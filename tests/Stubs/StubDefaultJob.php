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

    public function getJobType(): int
    {
        return QueuedJob::QUEUED;
    }

    public function process(): void
    {
        $this->isComplete = true;
    }
}
