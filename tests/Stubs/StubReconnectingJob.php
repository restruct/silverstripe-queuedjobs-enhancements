<?php

namespace Restruct\QueuedJobsEnhancements\Tests\Stubs;

use Restruct\QueuedJobsEnhancements\Jobs\ReconnectsAfterDbGoneAway;
use SilverStripe\Dev\TestOnly;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJob;

/**
 * Test stub: a job that uses the ReconnectsAfterDbGoneAway trait.
 */
class StubReconnectingJob extends AbstractQueuedJob implements QueuedJob, TestOnly
{
    use ReconnectsAfterDbGoneAway;

    public function getTitle(): string
    {
        return 'Stub reconnecting job';
    }

    public function getJobType(): string
    {
        return QueuedJob::QUEUED;
    }

    public function process(): void
    {
        $this->isComplete = true;
    }

    /**
     * Public wrapper, so the test can call the trait's protected method without reflection
     */
    public function callReconnectAfterDbGoneAway(\Throwable $e): bool
    {
        return $this->reconnectAfterDbGoneAway($e);
    }
}
