<?php

namespace Restruct\QueuedJobsEnhancements\Tests\Extensions;

use Restruct\QueuedJobsEnhancements\Extensions\QueuedJobContextExtension;
use SilverStripe\Dev\SapphireTest;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Symbiote\QueuedJobs\Services\QueuedJob;

/**
 * QueuedJobContextExtension: the job being processed is recorded in $_SERVER while its descriptor
 * says Running, and cleared on any other status. The key names and the value format are a contract
 * with admintweaks' EnhancedErrorFormatter (its default `server_vars`), so they are asserted exactly.
 */
class QueuedJobContextExtensionTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();
        unset($_SERVER['QUEUED_JOB'], $_SERVER['QUEUED_JOB_MEMORY']);
    }

    protected function tearDown(): void
    {
        # Never leak a job context into the next test (or the error reporter of this process)
        unset($_SERVER['QUEUED_JOB'], $_SERVER['QUEUED_JOB_MEMORY']);
        parent::tearDown();
    }

    protected function descriptor(string $status): QueuedJobDescriptor
    {
        $descriptor = QueuedJobDescriptor::create();
        $descriptor->Implementation = 'Some\\Job\\Class';
        $descriptor->JobTitle = 'Context test job';
        # QUEUED, not IMMEDIATE: this test is about the context, not the drain spawn
        $descriptor->JobType = QueuedJob::QUEUED;
        $descriptor->StepsProcessed = 3;
        $descriptor->TotalSteps = 10;
        $descriptor->JobStatus = $status;

        return $descriptor;
    }

    public function testTheExtensionIsRegistered(): void
    {
        $this->assertTrue(QueuedJobDescriptor::has_extension(QueuedJobContextExtension::class));
    }

    public function testARunningJobIsRecorded(): void
    {
        $descriptor = $this->descriptor(QueuedJob::STATUS_RUN);
        $descriptor->write();

        $this->assertSame(
            sprintf('#%d Some\\Job\\Class | Context test job | step 3/10 | queue %s', $descriptor->ID, QueuedJob::QUEUED),
            $_SERVER['QUEUED_JOB'] ?? null
        );
        $this->assertMatchesRegularExpression(
            '#^used [0-9.]+ MB / peak [0-9.]+ MB \| php\.ini memory_limit \S+$#',
            $_SERVER['QUEUED_JOB_MEMORY'] ?? ''
        );
    }

    /**
     * Every later write of a running job refreshes the context (the service writes after each step).
     */
    public function testEachWriteOfARunningJobRefreshesTheStep(): void
    {
        $descriptor = $this->descriptor(QueuedJob::STATUS_RUN);
        $descriptor->write();
        $descriptor->StepsProcessed = 4;
        $descriptor->write();

        $this->assertStringContainsString('| step 4/10 |', $_SERVER['QUEUED_JOB'] ?? '');
    }

    /**
     * Any status other than Running clears both keys, so a later unrelated error in the same runner
     * process is not blamed on a finished job.
     */
    public function testOtherStatusesClearTheContext(): void
    {
        foreach ([QueuedJob::STATUS_COMPLETE, QueuedJob::STATUS_BROKEN, QueuedJob::STATUS_PAUSED, QueuedJob::STATUS_NEW] as $status) {
            $descriptor = $this->descriptor(QueuedJob::STATUS_RUN);
            $descriptor->write();
            $this->assertArrayHasKey('QUEUED_JOB', $_SERVER, 'precondition: ' . $status);

            $descriptor->JobStatus = $status;
            $descriptor->write();

            $this->assertArrayNotHasKey('QUEUED_JOB', $_SERVER, $status);
            $this->assertArrayNotHasKey('QUEUED_JOB_MEMORY', $_SERVER, $status);
        }
    }

    public function testANewJobRecordsNothing(): void
    {
        $this->descriptor(QueuedJob::STATUS_NEW)->write();

        $this->assertArrayNotHasKey('QUEUED_JOB', $_SERVER);
        $this->assertArrayNotHasKey('QUEUED_JOB_MEMORY', $_SERVER);
    }
}
