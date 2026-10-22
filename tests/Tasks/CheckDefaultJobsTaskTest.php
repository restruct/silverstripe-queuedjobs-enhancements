<?php

namespace Restruct\QueuedJobsEnhancements\Tests\Tasks;

use Restruct\QueuedJobsEnhancements\Tasks\CheckDefaultJobsTask;
use Restruct\QueuedJobsEnhancements\Tests\Stubs\StubDefaultJob;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The task runs through BuildTask's public entry point on both majors: run($request) on SS5,
 * run(InputInterface, PolyOutput) on SS6. Before the entry-point split the class could not even
 * be declared on SS6 (typed $title/$description), which took the whole manifest down.
 */
class CheckDefaultJobsTaskTest extends SapphireTest
{
    protected $usesDatabase = true;

    /**
     * @param bool $yaml SS5 `yaml=1` request var / SS6 `--yaml` option
     */
    protected function runTask(bool $yaml): string
    {
        $task = CheckDefaultJobsTask::create();

        if (!class_exists(PolyOutput::class)) {
            ob_start();
            $task->run(new HTTPRequest('GET', 'dev/tasks/check-default-jobs', $yaml ? ['yaml' => '1'] : []));
            return (string) ob_get_clean();
        }

        $input = new ArrayInput($yaml ? ['--yaml' => true] : [], new InputDefinition($task->getOptions()));
        $input->setInteractive(false);
        $buffer = new BufferedOutput();
        $task->run($input, new PolyOutput(PolyOutput::FORMAT_ANSI, wrappedOutput: $buffer));

        return $buffer->fetch();
    }

    public function testTaskIdentifiesItselfOnBothMajors(): void
    {
        $task = CheckDefaultJobsTask::create();
        $this->assertSame('Check Default Jobs', $task->getTitle());

        # getDescription() is static on SS6 and an instance method on SS5; calling it on the
        # instance works for both
        $this->assertStringContainsString('Creates nothing', $task->getDescription());
    }

    public function testReportListsSelfDeclaringJobAsMissing(): void
    {
        $output = $this->runTask(false);

        $this->assertStringContainsString('Self-Declaring Default Jobs', $output);
        $this->assertStringContainsString('[MISSING] StubDefaultJob', $output);
        $this->assertStringContainsString(StubDefaultJob::class, $output);
        $this->assertStringContainsString('+10 minutes', $output);
    }

    public function testYamlModeEmitsDefaultJobsBlock(): void
    {
        $output = $this->runTask(true);

        $this->assertStringContainsString('defaultJobs:', $output);
        $this->assertStringContainsString('StubDefaultJob:', $output);
        $this->assertStringContainsString("startTimeString: '+10 minutes'", $output);
        # The report must not be printed in YAML mode
        $this->assertStringNotContainsString('Self-Declaring Default Jobs', $output);
    }

    /**
     * Run the report as the browser task runner does and return its HTML (see
     * CleanupBrokenJobsTaskTest::runTaskAsHtml() for the two majors).
     */
    protected function runTaskAsHtml(bool $yaml): string
    {
        if (!class_exists(PolyOutput::class)) {
            $task = new class extends CheckDefaultJobsTask {
                protected function writesHtml(): bool
                {
                    return true;
                }
            };
            ob_start();
            $task->run(new HTTPRequest('GET', 'dev/tasks/check-default-jobs', $yaml ? ['yaml' => '1'] : []));
            return (string) ob_get_clean();
        }

        $task = CheckDefaultJobsTask::create();
        $input = new ArrayInput($yaml ? ['--yaml' => true] : [], new InputDefinition($task->getOptions()));
        $input->setInteractive(false);
        $buffer = new BufferedOutput();
        $task->run($input, new PolyOutput(PolyOutput::FORMAT_HTML, decorated: true, wrappedOutput: $buffer));

        return $buffer->fetch();
    }

    /**
     * The task prints config and database values (class names, schedules, job statuses), which
     * 1.7.0 wrote unescaped into the browser page (OUTPUT_RAW on SS6, a plain echo on SS5). The
     * YAML block shows the escaping: its quotes must arrive as entities, as any markup would.
     */
    public function testOutputIsHtmlEscapedInTheBrowser(): void
    {
        $output = $this->runTaskAsHtml(true);

        $this->assertStringContainsString('StubDefaultJob:', $output, 'the YAML block was printed');
        $this->assertStringContainsString('startTimeString: &#039;+10 minutes&#039;', $output);
        $this->assertStringNotContainsString("'+10 minutes'", $output);
    }
}
