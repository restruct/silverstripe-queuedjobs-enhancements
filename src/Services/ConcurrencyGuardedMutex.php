<?php

namespace Restruct\QueuedJobsEnhancements\Services;

use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;

/**
 * grabMutex() override implementing the concurrency guard (claim-then-verify).
 *
 * Lives in a trait so the guard can be adopted by ANY QueuedJobService subclass:
 * PHP's single inheritance means two service subclasses can't be "merged", so a project
 * that already runs its own subclass simply adds `use ConcurrencyGuardedMutex;` to it
 * (or calls ConcurrencyGuard directly from its own grabMutex()). Projects without their
 * own subclass swap in EnhancedQueuedJobService instead. All tunables/config live on
 * ConcurrencyGuard — a real class — because config declared on a trait does not merge.
 *
 * Why grabMutex() and not the runner engines: every job start — new, Waiting-resume,
 * stall-restart, and BOTH engines (QueueRunner in-process; DoormanRunner via its child
 * task) — funnels through QueuedJobService::runJob() -> grabMutex(). One override covers
 * everything; an engine-level gate would need implementing per engine and would still
 * race, because selection (getNextPendingJob) is not a claim.
 *
 * Race-safety: parent::grabMutex() COMMITS this descriptor's Worker-token claim before we
 * verify for active siblings. For two contenders to both proceed, each would have to
 * verify before the other's claim committed — impossible, since each claims before it
 * verifies (a cyclic ordering). Worst case both see each other and both back off:
 * fail-closed, resolved on the next runner tick.
 */
trait ConcurrencyGuardedMutex
{
    /**
     * @param QueuedJobDescriptor $jobDescriptor
     * @return bool
     */
    protected function grabMutex(QueuedJobDescriptor $jobDescriptor)
    {
        # 1) CLAIM our own descriptor first (parent: SELECT..FOR UPDATE + Worker token, committed)
        if (!parent::grabMutex($jobDescriptor)) {
            return false;
        }

        # 2) VERIFY: only now check for active siblings — with our claim visible to them
        $guard = ConcurrencyGuard::singleton();
        if (!$guard->isGuarded($jobDescriptor) || !$guard->hasActiveSibling($jobDescriptor)) {
            return true;
        }

        # 3) BACK OFF: another descriptor of this job class is active (Init/Running/Waiting,
        # or holding a committed Worker claim). Release our claim and push StartAfter forward.
        # Nothing runs and nothing is lost — no setup()/afterComplete() fires, the descriptor
        # simply starts once the active run has finished.
        # (defer() releases the Worker claim itself, on a FRESH read — parent::grabMutex claimed
        # via raw SQL, so this in-memory record never saw the token and can't release it.)
        $guard->defer($jobDescriptor);

        # runJob() already treats false as "another process claimed the lock" and exits cleanly
        return false;
    }
}
