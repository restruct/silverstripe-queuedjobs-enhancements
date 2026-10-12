<?php

namespace Restruct\QueuedJobsEnhancements\Tests\Stubs;

use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJob;

/**
 * Test stub: a three-step job on the queue given to the constructor, calling
 * StubWorkerHooks::$onStep at each step so a test can act in the middle of a job.
 */
class StubMultiStepJob extends AbstractQueuedJob implements QueuedJob
{
    public function __construct(string $label = '', string $queue = QueuedJob::IMMEDIATE)
    {
        $this->label = $label;
        $this->queue = $queue;
    }

    public function getTitle(): string
    {
        return 'Stub multi-step job ' . $this->label;
    }

    public function getJobType(): string
    {
        return (string) $this->queue;
    }

    public function setup(): void
    {
        parent::setup();
        $this->totalSteps = 3;
    }

    public function process(): void
    {
        $this->currentStep++;
        StubWorkerHooks::record($this->label . '#' . $this->currentStep);
        if (StubWorkerHooks::$onStep) {
            (StubWorkerHooks::$onStep)($this->label, $this->currentStep);
        }
        if ($this->currentStep >= 3) {
            $this->isComplete = true;
        }
    }
}
