<?php

namespace Restruct\QueuedJobsEnhancements\Tests\Stubs;

use Restruct\QueuedJobsEnhancements\Jobs\BoundedJobMessages;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJob;

/**
 * Test stub: a QUEUED job using BoundedJobMessages with a small head and tail (declared as private
 * statics on the job class, as a project would), logging $perStep messages in each of $steps steps.
 */
class StubBoundedMessagesJob extends AbstractQueuedJob implements QueuedJob
{
    use BoundedJobMessages;

    private static int $keep_first_messages = 2;

    private static int $keep_last_messages = 3;

    public function __construct(int $perStep = 0, int $steps = 1)
    {
        $this->perStep = $perStep;
        $this->steps = $steps;
    }

    public function getTitle(): string
    {
        return 'Stub bounded-messages job';
    }

    public function getJobType(): string
    {
        return QueuedJob::QUEUED;
    }

    public function setup(): void
    {
        parent::setup();
        $this->totalSteps = (int) $this->steps;
    }

    public function process(): void
    {
        $this->currentStep++;
        for ($i = 1; $i <= (int) $this->perStep; $i++) {
            $this->addMessage(sprintf('step %d message %d', $this->currentStep, $i));
        }
        if ($this->currentStep >= (int) $this->steps) {
            $this->isComplete = true;
        }
    }
}
