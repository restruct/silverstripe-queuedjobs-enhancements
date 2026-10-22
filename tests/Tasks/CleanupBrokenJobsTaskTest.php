<?php

namespace Restruct\QueuedJobsEnhancements\Tests\Tasks;

use Restruct\QueuedJobsEnhancements\Tasks\CleanupBrokenJobsTask;
use SilverStripe\Dev\SapphireTest;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Symbiote\QueuedJobs\Services\QueuedJob;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Output\BufferedOutput;

class CleanupBrokenJobsTaskTest extends SapphireTest
{
    protected $usesDatabase = true;

    /**
     * Run the task through the public entry point of whichever Silverstripe major is installed
     * and return everything it printed.
     *
     * The tests called `$task->run(null)` under output buffering, which is the SS5 BuildTask API.
     * On SS6 run() is `run(InputInterface, PolyOutput): int` (BuildTask is a PolyCommand), so the
     * same call is a TypeError there. The SS6 branch drives it the way upstream's own
     * ProcessJobQueueTaskTest does; the SS5 branch is the original call, unchanged.
     */
    protected function runTask(CleanupBrokenJobsTask $task): string
    {
        if (!class_exists(PolyOutput::class)) {
            ob_start();
            $task->run(null);
            return (string) ob_get_clean();
        }

        $input = new ArrayInput([], new InputDefinition($task->getOptions()));
        $input->setInteractive(false);
        $buffer = new BufferedOutput();
        $task->run($input, new PolyOutput(PolyOutput::FORMAT_ANSI, wrappedOutput: $buffer));

        return $buffer->fetch();
    }

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
        // ob_start();
        // $task->run(null);
        // $output = ob_get_clean();
        $output = $this->runTask($task);

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

        // ob_start();
        // $task->run(null);
        // $output = ob_get_clean();
        $output = $this->runTask($task);

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

        // ob_start();
        // $task->run(null);
        // ob_get_clean();
        $this->runTask($task);

        # All non-broken jobs should still exist
        foreach ($ids as $id) {
            $this->assertNotNull(
                QueuedJobDescriptor::get()->byID($id),
                "Job #{$id} should not have been deleted"
            );
        }
    }

    /**
     * Run the task as the browser task runner does and return its HTML: on SS6 through a
     * PolyOutput in FORMAT_HTML, decorated (as TaskRunner and PolyCommandController create it); on
     * SS5 through run() with writesHtml() forced on, because Director::is_cli() cannot be faked
     * under PHPUnit.
     */
    protected function runTaskAsHtml(): string
    {
        if (!class_exists(PolyOutput::class)) {
            $task = new class extends CleanupBrokenJobsTask {
                protected function writesHtml(): bool
                {
                    return true;
                }
            };
            ob_start();
            $task->run(null);
            return (string) ob_get_clean();
        }

        $task = CleanupBrokenJobsTask::create();
        $input = new ArrayInput([], new InputDefinition($task->getOptions()));
        $input->setInteractive(false);
        $buffer = new BufferedOutput();
        $task->run($input, new PolyOutput(PolyOutput::FORMAT_HTML, decorated: true, wrappedOutput: $buffer));

        return $buffer->fetch();
    }

    protected function writeBrokenJob(string $title): QueuedJobDescriptor
    {
        $descriptor = QueuedJobDescriptor::create();
        $descriptor->Implementation = 'TestJob';
        $descriptor->JobStatus = QueuedJob::STATUS_BROKEN;
        $descriptor->JobTitle = $title;
        $descriptor->write();

        return $descriptor;
    }

    /**
     * A job title is free text (often built from user input), and 1.7.0 printed it unescaped in
     * the browser task runner: OUTPUT_RAW on SS6, a plain echo on SS5. It must arrive as text.
     */
    public function testJobTitleIsHtmlEscapedInTheBrowser(): void
    {
        $this->writeBrokenJob('<script>x</script>');

        $output = $this->runTaskAsHtml();

        $this->assertStringContainsString('&lt;script&gt;x&lt;/script&gt;', $output);
        $this->assertStringNotContainsString('<script>', $output);
    }

    /**
     * On the CLI the title is printed literally: neither eaten nor applied as a console tag.
     */
    public function testJobTitleIsPrintedLiterallyOnTheCli(): void
    {
        $this->writeBrokenJob('<error>not a style</error> & <b>');

        $output = $this->runTask(CleanupBrokenJobsTask::create());

        $this->assertStringContainsString('Deleting: <error>not a style</error> & <b> (ID: ', $output);
    }
}
