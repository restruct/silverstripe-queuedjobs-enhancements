<?php

namespace Restruct\QueuedJobsEnhancements\Tests\Stubs;

use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJob;

/**
 * Test stub: a one-step IMMEDIATE job that records what it finds in StubResettableCache (as
 * "<label>:saw=<value>", "-" for empty) and then leaves its own label there. Two of these run by
 * one runner show whether the first job's value reached the second.
 */
class StubResettableCacheJob extends AbstractQueuedJob implements QueuedJob
{
    /**
     * @param string $label part of the signature, so two stubs with different labels are not
     *                      deduplicated by queueJob()
     */
    public function __construct(string $label = '')
    {
        $this->label = $label;
    }

    public function getTitle(): string
    {
        return 'Stub resettable cache job ' . $this->label;
    }

    public function getJobType(): string
    {
        return QueuedJob::IMMEDIATE;
    }

    public function process(): void
    {
        StubWorkerHooks::record($this->label . ':saw=' . (StubResettableCache::$value ?? '-'));
        StubResettableCache::$value = $this->label;
        $this->isComplete = true;
    }
}
