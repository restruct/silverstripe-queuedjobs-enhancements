<?php

namespace Restruct\QueuedJobsEnhancements\Tests\Stubs;

use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJob;

/**
 * Test stub: a job that opts in to the concurrency guard.
 */
class StubGuardedJob extends AbstractQueuedJob implements QueuedJob
{
    # the ConcurrencyGuard opt-in under test
    private static bool $prevent_concurrent_runs = true;

    public function getTitle(): string
    {
        return 'Stub guarded job';
    }

    // Was `: int`. The QueuedJob constants are strings ('1'/'2'/'3') and PHP coerced QUEUED to
    // int 2 here; on SS6 QueuedJobDescriptor.JobType is a validated Varchar, so queueing this stub
    // threw `ValidationException: Must be a string - fieldName: JobType` before any assertion ran.
    // The same defect as ScheduledMethodCall::getJobType(), reproduced in the fixture.
//    public function getJobType(): int
//    {
//        return QueuedJob::QUEUED;
//    }

    public function getJobType(): string
    {
        return QueuedJob::QUEUED;
    }

    public function process(): void
    {
        $this->isComplete = true;
    }
}
