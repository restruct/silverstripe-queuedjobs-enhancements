<?php

namespace Restruct\QueuedJobsEnhancements\Tests\Stubs;

use Restruct\QueuedJobsEnhancements\Jobs\BoundedJobMessages;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJob;

/**
 * Test stub: BoundedJobMessages with no settings of its own, for the trait defaults.
 */
class StubBoundedDefaultsJob extends AbstractQueuedJob implements QueuedJob
{
    use BoundedJobMessages;

    public function getTitle(): string
    {
        return 'Stub bounded-messages job with defaults';
    }

    public function getJobType(): string
    {
        return QueuedJob::QUEUED;
    }

    public function process(): void
    {
        $this->isComplete = true;
    }
}
