<?php

namespace Restruct\QueuedJobsEnhancements\Tests\Tasks;

use Restruct\QueuedJobsEnhancements\Tasks\QueuedJobProfileTask;
use Restruct\QueuedJobsEnhancements\Tests\Stubs\StubMultiStepJob;
use Restruct\QueuedJobsEnhancements\Tests\Stubs\StubWorkerHooks;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Kernel;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * QueuedJobProfileTask, through the public entry point of whichever major is installed: it runs a
 * stub job to completion and prints one line per process() call plus the summary, lists the job
 * classes when none is named, and refuses outside dev.
 */
class QueuedJobProfileTaskTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected ?string $originalEnvironment = null;

    protected function setUp(): void
    {
        parent::setUp();
        StubWorkerHooks::reset();
        $kernel = Injector::inst()->get(Kernel::class);
        $this->originalEnvironment = $kernel->getEnvironment();
        # The task is dev-only; run in dev unless a test says otherwise
        $kernel->setEnvironment('dev');
    }

    protected function tearDown(): void
    {
        Injector::inst()->get(Kernel::class)->setEnvironment($this->originalEnvironment);
        StubWorkerHooks::reset();
        parent::tearDown();
    }

    /**
     * Run the task with the given options (class, maxcalls) and return [result code, output].
     * SS5's run() returns nothing, so its result code is null.
     *
     * @return array{0: int|null, 1: string}
     */
    protected function runTask(array $options): array
    {
        $task = QueuedJobProfileTask::create();

        if (!class_exists(PolyOutput::class)) {
            ob_start();
            $task->run(new HTTPRequest('GET', 'dev/tasks/qjob-profile', $options));

            return [null, (string) ob_get_clean()];
        }

        $args = [];
        foreach ($options as $name => $value) {
            $args['--' . $name] = $value;
        }
        $input = new ArrayInput($args, new InputDefinition($task->getOptions()));
        $input->setInteractive(false);
        $buffer = new BufferedOutput();
        $code = $task->run($input, new PolyOutput(PolyOutput::FORMAT_ANSI, wrappedOutput: $buffer));

        return [$code, $buffer->fetch()];
    }

    public function testProfilesAJobToCompletion(): void
    {
        [$code, $output] = $this->runTask(['class' => StubMultiStepJob::class]);

        $this->assertStringContainsString('Profiling ' . StubMultiStepJob::class, $output);
        $this->assertMatchesRegularExpression('/^call\s+phase\/step\s+mem\s+delta\s+peak\s+jobData\s+msgs$/m', $output);
        # One line per process() call: the stub completes on its third step
        $this->assertMatchesRegularExpression('/^1\s+\/1\s+\S+M\s+\S+K\s+\S+M\s+\S+K\s+0$/m', $output);
        $this->assertMatchesRegularExpression('/^3\s+\/3\s+/m', $output);
        $this->assertDoesNotMatchRegularExpression('/^4\s+/m', $output);
        $this->assertMatchesRegularExpression('/^COMPLETE after 3 call\(s\) in [0-9.]+s - peak \S+M, final jobData \S+K, 0 message\(s\)$/m', $output);
        $this->assertSame(['#1', '#2', '#3'], StubWorkerHooks::$ran, 'the job really ran');
        if ($code !== null) {
            $this->assertSame(0, $code);
        }
    }

    public function testMaxCallsStopsTheRun(): void
    {
        [, $output] = $this->runTask(['class' => StubMultiStepJob::class, 'maxcalls' => '2']);

        $this->assertStringContainsString('STOPPED (maxcalls reached) after 2 call(s)', $output);
        $this->assertSame(['#1', '#2'], StubWorkerHooks::$ran);
    }

    public function testWithoutAClassItListsTheJobClasses(): void
    {
        [$code, $output] = $this->runTask([]);

        $this->assertStringContainsString('Usage: ', $output);
        $this->assertStringContainsString('Queued jobs found:', $output);
        $this->assertStringContainsString('  - ' . StubMultiStepJob::class, $output);
        $this->assertSame([], StubWorkerHooks::$ran);
        if ($code !== null) {
            $this->assertSame(2, $code, 'no class named is a usage error');
        }
    }

    public function testRejectsAClassThatIsNotAQueuedJob(): void
    {
        [$code, $output] = $this->runTask(['class' => \stdClass::class]);

        $this->assertStringContainsString('Not a queued job class: stdClass', $output);
        $this->assertStringContainsString('Queued jobs found:', $output);
        if ($code !== null) {
            $this->assertSame(2, $code);
        }
    }

    public function testRefusesOutsideDev(): void
    {
        foreach (['test', 'live'] as $environment) {
            StubWorkerHooks::reset();
            Injector::inst()->get(Kernel::class)->setEnvironment($environment);

            [$code, $output] = $this->runTask(['class' => StubMultiStepJob::class]);

            $this->assertStringContainsString('Dev-only', $output, $environment);
            $this->assertStringNotContainsString('Profiling', $output, $environment);
            $this->assertSame([], StubWorkerHooks::$ran, $environment . ': the job must not run');
            if ($code !== null) {
                $this->assertSame(1, $code, $environment);
            }
        }
    }

    /**
     * Run the task as an HTTP request through dev/tasks would: isCli() reports false (PHPUnit
     * itself always runs on the CLI SAPI), and on SS6 the output is a decorated FORMAT_HTML
     * PolyOutput, as the browser task runner creates it. Returns [result code, output].
     *
     * @return array{0: int|null, 1: string}
     */
    protected function runTaskOverHttp(array $options): array
    {
        $task = new class extends QueuedJobProfileTask {
            protected function isCli(): bool
            {
                return false;
            }
        };

        if (!class_exists(PolyOutput::class)) {
            ob_start();
            $task->run(new HTTPRequest('GET', 'dev/tasks/qjob-profile', $options));

            return [null, (string) ob_get_clean()];
        }

        $args = [];
        foreach ($options as $name => $value) {
            $args['--' . $name] = $value;
        }
        $input = new ArrayInput($args, new InputDefinition($task->getOptions()));
        $input->setInteractive(false);
        $buffer = new BufferedOutput();
        $code = $task->run($input, new PolyOutput(PolyOutput::FORMAT_HTML, decorated: true, wrappedOutput: $buffer));

        return [$code, $buffer->fetch()];
    }

    /**
     * In dev mode dev/tasks is open to anyone over HTTP. 1.7.0 checked isDev() only, so an
     * anonymous visitor could make the task run any job class.
     */
    public function testRefusesAnHttpVisitorWhoIsNotAdmin(): void
    {
        $this->logOut();
        [$code, $output] = $this->runTaskOverHttp(['class' => StubMultiStepJob::class]);

        $this->assertStringContainsString('Run this task from the CLI, or log in as an administrator', $output);
        $this->assertStringNotContainsString('Profiling', $output);
        $this->assertSame([], StubWorkerHooks::$ran, 'the job must not run');
        if ($code !== null) {
            $this->assertSame(1, $code);
        }

        # A logged-in member without ADMIN is refused the same way
        $this->logInWithPermission('CMS_ACCESS_CMSMain');
        [, $output] = $this->runTaskOverHttp(['class' => StubMultiStepJob::class]);
        $this->assertStringContainsString('Run this task from the CLI, or log in as an administrator', $output);
        $this->assertSame([], StubWorkerHooks::$ran, 'the job must not run');
    }

    public function testRunsForAnAdminOverHttp(): void
    {
        $this->logInWithPermission('ADMIN');

        [, $output] = $this->runTaskOverHttp(['class' => StubMultiStepJob::class]);

        $this->assertStringContainsString('COMPLETE after 3 call(s)', $output);
        $this->assertSame(['#1', '#2', '#3'], StubWorkerHooks::$ran);
    }

    /**
     * The `class` parameter is echoed back ("Not a queued job class: ..."). In the browser it must
     * arrive as text; on SS5 1.7.0 echoed it raw (reflected XSS).
     */
    public function testRequestClassIsHtmlEscapedInTheBrowser(): void
    {
        $this->logInWithPermission('ADMIN');

        [, $output] = $this->runTaskOverHttp(['class' => '<script>x</script>']);

        $this->assertStringContainsString('Not a queued job class: &lt;script&gt;x&lt;/script&gt;', $output);
        $this->assertStringNotContainsString('<script>', $output);
    }
}
