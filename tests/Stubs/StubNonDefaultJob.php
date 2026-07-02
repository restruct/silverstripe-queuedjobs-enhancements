<?php

namespace Restruct\QueuedJobsEnhancements\Tests\Stubs;

use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJob;

/**
 * Test stub: a normal job (NOT self-declaring).
 */
class StubNonDefaultJob extends AbstractQueuedJob implements QueuedJob
{
    public function getTitle(): string
    {
        return 'Stub non-default job';
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
