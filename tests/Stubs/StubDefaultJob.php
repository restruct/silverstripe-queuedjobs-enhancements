<?php

namespace Restruct\QueuedJobsEnhancements\Tests\Stubs;

use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJob;

/**
 * Test stub: a self-declaring default job.
 */
class StubDefaultJob extends AbstractQueuedJob implements QueuedJob
{
    private static bool $is_default_job = true;
    private static string $default_schedule = '+10 minutes';

    public function getTitle(): string
    {
        return 'Stub default job';
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
