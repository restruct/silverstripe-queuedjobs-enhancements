<?php

namespace Restruct\QueuedJobsEnhancements\Extensions;

use Restruct\QueuedJobsEnhancements\Services\DrainSpawner;
use SilverStripe\Core\Extension;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Symbiote\QueuedJobs\Services\QueuedJob;

/**
 * Starts a background drain of the immediate queue (DrainSpawner) when a NEW IMMEDIATE job
 * descriptor has been written. Applied to QueuedJobDescriptor in _config/config.yml.
 *
 * Hooking the descriptor rather than QueuedJobService::queueJob() covers every way an immediate job
 * is queued (queueJob(), ImmediateJobRunner::queueAndRun(), ScheduledMethodCall, a descriptor
 * written by hand) without replacing or subclassing upstream's service.
 *
 * Only new rows: on an insert, DataObject::write() marks ID as changed and still has that change
 * when onAfterWrite() runs (the change list is cleared after the hook). Every later write of the
 * same descriptor (status, steps, messages while it runs) has no ID change and spawns nothing. A
 * queueJob() that finds an existing job with the same signature writes nothing, so it spawns
 * nothing either. A future StartAfter is not checked: the runner's getNextPendingJob() skips a job
 * that is not due, so such a drain finds nothing and exits after its linger.
 *
 * @property QueuedJobDescriptor $owner
 */
class QueuedJobDescriptorDrainExtension extends Extension
{
    /**
     * Public: Silverstripe 5 calls extension hooks from outside the class.
     */
    public function onAfterWrite(): void
    {
        $descriptor = $this->getOwner();

        if (!$descriptor->isChanged('ID')) {
            return;
        }
        if ((string) $descriptor->JobType !== (string) QueuedJob::IMMEDIATE) {
            return;
        }

        DrainSpawner::singleton()->requestDrain();
    }
}
