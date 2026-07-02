<?php

namespace Restruct\QueuedJobsEnhancements\Tests\Tasks;

use Restruct\QueuedJobsEnhancements\Tasks\CleanupBrokenJobsTask;
use SilverStripe\Dev\SapphireTest;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Symbiote\QueuedJobs\Services\QueuedJob;

class CleanupBrokenJobsTaskTest extends SapphireTest
{
    protected $usesDatabase = true;

    public function testDeletesBrokenJobs(): void
    {
        # Create broken jobs
        $broken1 = QueuedJobDescriptor::create();
        $broken1->Implementation = 'TestJob';
        $broken1->JobStatus = QueuedJob::STATUS_BROKEN;
        $broken1->JobTitle = 'Broken job 1';
        $broken1->write();

        $broken2 = QueuedJobDescriptor::create();
        $broken2->Implementation = 'TestJob';
        $broken2->JobStatus = QueuedJob::STATUS_BROKEN;
        $broken2->JobTitle = 'Broken job 2';
        $broken2->write();

        # Create a healthy job (should not be deleted)
        $healthy = QueuedJobDescriptor::create();
        $healthy->Implementation = 'TestJob';
        $healthy->JobStatus = QueuedJob::STATUS_NEW;
        $healthy->JobTitle = 'Healthy job';
        $healthy->write();

        $task = CleanupBrokenJobsTask::create();

        # Capture output
        ob_start();
        $task->run(null);
        $output = ob_get_clean();

        # Broken jobs should be deleted
        $this->assertNull(QueuedJobDescriptor::get()->byID($broken1->ID));
        $this->assertNull(QueuedJobDescriptor::get()->byID($broken2->ID));

        # Healthy job should still exist
        $this->assertNotNull(QueuedJobDescriptor::get()->byID($healthy->ID));

        # Output should mention the count
        $this->assertStringContainsString('2', $output);
        $this->assertStringContainsString('Broken job 1', $output);
        $this->assertStringContainsString('Broken job 2', $output);
    }

    public function testNoBrokenJobsOutputsMessage(): void
    {
        $task = CleanupBrokenJobsTask::create();

        ob_start();
        $task->run(null);
        $output = ob_get_clean();

        $this->assertStringContainsString('No broken jobs found', $output);
    }

    public function testDoesNotDeleteCompletedOrNewJobs(): void
    {
        # Create jobs with various non-broken statuses
        $statuses = [
            QueuedJob::STATUS_NEW,
            QueuedJob::STATUS_INIT,
            QueuedJob::STATUS_RUN,
            QueuedJob::STATUS_COMPLETE,
            QueuedJob::STATUS_PAUSED,
            QueuedJob::STATUS_CANCELLED,
        ];

        $ids = [];
        foreach ($statuses as $status) {
            $descriptor = QueuedJobDescriptor::create();
            $descriptor->Implementation = 'TestJob';
            $descriptor->JobStatus = $status;
            $descriptor->JobTitle = "Job with status {$status}";
            $descriptor->write();
            $ids[] = $descriptor->ID;
        }

        $task = CleanupBrokenJobsTask::create();

        ob_start();
        $task->run(null);
        ob_get_clean();

        # All non-broken jobs should still exist
        foreach ($ids as $id) {
            $this->assertNotNull(
                QueuedJobDescriptor::get()->byID($id),
                "Job #{$id} should not have been deleted"
            );
        }
    }
}
