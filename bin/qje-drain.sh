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
#   QJE_DRAIN_LOCK_TOOL  force the lock implementation: flock, perl or none (default: detect)
#
# Exit codes: 0, or 64 on a usage error. Compatible with bash 3.2+ (macOS) and 5.

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
INNER='rm -f "$1"; shift; exec "$@"'

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

while :; do
    run_locked "$@"
    rc=$?
    if [ "$rc" -eq "$BUSY" ]; then
        # Another drain is running; it owns the marker check now
        exit 0
    fi
    if [ -e "$MARKER" ]; then
        # A job was queued after this runner started, possibly after its last look
        continue
    fi
    if [ "$rc" -eq "$MORE_WORK" ]; then
        # The runner stopped early (a limit, a signal, a parked job) with work possibly left
        continue
    fi
    exit 0
done
