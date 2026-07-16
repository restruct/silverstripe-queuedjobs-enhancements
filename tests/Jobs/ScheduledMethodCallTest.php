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

    /**
     * Regression test for the 3.15.0 rethrow port: an exception from the called method must
     * PROPAGATE out of process(). The old code swallowed it and set `$this->jobStatus` — a
     * magic-jobData no-op QueuedJobService never reads, so the runner looped process()
     * indefinitely, re-serialising the growing message log every pass (the step-2563 /
     * 400MB OOM). Throwing lets runJob() mark the descriptor Broken properly.
     */
    public function testProcessRethrowsException(): void
    {
        $job = new ScheduledMethodCall(
            ScheduledMethodCallTestHelper::class,
            'throwingMethod',
            [],
            ['description' => 'Test error handling'],
        );

        $job->setup();

        try {
            $job->process();
            $this->fail('process() must rethrow the exception from the called method');
        } catch (\RuntimeException $e) {
            $this->assertEquals('Test exception', $e->getMessage());
        }

        # The error context message must still be recorded before the rethrow
        $messages = $job->getJobData()->messages;
        $this->assertNotEmpty($messages);
        $this->assertStringContainsString('throwingMethod ERROR', (string) end($messages));

        $this->assertFalse($job->jobFinished());
    }

    /**
     * Regression test for the 3.16.0 ID=0 constructor guard (parity port, 1.2.2): scheduling on
     * an UNWRITTEN DataObject must throw immediately at construction. Without the guard, the
     * falsy objectID=0 made process() silently degrade to a STATIC method call, fataling at cron
     * time far from the cause (hit on a site in prod: scheduling from onBeforeWrite of an initial save).
     */
    public function testScheduleThrowsOnUnwrittenRecord(): void
    {
        # Unwritten — ID is 0
        $descriptor = QueuedJobDescriptor::create();
        $descriptor->JobTitle = 'Never written';
        $this->assertEquals(0, (int) $descriptor->ID);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/UNWRITTEN record \(ID=0\).*write\(\) it first/');

        new ScheduledMethodCall(
            $descriptor,
            'getTitle',
            [],
            ['description' => 'Schedule on unwritten record'],
        );
    }

    /**
     * Regression test for the 3.16.0 fail-fast port: a record deleted between scheduling and
     * execution must throw a clear RuntimeException — not a property-set on null (an Error the
     * old catch then swallowed, feeding the same infinite-loop failure mode as above).
     */
    public function testProcessFailsFastOnDeletedRecord(): void
    {
        # Create a real DataObject, schedule against it, then delete it
        $descriptor = QueuedJobDescriptor::create();
        $descriptor->JobTitle = 'Doomed target';
        $descriptor->Implementation = 'DummyImpl';
        $descriptor->JobStatus = QueuedJob::STATUS_NEW;
        $descriptor->write();

        $job = new ScheduledMethodCall(
            $descriptor,
            'getTitle',
            [],
            ['description' => 'Call on deleted record'],
        );

        $descriptor->delete();

        $job->setup();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no longer exists.*record deleted since scheduling/');
        $job->process();
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
