#!/usr/bin/env bash
# Tests for bin/qje-drain.sh, without PHP or a database: the wrapper runs a fake runner that logs
# its runs and exits with scripted codes.
#
#   bash tests/shell/qje-drain-test.sh            (from anywhere; exit 0 = all passed)
#   bash tests/shell/qje-drain-test.sh --mutants  (must-fail controls, see the end of this file)
#
# Checks, for every lock implementation available here (flock, perl, and none for the unlocked
# fallback): one run and exit 0 when nothing is left; the marker is removed inside the lock; a
# marker touched during a run makes it run again; exit 75 makes it run again; another exit code
# does not; a held lock makes a second wrapper exit at once without touching the marker, and the
# holder then runs again for it; runner arguments with spaces arrive intact. And the bounds, each
# run with a time limit so that an endless wrapper fails instead of hanging the suite: a marker
# that cannot be removed stops it before the runner starts; a lock file that cannot be opened
# stops it; a runner that fails while jobs keep arriving runs twice, not for ever; and
# QJE_DRAIN_MAX_PASSES caps the runs of a runner that keeps exiting 75.
# QJE_DRAIN_SCRIPT points the tests at another copy of the wrapper (used by --mutants).
# Compatible with bash 3.2+ (macOS) and 5.

set -u
HERE=$(cd "$(dirname "${BASH_SOURCE[0]:-$0}")" && pwd)
DRAIN=${QJE_DRAIN_SCRIPT:-"$HERE/../../bin/qje-drain.sh"}
TMP=$(mktemp -d "${TMPDIR:-/tmp}/qje-drain-test.XXXXXX")
FAILED=0
BG_PIDS=""

cleanup() {
    for pid in $BG_PIDS; do kill "$pid" 2>/dev/null; done
    # the read-only marker folder of the bounds checks would otherwise survive the rm
    chmod -R u+w "$TMP" 2>/dev/null
    rm -rf "$TMP"
}
trap cleanup EXIT

pass() { echo "ok   - $*"; }
fail() { echo "FAIL - $*"; FAILED=1; }

# The fake runner. Per run: logs "start" (and whether the marker still existed, which it must not:
# the wrapper removes it before the runner starts), then does what the next line of `script`
# says, then logs "end rc=N" and exits N. Script lines: "<rc> [sleep-seconds] [touch]"; "touch"
# touches the marker during the run, like a job queued after the runner's start. Without a script
# line it exits 0 at once.
cat > "$TMP/runner.sh" <<'RUNNER'
#!/usr/bin/env bash
T=$1; shift
if [ -e "$T/marker" ]; then m=marker-present; else m=marker-absent; fi
echo "start $m args=$*" >> "$T/runs.log"
line=""
if [ -s "$T/script" ]; then
    line=$(head -n 1 "$T/script")
    tail -n +2 "$T/script" > "$T/script.tmp"; mv "$T/script.tmp" "$T/script"
fi
set -- $line
rc=${1:-0}; secs=${2:-0}; act=${3:-}
[ "$act" = touch ] && touch "$T/marker"
sleep "$secs"
echo "end rc=$rc" >> "$T/runs.log"
exit "$rc"
RUNNER
chmod +x "$TMP/runner.sh"

reset_state() {
    rm -f "$TMP/runs.log" "$TMP/script" "$TMP/marker" "$TMP/lock"
    : > "$TMP/runs.log"
}

starts() { grep -c '^start ' "$TMP/runs.log"; }

# drain [extra runner args...]: run the wrapper in the foreground with the current lock tool
drain() {
    QJE_DRAIN_LOCK_TOOL=$TOOL bash "$DRAIN" "$TMP/marker" "$TMP/lock" "$TMP/runner.sh" "$TMP" "$@" 2> "$TMP/stderr"
}

# drain_bounded SECS MARKER LOCK [extra runner args...]: run the wrapper in the background with
# the current lock tool and give it SECS seconds. Sets rc (the wrapper's exit code) and timed_out
# (yes when it had to be killed: an unbounded loop). No timeout(1): macOS does not ship it.
drain_bounded() {
    secs=$1; marker=$2; lock=$3; shift 3
    QJE_DRAIN_LOCK_TOOL=$TOOL bash "$DRAIN" "$marker" "$lock" "$TMP/runner.sh" "$TMP" "$@" 2> "$TMP/stderr" &
    bpid=$!; BG_PIDS="$BG_PIDS $bpid"
    i=0; while kill -0 "$bpid" 2>/dev/null && [ $i -lt $((secs * 10)) ]; do sleep 0.1; i=$((i + 1)); done
    if kill -0 "$bpid" 2>/dev/null; then
        kill "$bpid" 2>/dev/null; wait "$bpid" 2>/dev/null
        timed_out=yes; rc=-1
    else
        wait "$bpid"; rc=$?
        timed_out=no
    fi
}

run_suite() {
    TOOL=$1

    # 1. Nothing queued after the start: one run, exit 0, and the marker removed before the run
    reset_state
    touch "$TMP/marker"
    drain; rc=$?
    if [ "$rc" -eq 0 ] && [ "$(starts)" -eq 1 ] && grep -q '^start marker-absent' "$TMP/runs.log" && [ ! -e "$TMP/marker" ]; then
        pass "[$TOOL] one run, marker removed before it, exit 0"
    else
        fail "[$TOOL] one run, marker removed before it, exit 0 (rc=$rc, runs=$(starts))"; cat "$TMP/runs.log"
    fi

    # 2. A job queued during the run (marker touched): run again
    reset_state
    printf '0 0 touch\n' > "$TMP/script"
    drain; rc=$?
    if [ "$rc" -eq 0 ] && [ "$(starts)" -eq 2 ] && [ ! -e "$TMP/marker" ]; then
        pass "[$TOOL] marker touched during the run: second run"
    else
        fail "[$TOOL] marker touched during the run: second run (rc=$rc, runs=$(starts))"; cat "$TMP/runs.log"
    fi

    # 3. Runner exits 75 (stopped early with work left): run again, until it exits 0
    reset_state
    printf '75\n75\n0\n' > "$TMP/script"
    drain; rc=$?
    if [ "$rc" -eq 0 ] && [ "$(starts)" -eq 3 ]; then
        pass "[$TOOL] exit 75 runs again (3 runs for 75, 75, 0)"
    else
        fail "[$TOOL] exit 75 runs again (rc=$rc, runs=$(starts))"; cat "$TMP/runs.log"
    fi

    # 4. Any other exit code without a marker: no second run (a failing runner must not loop)
    reset_state
    printf '1\n0\n' > "$TMP/script"
    drain; rc=$?
    if [ "$rc" -eq 0 ] && [ "$(starts)" -eq 1 ]; then
        pass "[$TOOL] exit 1 does not run again"
    else
        fail "[$TOOL] exit 1 does not run again (rc=$rc, runs=$(starts))"; cat "$TMP/runs.log"
    fi

    # 5. Runner arguments arrive intact, spaces included
    reset_state
    drain "two words" --opt=x
    if grep -q '^start marker-absent args=two words --opt=x$' "$TMP/runs.log"; then
        pass "[$TOOL] runner arguments intact"
    else
        fail "[$TOOL] runner arguments intact"; cat "$TMP/runs.log"
    fi

    # 6. Lock held: a second wrapper exits at once, leaves the marker alone, and the holder runs
    #    again for it. Not for "none": without a lock both run.
    if [ "$TOOL" != none ]; then
        reset_state
        printf '0 2\n0\n' > "$TMP/script"
        QJE_DRAIN_LOCK_TOOL=$TOOL bash "$DRAIN" "$TMP/marker" "$TMP/lock" "$TMP/runner.sh" "$TMP" 2> /dev/null &
        holder=$!; BG_PIDS="$BG_PIDS $holder"
        # wait until the holder's runner has started (and so holds the lock)
        i=0; while [ "$(starts)" -lt 1 ] && [ $i -lt 50 ]; do sleep 0.1; i=$((i + 1)); done
        touch "$TMP/marker"
        t0=$(date +%s)
        drain; rc=$?
        t1=$(date +%s)
        marker_kept=no; [ -e "$TMP/marker" ] && marker_kept=yes
        wait "$holder"; holder_rc=$?
        if [ "$rc" -eq 0 ] && [ $((t1 - t0)) -le 1 ] && [ "$marker_kept" = yes ] \
            && [ "$holder_rc" -eq 0 ] && [ "$(starts)" -eq 2 ] && [ ! -e "$TMP/marker" ]; then
            pass "[$TOOL] lock held: second wrapper exits at once, holder runs again for the marker"
        else
            fail "[$TOOL] lock held (rc=$rc, took=$((t1 - t0))s, marker_kept=$marker_kept, holder_rc=$holder_rc, runs=$(starts))"; cat "$TMP/runs.log"
        fi
    fi

    # 7. Only the unlocked fallback warns
    reset_state
    drain
    if [ "$TOOL" = none ]; then
        grep -q 'without a lock' "$TMP/stderr" && pass "[none] warns about the missing lock" || fail "[none] warns about the missing lock"
    else
        [ ! -s "$TMP/stderr" ] && pass "[$TOOL] no warning" || { fail "[$TOOL] no warning"; cat "$TMP/stderr"; }
    fi

    # 8. The marker cannot be removed (its folder is not writable): the wrapper stops at once,
    #    with a message and exit 70, and never starts the runner. Before 1.7.1 the rm failure was
    #    ignored and the marker, still there after every run, started a runner for ever. Skipped
    #    as root, where a read-only folder does not stop the rm.
    if [ "$(id -u)" -ne 0 ]; then
        reset_state
        mkdir -p "$TMP/ro"; chmod u+w "$TMP/ro"; touch "$TMP/ro/marker"; chmod a-w "$TMP/ro"
        drain_bounded 5 "$TMP/ro/marker" "$TMP/lock"
        chmod u+w "$TMP/ro"
        if [ "$timed_out" = no ] && [ "$rc" -eq 70 ] && [ "$(starts)" -eq 0 ] && grep -q 'cannot remove marker' "$TMP/stderr"; then
            pass "[$TOOL] undeletable marker: stops at once, runner not started, exit 70"
        else
            fail "[$TOOL] undeletable marker: bounded (timed_out=$timed_out, rc=$rc, runs=$(starts))"; tail -n 5 "$TMP/stderr"
        fi
        rm -rf "$TMP/ro"
    else
        echo "# running as root: the undeletable-marker check is skipped"
    fi

    # 9. The lock file cannot be opened (its folder does not exist), with a marker pending: the
    #    lock tool fails, so the runner never starts and the marker stays. The wrapper tries once
    #    more and stops with exit 70, instead of hot-spinning on the marker. Not for "none", which
    #    takes no lock and simply runs.
    if [ "$TOOL" != none ]; then
        reset_state
        touch "$TMP/marker"
        drain_bounded 5 "$TMP/marker" "$TMP/no-such-dir/lock"
        if [ "$timed_out" = no ] && [ "$rc" -eq 70 ] && [ "$(starts)" -eq 0 ] && grep -q 'failed twice' "$TMP/stderr"; then
            pass "[$TOOL] unopenable lock: two attempts, then exit 70"
        else
            fail "[$TOOL] unopenable lock: bounded (timed_out=$timed_out, rc=$rc, runs=$(starts))"; tail -n 5 "$TMP/stderr"
        fi
    fi

    # 10. A runner that fails while jobs keep arriving (the marker touched during every run): one
    #     rerun for the marker, then the second failure stops it with exit 70
    reset_state
    i=0; while [ $i -lt 20 ]; do echo '1 0 touch'; i=$((i + 1)); done > "$TMP/script"
    drain_bounded 10 "$TMP/marker" "$TMP/lock"
    if [ "$timed_out" = no ] && [ "$rc" -eq 70 ] && [ "$(starts)" -eq 2 ]; then
        pass "[$TOOL] failing runner with jobs arriving: two runs, then exit 70"
    else
        fail "[$TOOL] failing runner with jobs arriving: two runs (timed_out=$timed_out, rc=$rc, runs=$(starts))"; cat "$TMP/stderr"
    fi

    # 11. QJE_DRAIN_MAX_PASSES caps the runs of a runner that keeps exiting 75
    reset_state
    i=0; while [ $i -lt 20 ]; do echo '75'; i=$((i + 1)); done > "$TMP/script"
    QJE_DRAIN_MAX_PASSES=4 drain_bounded 10 "$TMP/marker" "$TMP/lock"
    if [ "$timed_out" = no ] && [ "$rc" -eq 70 ] && [ "$(starts)" -eq 4 ] && grep -q 'stopping after 4 runs' "$TMP/stderr"; then
        pass "[$TOOL] QJE_DRAIN_MAX_PASSES=4: four runs, then exit 70"
    else
        fail "[$TOOL] QJE_DRAIN_MAX_PASSES=4: four runs (timed_out=$timed_out, rc=$rc, runs=$(starts))"; cat "$TMP/stderr"
    fi
}

# Must-fail controls: each mutant breaks one rule of the wrapper; the suite must fail against
# every one of them, which shows the tests above can see that rule break.
if [ "${1:-}" = --mutants ]; then
    SRC="$HERE/../../bin/qje-drain.sh"
    survived=0
    for mutant in no-marker-loop no-75-loop no-busy-exit rm-outside-lock rm-unchecked no-failure-limit no-pass-cap; do
        M="$TMP/drain-$mutant.sh"
        case $mutant in
            # the marker check after the runner never fires
            no-marker-loop) sed 's/if \[ -e "\$MARKER" \]; then/if false; then/' "$SRC" > "$M" ;;
            # exit 75 is treated like any other exit code
            no-75-loop) sed 's/if \[ "\$rc" -eq "\$MORE_WORK" \]; then/if false; then/' "$SRC" > "$M" ;;
            # a held lock is treated like a runner exit, so the marker check runs and the second
            # wrapper loops on the holder's marker (it waits, instead of exiting at once)
            no-busy-exit) sed 's/if \[ "\$rc" -eq "\$BUSY" \]; then/if false; then/' "$SRC" > "$M" ;;
            # the marker is removed before the lock is taken: a wrapper that then finds the lock
            # held has eaten the marker, and the holder never runs again for it
            rm-outside-lock) sed 's/^    run_locked "\$@"$/    rm -f "$MARKER"; run_locked "$@"/' "$SRC" > "$M" ;;
            # the marker's rm failure is ignored again (the 1.7.0 inner command)
            rm-unchecked) sed 's/^INNER=.*/INNER='"'"'rm -f "$1"; shift; exec "$@"'"'"'/' "$SRC" > "$M" ;;
            # a failing runner never stops the wrapper
            no-failure-limit) sed 's/if \[ "\$failures" -ge 2 \]; then/if false; then/' "$SRC" > "$M" ;;
            # no cap on the number of runs
            no-pass-cap) sed 's/if \[ "\$passes" -ge "\$MAX_PASSES" \]; then/if false; then/' "$SRC" > "$M" ;;
        esac
        if cmp -s "$SRC" "$M"; then
            echo "FAIL - mutant $mutant: the sed did not change the wrapper (the control is broken)"; survived=1; continue
        fi
        if QJE_DRAIN_SCRIPT="$M" bash "$0" > "$TMP/mutant.log" 2>&1; then
            echo "FAIL - mutant $mutant survived: the suite passed against a broken wrapper"; survived=1
        else
            echo "ok   - mutant $mutant is caught ($(grep -c '^FAIL' "$TMP/mutant.log") failing check(s))"
        fi
    done
    exit $survived
fi

TOOLS="none"
command -v perl > /dev/null 2>&1 && TOOLS="perl $TOOLS"
if command -v flock > /dev/null 2>&1 && flock -n -E 200 "$TMP/probe.lock" true > /dev/null 2>&1; then
    TOOLS="flock $TOOLS"
else
    echo "# flock(1) with -E not available here: the flock variant is not tested"
fi
for t in $TOOLS; do run_suite "$t"; done

[ "$FAILED" -eq 0 ] && echo "all passed" || echo "FAILED"
exit $FAILED
