<?php

namespace Restruct\QueuedJobsEnhancements\Tests\Jobs;

use Restruct\QueuedJobsEnhancements\Jobs\ClosureJob;
use SilverStripe\Dev\SapphireTest;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Symbiote\QueuedJobs\Services\QueuedJob;

# Note: AbstractQueuedJob does NOT use Injectable, so ::create() is unavailable.
# Use `new ClosureJob(...)` or `Injector::inst()->create()` instead.
class ClosureJobTest extends SapphireTest
{
    protected $usesDatabase = true;

    public function testDispatchQueuesJob(): void
    {
        $descriptorId = ClosureJob::dispatch(
            fn() => 'test result',
            description: 'Test closure job'
        );

        $this->assertGreaterThan(0, $descriptorId);

        $descriptor = QueuedJobDescriptor::get()->byID($descriptorId);
        $this->assertNotNull($descriptor);
        $this->assertEquals('Test closure job', $descriptor->JobTitle);
    }

    public function testProcessExecutesClosure(): void
    {
        # Use a closure that writes to a temp file to verify execution
        $tmpFile = tempnam(sys_get_temp_dir(), 'closure_test_');
        $expected = 'closure_executed_' . uniqid();

        $job = new ClosureJob(
            function () use ($tmpFile, $expected) {
                file_put_contents($tmpFile, $expected);
                return 'done';
            },
            'Test execution'
        );

        $job->setup();
        $job->process();

        $this->assertTrue($job->jobFinished());
        $this->assertEquals($expected, file_get_contents($tmpFile));

        unlink($tmpFile);
    }

    public function testGetTitleReturnsDescription(): void
    {
        $job = new ClosureJob(fn() => null, 'My custom title');
        $this->assertEquals('My custom title', $job->getTitle());
    }

    public function testGetTitleDefaultsToClosureJob(): void
    {
        $job = new ClosureJob(fn() => null);
        $this->assertEquals('Closure job', $job->getTitle());
    }

    public function testGetJobTypeReturnsQueued(): void
    {
        $job = new ClosureJob(fn() => null);
        $this->assertEquals(QueuedJob::QUEUED, $job->getJobType());
    }

    public function testClosureSurvivesSerialization(): void
    {
        # Simulate what QueuedJobService does: serialize jobData, unserialize, then process
        $value = 'test_' . uniqid();
        $job = new ClosureJob(fn() => $value, 'Serialization test');

        # Get the jobData (what gets serialized by QueuedJobService)
        $jobData = $job->getJobData();

        # Create a new instance and restore jobData (simulates job being loaded from DB)
        $restoredJob = new ClosureJob();
        $restoredJob->setJobData(
            $jobData->totalSteps,
            $jobData->currentStep,
            $jobData->isComplete,
            $jobData->jobData,
            $jobData->messages
        );

        # Process should work after deserialization
        $restoredJob->process();
        $this->assertTrue($restoredJob->jobFinished());
    }
}
