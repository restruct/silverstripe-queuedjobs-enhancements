<?php

namespace Restruct\QueuedJobsEnhancements\Tests\Jobs;

use Restruct\QueuedJobsEnhancements\Jobs\ScheduledMethodCall;
use SilverStripe\Dev\SapphireTest;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Symbiote\QueuedJobs\Services\QueuedJob;

# Note: AbstractQueuedJob does NOT use Injectable, so ::create() is unavailable.
# Use `new ScheduledMethodCall(...)` or `Injector::inst()->create()` instead.
class ScheduledMethodCallTest extends SapphireTest
{
    protected $usesDatabase = true;

    public function testScheduleQueuesJob(): void
    {
        $descriptorId = ScheduledMethodCall::schedule(
            ScheduledMethodCallTestHelper::class,
            'staticMethod',
            ['test_arg'],
            ['description' => 'Test static method call'],
        );

        $this->assertGreaterThan(0, $descriptorId);

        $descriptor = QueuedJobDescriptor::get()->byID($descriptorId);
        $this->assertNotNull($descriptor);
        $this->assertEquals('Test static method call', $descriptor->JobTitle);
    }

    public function testGetTitleReturnsDescription(): void
    {
        $job = new ScheduledMethodCall(
            ScheduledMethodCallTestHelper::class,
            'staticMethod',
            [],
            ['description' => 'My method call'],
        );

        $this->assertEquals('My method call', $job->getTitle());
    }

    public function testGetTitleDefaultsWhenNoDescription(): void
    {
        # Uses default '[ no description ]' from options
        $job = new ScheduledMethodCall(
            ScheduledMethodCallTestHelper::class,
            'staticMethod',
        );

        $this->assertEquals('[ no description ]', $job->getTitle());
    }

    public function testGetJobTypeReturnsConfigured(): void
    {
        $job = new ScheduledMethodCall(
            ScheduledMethodCallTestHelper::class,
            'staticMethod',
            [],
            ['jobType' => QueuedJob::LARGE],
        );

        $this->assertEquals(QueuedJob::LARGE, $job->getJobType());
    }

    public function testGetJobTypeDefaultsToQueued(): void
    {
        $job = new ScheduledMethodCall(
            ScheduledMethodCallTestHelper::class,
            'staticMethod',
        );

        $this->assertEquals(QueuedJob::QUEUED, $job->getJobType());
    }

    public function testProcessCallsStaticMethod(): void
    {
        ScheduledMethodCallTestHelper::$lastCalled = null;

        $job = new ScheduledMethodCall(
            ScheduledMethodCallTestHelper::class,
            'staticMethod',
            ['test_value'],
            ['description' => 'Test static call'],
        );

        $job->setup();
        $job->process();

        $this->assertTrue($job->jobFinished());
        $this->assertEquals('test_value', ScheduledMethodCallTestHelper::$lastCalled);
    }

    public function testProcessCallsInstanceMethod(): void
    {
        # Create a DataObject and call a method on it
        $descriptor = QueuedJobDescriptor::create();
        $descriptor->JobTitle = 'Test target';
        $descriptor->Implementation = 'DummyImpl';
        $descriptor->JobStatus = QueuedJob::STATUS_NEW;
        $descriptor->write();

        # Schedule a method call on the QueuedJobDescriptor (use a real DataObject)
        $job = new ScheduledMethodCall(
            $descriptor,
            'getTitle',
            [],
            ['description' => 'Test instance call'],
        );

        $job->setup();
        $job->process();

        $this->assertTrue($job->jobFinished());
    }

    public function testSignatureWithIgnoreIdentical(): void
    {
        # Two jobs with ignoreIdentical should have different signatures
        $job1 = new ScheduledMethodCall(
            ScheduledMethodCallTestHelper::class,
            'staticMethod',
            [],
            ['ignoreIdentical' => true],
        );

        $job2 = new ScheduledMethodCall(
            ScheduledMethodCallTestHelper::class,
            'staticMethod',
            [],
            ['ignoreIdentical' => true],
        );

        $this->assertNotEquals($job1->getSignature(), $job2->getSignature());
    }

    public function testSignatureWithoutIgnoreIdentical(): void
    {
        # Two identical jobs without ignoreIdentical should have same signature
        $job1 = new ScheduledMethodCall(
            ScheduledMethodCallTestHelper::class,
            'staticMethod',
            [],
            ['ignoreIdentical' => false],
        );

        $job2 = new ScheduledMethodCall(
            ScheduledMethodCallTestHelper::class,
            'staticMethod',
            [],
            ['ignoreIdentical' => false],
        );

        $this->assertEquals($job1->getSignature(), $job2->getSignature());
    }

    public function testProcessHandlesException(): void
    {
        $job = new ScheduledMethodCall(
            ScheduledMethodCallTestHelper::class,
            'throwingMethod',
            [],
            ['description' => 'Test error handling'],
        );

        $job->setup();
        $job->process();

        # Job should be broken after exception
        $this->assertFalse($job->jobFinished());
    }
}

/**
 * Simple helper class for testing static method calls.
 */
class ScheduledMethodCallTestHelper
{
    public static ?string $lastCalled = null;

    public static function staticMethod(string $value = 'default'): string
    {
        static::$lastCalled = $value;
        return "called with: {$value}";
    }

    public static function throwingMethod(): void
    {
        throw new \RuntimeException('Test exception');
    }
}
