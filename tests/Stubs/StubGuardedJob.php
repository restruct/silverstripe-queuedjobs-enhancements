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

    public function getJobType(): int
    {
        return QueuedJob::QUEUED;
    }

    public function process(): void
    {
        $this->isComplete = true;
    }
}
