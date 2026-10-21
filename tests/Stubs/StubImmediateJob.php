<?php

namespace Restruct\QueuedJobsEnhancements\Tests\Stubs;

use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJob;

/**
 * Test stub: a one-step job on the IMMEDIATE queue. Up to 1.6.0 it was also run in a child process
 * by ImmediateSpawnRunsOnlyItsJobTest (removed in 1.7.0 with the per-job spawn), which is why it
 * does not depend on anything set up in the test process; keep it that way.
 */
class StubImmediateJob extends AbstractQueuedJob implements QueuedJob
{
    /**
     * @param string $label distinguishes otherwise identical jobs (it is part of the signature, so
     *                      two stubs with different labels are not deduplicated by queueJob())
     */
    public function __construct(string $label = '')
    {
        $this->label = $label;
    }

    public function getTitle(): string
    {
        return 'Stub immediate job ' . $this->label;
    }

    public function getJobType(): string
    {
        return QueuedJob::IMMEDIATE;
    }

    public function process(): void
    {
        StubWorkerHooks::record($this->label);
        $this->isComplete = true;
    }
}
