<?php

namespace Restruct\QueuedJobsEnhancements\Tests\Worker;

use InvalidArgumentException;
use Restruct\QueuedJobsEnhancements\Tests\Stubs\StubImmediateJob;
use Restruct\QueuedJobsEnhancements\Tests\Stubs\StubMultiStepJob;
use Restruct\QueuedJobsEnhancements\Tests\Stubs\StubNonDefaultJob;
use Restruct\QueuedJobsEnhancements\Tests\Stubs\StubWorkerHooks;
use Restruct\QueuedJobsEnhancements\Worker\QueueWorker;
use Restruct\QueuedJobsEnhancements\Worker\WorkerRestartSignal;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\FieldType\DBDatetime;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Symbiote\QueuedJobs\Services\QueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJobService;

/**
 * The worker loop, run in-process against the test database: queue priority, the exit conditions
 * (max-jobs, restart signal, stop signal, a job parked for memory) and --job mode.
 *
 * What only a separate process can show (that a --job run does not drain the immediate queue at
 * exit) is covered by ImmediateSpawnRunsOnlyItsJobTest.
 */
class QueueWorkerTest extends SapphireTest
{
    protected $usesDatabase = true;

    /** @var string[] lines the worker wrote */
    protected array $output = [];

    protected function setUp(): void
    {
        parent::setUp();
        StubWorkerHooks::reset();
        $this->output = [];
        # No time-based parking unless a test asks for it
        Config::modify()->set(QueuedJobService::class, 'time_limit', 0);
    }

    protected function tearDown(): void
    {
        StubWorkerHooks::reset();
        parent::tearDown();
    }

    protected function worker(array $options): TestQueueWorker
    {
        # sleep 0 and stop-when-empty: a test never waits, and never loops forever
        return TestQueueWorker::create(
            array_merge(['sleep' => 0, 'stop-when-empty' => true], $options),
            function (string $message): void {
                $this->output[] = $message;
            }
        );
    }

    protected function jobStatus(int $id): string
    {
        return (string) QueuedJobDescriptor::get()->filter('ID', $id)->column('JobStatus')[0];
    }

    protected function queue($job, ?string $startAfter = null): int
    {
        return QueuedJobService::singleton()->queueJob($job, $startAfter);
    }

    public function testRunsQueuesInPriorityOrder(): void
    {
        $queued = $this->queue(new StubMultiStepJob('queued', QueuedJob::QUEUED));
        $immediate = $this->queue(new StubImmediateJob('immediate'));

        $exit = $this->worker(['queue' => 'immediate,queued'])->run();

        $this->assertSame(0, $exit);
        # The immediate job was queued second but runs first: queues are tried in the given order
        $this->assertSame(['immediate', 'queued#1', 'queued#2', 'queued#3'], StubWorkerHooks::$ran);
        $this->assertSame(QueuedJob::STATUS_COMPLETE, $this->jobStatus($queued));
        $this->assertSame(QueuedJob::STATUS_COMPLETE, $this->jobStatus($immediate));
    }

    public function testOnlyWorksTheGivenQueue(): void
    {
        $queued = $this->queue(new StubNonDefaultJob());

        $this->worker(['queue' => 'immediate'])->run();

        $this->assertSame(QueuedJob::STATUS_NEW, $this->jobStatus($queued));
    }

    public function testMaxJobsStopsTheWorker(): void
    {
        $ids = [];
        foreach (['a', 'b', 'c'] as $label) {
            $ids[] = $this->queue(new StubImmediateJob($label));
        }

        $worker = $this->worker(['max-jobs' => 2]);
        $worker->run();

        $this->assertSame(2, $worker->getJobsRun());
        $this->assertSame(['a', 'b'], StubWorkerHooks::$ran);
        $this->assertSame(QueuedJob::STATUS_NEW, $this->jobStatus($ids[2]));
        $this->assertStringContainsString('max-jobs (2) reached', implode("\n", $this->output));
    }

    public function testRestartSignalStopsTheWorkerAfterTheCurrentJob(): void
    {
        $first = $this->queue(new StubMultiStepJob('first'));
        $second = $this->queue(new StubImmediateJob('second'));

        # queuedjobs:restart arrives while the first job is running
        StubWorkerHooks::$onStep = function (string $label, int $step): void {
            if ($label === 'first' && $step === 1) {
                WorkerRestartSignal::requestRestart();
            }
        };

        $this->worker([])->run();

        # The running job is finished, not cut short; the next one is left for the new worker
        $this->assertSame(QueuedJob::STATUS_COMPLETE, $this->jobStatus($first));
        $this->assertSame(QueuedJob::STATUS_NEW, $this->jobStatus($second));
        $this->assertStringContainsString('restart requested', implode("\n", $this->output));
    }

    public function testRestartTokenKeepsOneRow(): void
    {
        $first = WorkerRestartSignal::requestRestart();
        $second = WorkerRestartSignal::requestRestart();

        $this->assertNotSame($first, $second);
        $this->assertSame($second, WorkerRestartSignal::currentToken());
        $this->assertSame(1, WorkerRestartSignal::get()->count());
    }

    /**
     * SIGTERM in the middle of a job: the current step finishes, the job is parked as Waiting with
     * its lock released (upstream's own time-limit path), and the worker exits. The next worker
     * resumes the job at step 2.
     */
    public function testStopSignalParksTheJobAfterTheCurrentStep(): void
    {
        $job = $this->queue(new StubMultiStepJob('long'));
        $other = $this->queue(new StubImmediateJob('other'));

        $worker = $this->worker([]);
        StubWorkerHooks::$onStep = function (string $label, int $step) use ($worker): void {
            if ($step === 1) {
                $worker->handleSignal(defined('SIGTERM') ? SIGTERM : 15);
            }
        };
        $worker->run();

        $descriptor = QueuedJobDescriptor::get()->byID($job);
        $this->assertSame(['long#1'], StubWorkerHooks::$ran, 'only the step in progress may run');
        $this->assertSame(QueuedJob::STATUS_WAIT, $descriptor->JobStatus);
        $this->assertSame(1, (int) $descriptor->StepsProcessed);
        $this->assertNull($descriptor->Worker, 'the lock must be released so another worker can resume it');
        $this->assertSame(QueuedJob::STATUS_NEW, $this->jobStatus($other));
    }

    /**
     * A job that QueuedJobService parks for memory (status Waiting) needs a fresh process: the
     * worker must exit rather than take the next job.
     */
    public function testJobParkedForMemoryStopsTheWorker(): void
    {
        $job = $this->queue(new StubMultiStepJob('heavy'));
        $other = $this->queue(new StubImmediateJob('other'));

        # The service parks any job after its first step; the worker's own limit stays out of the way
        Config::modify()->set(QueuedJobService::class, 'memory_limit', '1K');
        $this->worker(['memory' => '64G'])->run();

        $this->assertSame(QueuedJob::STATUS_WAIT, $this->jobStatus($job));
        $this->assertSame(QueuedJob::STATUS_NEW, $this->jobStatus($other));
        $this->assertStringContainsString('left Waiting', implode("\n", $this->output));
    }

    public function testMemoryLimitStopsTheWorker(): void
    {
        $job = $this->queue(new StubImmediateJob('never'));

        $this->worker(['memory' => '1K'])->run();

        $this->assertSame(QueuedJob::STATUS_NEW, $this->jobStatus($job));
        $this->assertStringContainsString('memory limit', implode("\n", $this->output));
    }

    public function testMemoryDefaultsToTheServiceLimit(): void
    {
        Config::modify()->set(QueuedJobService::class, 'memory_limit', '300M');

        $this->assertSame(300 * 1048576, QueueWorker::serviceMemoryLimit());
    }

    public function testJobModeRunsOnlyThatJob(): void
    {
        $mine = $this->queue(new StubNonDefaultJob());
        $other = $this->queue(new StubImmediateJob('other'));

        $exit = $this->worker(['job' => (string) $mine, 'stop-when-empty' => false])->run();

        $this->assertSame(0, $exit);
        $this->assertSame(QueuedJob::STATUS_COMPLETE, $this->jobStatus($mine));
        $this->assertSame(QueuedJob::STATUS_NEW, $this->jobStatus($other));
    }

    public function testJobModeAcceptsTheTriggerFileName(): void
    {
        $mine = $this->queue(new StubNonDefaultJob());

        $this->worker(['job' => 'queuedjob-' . $mine])->run();

        $this->assertSame(QueuedJob::STATUS_COMPLETE, $this->jobStatus($mine));
    }

    /**
     * runJob() ignores StartAfter; --job must not, or a spawn could run a job before its time.
     */
    public function testJobModeHonoursStartAfter(): void
    {
        DBDatetime::set_mock_now('2026-10-12 12:00:00');
        $future = $this->queue(new StubImmediateJob('later'), '2026-10-12 13:00:00');

        $this->worker(['job' => (string) $future])->run();

        $this->assertSame(QueuedJob::STATUS_NEW, $this->jobStatus($future));
        $this->assertSame([], StubWorkerHooks::$ran);
    }

    public function testJobModeDoesNotRerunAFinishedJob(): void
    {
        $done = $this->queue(new StubImmediateJob('done'));
        $descriptor = QueuedJobDescriptor::get()->byID($done);
        $descriptor->JobStatus = QueuedJob::STATUS_COMPLETE;
        $descriptor->write();

        $this->worker(['job' => (string) $done])->run();

        $this->assertSame([], StubWorkerHooks::$ran);
    }

    public function testJobModeReportsAMissingJob(): void
    {
        $this->assertSame(1, $this->worker(['job' => '999999'])->run());
    }

    /**
     * A paused queue (maintenance lock) starts nothing, in either mode.
     */
    public function testMaintenanceLockStartsNothing(): void
    {
        Config::modify()->set(QueuedJobService::class, 'lock_file_enabled', true);
        Config::modify()->set(QueuedJobService::class, 'lock_file_path', '');
        Config::modify()->set(QueuedJobService::class, 'lock_file_name', 'qje-worker-test-lock-' . getmypid() . '.txt');
        $service = QueuedJobService::singleton();
        $job = $this->queue(new StubImmediateJob('paused'));

        $service->enableMaintenanceLock();
        try {
            $this->assertTrue($service->isMaintenanceLockActive(), 'precondition: the lock is on');
            $this->worker([])->run();
            $this->worker(['job' => (string) $job])->run();
        } finally {
            $service->disableMaintenanceLock();
        }

        $this->assertSame(QueuedJob::STATUS_NEW, $this->jobStatus($job));
        $this->assertSame([], StubWorkerHooks::$ran);
    }

    public function testWorkerSwitchesOffTheShutdownDrainForItsProcess(): void
    {
        Config::modify()->set(QueuedJobService::class, 'use_shutdown_function', true);

        $this->worker([])->run();

        $this->assertFalse(Config::inst()->get(QueuedJobService::class, 'use_shutdown_function'));
    }

    public function testParsesOptions(): void
    {
        $this->assertSame(
            [QueuedJob::IMMEDIATE, QueuedJob::QUEUED, QueuedJob::LARGE],
            QueueWorker::parseQueues('immediate, queued,large,immediate')
        );
        $this->assertSame(42, QueueWorker::parseJobId('42'));
        $this->assertSame(42, QueueWorker::parseJobId('queuedjob-42'));
        # A bare number is megabytes, as in Laravel
        $this->assertSame(512 * 1048576, QueueWorker::parseMemory('512'));
        $this->assertSame(1073741824, QueueWorker::parseMemory('1G'));
        $this->assertSame(2048, QueueWorker::parseMemory('2K'));
    }

    public function testRejectsAnUnknownQueue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        QueueWorker::parseQueues('immediate,bogus');
    }

    public function testRejectsANonNumericJob(): void
    {
        $this->expectException(InvalidArgumentException::class);
        QueueWorker::parseJobId('abc');
    }
}

/**
 * The worker without real signal handlers: installing pcntl handlers in the PHPUnit process
 * would outlive the test (Ctrl-C would stop working). handleSignal() is called directly instead.
 */
class TestQueueWorker extends QueueWorker
{
    protected function registerSignalHandlers(): void
    {
    }
}
