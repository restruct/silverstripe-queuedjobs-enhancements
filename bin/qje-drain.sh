#!/usr/bin/env bash
# qje-drain.sh: run the immediate queue until it is empty, one runner at a time.
#
# Started in the background by DrainSpawner every time an IMMEDIATE job is queued, after the job's
# descriptor row was written and the marker file was touched:
#
#   nohup bash qje-drain.sh MARKER LOCK RUNNER [ARGS...] > /dev/null 2>&1 &
#
# RUNNER [ARGS...] is the module's one-shot worker, e.g. (Silverstripe 6)
#   php vendor/silverstripe/framework/bin/sake queuedjobs:work --queue=immediate --stop-when-empty
#
# What it does, in a loop:
#   1. Take LOCK without waiting. If another drain holds it, exit 0: that drain's runner will see
#      the new job, or its wrapper will see the marker (step 3) and go again.
#   2. Holding the lock: remove MARKER, then run RUNNER.
#   3. After RUNNER exits (the lock is released): if MARKER exists again, a job was queued after
#      step 2, possibly after the runner's last look at the queue, so go again. Also go again when
#      RUNNER exited 75 (QueueWorker::EXIT_MORE_WORK: it stopped early with work left). Otherwise
#      exit 0.
#
# Bounds, so that a wrapper can never start PHP runners forever:
#   - The marker cannot be removed (folder not writable, read-only filesystem): step 2 fails
#     before RUNNER starts, and the wrapper stops at once. Without this the marker would still
#     exist after every run and step 3 would go again for ever.
#   - RUNNER (or the lock tool, e.g. a lock file that cannot be opened) exits with anything other
#     than 0 or 75: a failure. With the marker present the wrapper goes again once (a job may have
#     been queued during the failed run); a second failure stops it. Without the marker it exits
#     0, as before.
#   - At most QJE_DRAIN_MAX_PASSES runs per wrapper; past that it stops with a warning and leaves
#     the marker for the next dispatch (or the cron) to pick up.
#   - Each further pass waits PASS_SLEEP seconds first, so a misbehaving loop cannot spin.
#
# Why the order in DrainSpawner matters: the row is written BEFORE the marker is touched. A runner
# that started after the marker was removed (step 2) therefore either sees the row, or the marker
# was touched after the removal and step 3 starts another runner. No job is left behind without a
# runner that will look for it.
#
# Locking: util-linux flock(1) when it is installed and supports -E (Linux). Otherwise perl's
# flock() (macOS has perl but no flock(1)). With neither, the runner is started without a lock and
# a warning is written to stderr: correctness does not depend on the lock (upstream's per-job mutex
# stops a job from running twice), only the number of PHP processes during a burst does.
#
# Environment (all optional):
#   QJE_DRAIN_LOCK_TOOL   force the lock implementation: flock, perl or none (default: detect)
#   QJE_DRAIN_MAX_PASSES  most runner starts per wrapper, a positive integer (default: 50)
#
# Exit codes: 0 when done (nothing left, another drain holds the lock, or a single runner failure
# without a new job); 64 on a usage error; 70 when it stopped early (the marker cannot be removed,
# the runner failed twice, or QJE_DRAIN_MAX_PASSES was reached), with the reason on stderr.
# Compatible with bash 3.2+ (macOS) and 5.

set -u

if [ $# -lt 3 ]; then
    echo "usage: qje-drain.sh MARKER LOCK RUNNER [ARGS...]" >&2
    exit 64
fi
MARKER=$1
LOCK=$2
shift 2

# Exit code the lock helpers return when the lock is held. Chosen so that it cannot be confused
# with the runner's own exit codes (0, 1, 2, 75, 128+signal).
BUSY=200
# QueueWorker::EXIT_MORE_WORK
MORE_WORK=75
# Exit code of the inner command when the marker cannot be removed. Distinct from BUSY and from
# the runner's own exit codes for the same reason as BUSY.
MARKER_STUCK=201
# The wrapper's own exit code when it stops early (EX_SOFTWARE, sysexits.h)
GAVE_UP=70
# Seconds to wait before each pass after the first (fractional: GNU, BSD and busybox sleep)
PASS_SLEEP=0.2

# Cap on runner starts. A value that is not a positive integer falls back to the default, rather
# than switching the cap off.
MAX_PASSES=${QJE_DRAIN_MAX_PASSES:-50}
case "$MAX_PASSES" in
    '' | *[!0-9]* | 0) MAX_PASSES=50 ;;
esac

# Pick the lock implementation once. A flock(1) without -E (busybox) would report a held lock as
# exit 1, which would look like a runner that failed, so it is probed: 0 (got it) or BUSY (held)
# both prove -E works.
LOCK_TOOL=${QJE_DRAIN_LOCK_TOOL:-}
if [ -z "$LOCK_TOOL" ]; then
    LOCK_TOOL=none
    if command -v flock > /dev/null 2>&1; then
        flock -n -E "$BUSY" "$LOCK" true > /dev/null 2>&1
        probe=$?
        if [ "$probe" -eq 0 ] || [ "$probe" -eq "$BUSY" ]; then
            LOCK_TOOL=flock
        fi
    fi
    if [ "$LOCK_TOOL" = none ] && command -v perl > /dev/null 2>&1; then
        LOCK_TOOL=perl
    fi
fi
if [ "$LOCK_TOOL" = none ]; then
    echo "qje-drain.sh: warning: neither flock(1) with -E nor perl found; running the runner without a lock" >&2
fi

# The command run while holding the lock: remove the marker, then become the runner. sh -c with
# the marker as $1 and the runner as the rest, so no argument is ever re-parsed by a shell.
# A marker that cannot be removed exits MARKER_STUCK before the runner starts: running it anyway
# would leave the marker in place, and the loop below would see it after every run.
# old: the rm's exit code was ignored, which made an undeletable marker an endless loop
#INNER='rm -f "$1"; shift; exec "$@"'
INNER='rm -f "$1" || exit '"$MARKER_STUCK"'; shift; exec "$@"'

# run_locked RUNNER [ARGS...]: steps 1 and 2. Returns BUSY when the lock is held, else the runner's
# exit code.
run_locked() {
    case "$LOCK_TOOL" in
        flock)
            flock -n -E "$BUSY" "$LOCK" /bin/sh -c "$INNER" qje-drain "$MARKER" "$@"
            ;;
        perl)
            # Same contract as flock -n -E: the lock is held by this perl process while the
            # command runs as its child, and released when perl exits. A child killed by a signal
            # is reported as 128+signal, as a shell does.
            perl -MFcntl=:DEFAULT,:flock -e '
                my ($file, $busy, @cmd) = @ARGV;
                sysopen(my $fh, $file, O_RDWR | O_CREAT, 0666) or do { print STDERR "qje-drain.sh: cannot open lock $file: $!\n"; exit 1 };
                flock($fh, LOCK_EX | LOCK_NB) or exit $busy;
                my $pid = fork();
                defined $pid or do { print STDERR "qje-drain.sh: fork failed: $!\n"; exit 1 };
                if ($pid == 0) { exec { $cmd[0] } @cmd; exit 127 }
                waitpid($pid, 0);
                exit(($? & 127) ? 128 + ($? & 127) : $? >> 8);
            ' "$LOCK" "$BUSY" /bin/sh -c "$INNER" qje-drain "$MARKER" "$@"
            ;;
        *)
            /bin/sh -c "$INNER" qje-drain "$MARKER" "$@"
            ;;
    esac
}

# old: the unbounded loop. An undeletable marker or a lock that could not be opened (the lock
# tool fails, the marker stays) made it start a runner, or hot-spin, for ever.
#while :; do
#    run_locked "$@"
#    rc=$?
#    if [ "$rc" -eq "$BUSY" ]; then
#        # Another drain is running; it owns the marker check now
#        exit 0
#    fi
#    if [ -e "$MARKER" ]; then
#        # A job was queued after this runner started, possibly after its last look
#        continue
#    fi
#    if [ "$rc" -eq "$MORE_WORK" ]; then
#        # The runner stopped early (a limit, a signal, a parked job) with work possibly left
#        continue
#    fi
#    exit 0
#done

passes=0
failures=0
while :; do
    if [ "$passes" -gt 0 ]; then
        # Not before the first run (a dispatch should start PHP at once), but before every rerun
        sleep "$PASS_SLEEP"
    fi
    passes=$((passes + 1))
    run_locked "$@"
    rc=$?
    if [ "$rc" -eq "$BUSY" ]; then
        # Another drain is running; it owns the marker check now
        exit 0
    fi
    if [ "$rc" -eq "$MARKER_STUCK" ]; then
        echo "qje-drain.sh: cannot remove marker $MARKER; stopping (the runner was not started)" >&2
        exit "$GAVE_UP"
    fi
    if [ "$rc" -ne 0 ] && [ "$rc" -ne "$MORE_WORK" ]; then
        # A failure: of the runner, or of the lock tool (a lock file that cannot be opened). Go
        # again at most once, and only for a job queued meanwhile (the marker check below).
        failures=$((failures + 1))
        if [ "$failures" -ge 2 ]; then
            echo "qje-drain.sh: the runner failed twice (last exit code $rc); stopping" >&2
            exit "$GAVE_UP"
        fi
    fi
    if [ -e "$MARKER" ]; then
        # A job was queued after this runner started, possibly after its last look
        :
    elif [ "$rc" -eq "$MORE_WORK" ]; then
        # The runner stopped early (a limit, a signal, a parked job) with work possibly left
        :
    else
        exit 0
    fi
    if [ "$passes" -ge "$MAX_PASSES" ]; then
        echo "qje-drain.sh: stopping after $MAX_PASSES runs with work possibly left; the next dispatch or the cron picks it up" >&2
        exit "$GAVE_UP"
    fi
done
