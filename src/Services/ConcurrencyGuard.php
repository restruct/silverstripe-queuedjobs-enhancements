<?php

namespace Restruct\QueuedJobsEnhancements\Services;

use Psr\Log\LoggerInterface;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\FieldType\DBDatetime;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Symbiote\QueuedJobs\Services\QueuedJob;

/**
 * Decision logic for the per-job-class concurrency guard.
 *
 * Job classes opt in by declaring:
 *
 *     private static bool $prevent_concurrent_runs = true;
 *
 * When such a job's descriptor is about to start while ANOTHER descriptor of the same
 * Implementation is still active, the new one is deferred (StartAfter pushed forward)
 * instead of run. This closes the cross-process duplicate-writes window: symbiote's own
 * grabMutex() is strictly per-DESCRIPTOR (Worker token), so two scheduled descriptors of
 * the same job class happily run concurrently in separate DB sessions.
 *
 * All tunables live here — on a real class — because config declared on a trait does not
 * merge in Silverstripe. Enforcement/orchestration lives in ConcurrencyGuardedMutex.
 */
class ConcurrencyGuard
{
    use Configurable;
    use Injectable;

    /**
     * Seconds to push a deferred descriptor's StartAfter into the future.
     * Deliberately short: deferral is a back-off, not a cancellation — the deferred run
     * executes as soon as the active one has finished and the runner ticks again.
     */
    private static $defer_seconds = 300;

    /**
     * Does this descriptor's job class opt in to the guard?
     */
    public function isGuarded(QueuedJobDescriptor $descriptor): bool
    {
        $impl = $descriptor->Implementation;
        if (!$impl || !class_exists($impl)) {
            return false;
        }
        # Config::inst() reads private statics off any manifest class — jobs need no Configurable trait
        return (bool)Config::inst()->get($impl, 'prevent_concurrent_runs');
    }

    /**
     * Is another descriptor (any but this one) of the same job class currently active?
     *
     * "Active" =
     *  - JobStatus Initialising/Running: a runner is executing it right now; or
     *  - JobStatus Waiting: memory/time-parked mid-run — half-done and about to resume in a
     *    fresh process. MUST count as active: a parked sibling is exactly the duplicate-writes
     *    window this guard exists for; or
     *  - a committed Worker claim (Worker IS NOT NULL) in any non-terminal status: a contender
     *    that just won grabMutex() but hasn't reached Initialising yet — the claim-then-verify
     *    window. Without this clause two same-second starters could both pass the check.
     *
     * Paused/Broken/Completed/Cancelled siblings do NOT block (a paused-broken job must not
     * deadlock the next scheduled run; stale Worker tokens on such rows are cleared by
     * checkJobHealth()/pause() upstream).
     */
    public function hasActiveSibling(QueuedJobDescriptor $descriptor): bool
    {
        # fresh ORM query on purpose — the whole point is seeing OTHER sessions' committed state
        return QueuedJobDescriptor::get()
            ->filter('Implementation', $descriptor->Implementation)
            ->exclude('ID', $descriptor->ID)
            ->filterAny([
                'JobStatus' => [
                    QueuedJob::STATUS_INIT,
                    QueuedJob::STATUS_RUN,
                    QueuedJob::STATUS_WAIT,
                ],
                'Worker:not' => null,
            ])
            ->exclude('JobStatus', [
                # terminal/inactive statuses never block, even with a stale Worker token left behind
                QueuedJob::STATUS_COMPLETE,
                QueuedJob::STATUS_PAUSED,
                QueuedJob::STATUS_CANCELLED,
                QueuedJob::STATUS_BROKEN,
            ])
            ->exists();
    }

    /**
     * Back a descriptor off: push StartAfter forward and persist. The descriptor keeps its
     * status (normally New) so the runner simply re-considers it later — no job lifecycle
     * methods fire (no setup(), no afterComplete(), no mails, no heartbeats).
     *
     * NB: for a Waiting (parked) descriptor the StartAfter push is cosmetic — the runner's
     * waiting-jobs branch ignores StartAfter, so it will be re-examined (and re-deferred)
     * every tick until the sibling finishes. Harmless busy-defer; converges automatically.
     */
    public function defer(QueuedJobDescriptor $descriptor): void
    {
        # Re-read before writing: grabMutex() claims the Worker token via RAW SQL, so the record
        # we were handed never saw it — releasing by setting an (already-null) in-memory field is
        # a null->null non-change that write() would silently skip, leaving the claim in the DB.
        # The fresh read hydrates the token, making the release below a real, persisted change.
        $descriptor = QueuedJobDescriptor::get()->byID($descriptor->ID) ?: $descriptor;

        $deferSeconds = (int)$this->config()->get('defer_seconds');
        $descriptor->Worker = null; # release the claim we just took
        $descriptor->StartAfter = date(
            'Y-m-d H:i:s',
            DBDatetime::now()->getTimestamp() + $deferSeconds
        );
        $descriptor->write();

        Injector::inst()->get(LoggerInterface::class)->info(sprintf(
            'ConcurrencyGuard: deferred %s (#%d) by %ds — another descriptor of this job class is active',
            $descriptor->Implementation,
            $descriptor->ID,
            $deferSeconds
        ));
    }
}
