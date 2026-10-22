<?php

namespace Restruct\QueuedJobsEnhancements\Tests\Services;

use Restruct\QueuedJobsEnhancements\Services\DrainSpawner;
use Restruct\QueuedJobsEnhancements\Services\ImmediateJobRunner;
use Restruct\QueuedJobsEnhancements\Tests\Stubs\StubImmediateJob;
use Restruct\QueuedJobsEnhancements\Tests\Stubs\StubNonDefaultJob;
use Restruct\QueuedJobsEnhancements\Tests\Stubs\StubMultiStepJob;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\PolyExecution\PolyOutput;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Symbiote\QueuedJobs\Services\QueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJobService;

/**
 * Start requests: a job on the Queued (or Large) queue queued with ImmediateJobRunner::queueAndStart()
 * is started by the drain machinery, but stays on its own queue (DrainSpawner::requestStart()). The
 * opt-in is per queued job, not per job class. Uses the recorders
 * of DrainSpawnerTest, which record the command instead of running it. What the runner does with a
 * request is QueueWorkerRequestedTest.
 */
class StartRequestTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected RecordingDrainSpawner $spawner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->spawner = new RecordingDrainSpawner();
        Injector::inst()->registerService($this->spawner, DrainSpawner::class);
        Environment::setEnv(DrainSpawner::ENV_PHP_CLI, '');
        RecordingStartSpawner::resetStarts();
        RecordingDrainSpawner::resetWarnings();
        $this->clearRequests();
    }

    protected function tearDown(): void
    {
        $this->clearRequests();
        RecordingStartSpawner::resetStarts();
        RecordingDrainSpawner::resetWarnings();
        parent::tearDown();
    }

    protected function clearRequests(): void
    {
        foreach (glob($this->spawner->startRequestFolder() . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @unlink($this->spawner->startMarkerPath());
    }

    protected function requestFile(int $id): string
    {
        return $this->spawner->startRequestFolder() . '/' . $id;
    }

    /**
     * @return string[] the recorded commands of the start lane (not the immediate drain)
     */
    protected function startCommands(): array
    {
        return array_values(array_filter(
            $this->spawner->commands,
            fn ($command) => strpos($command, escapeshellarg($this->spawner->startLockPath())) !== false
        ));
    }

    public function testQueueAndStartStartsTheJobOnItsOwnQueue(): void
    {
        $id = ImmediateJobRunner::queueAndStart(new StubNonDefaultJob());

        $this->assertCount(1, $this->spawner->commands, 'one spawn');
        $this->assertCount(1, $this->startCommands(), 'and it is the start lane, not the immediate drain');
        $this->assertFileExists($this->requestFile($id), 'the request is written');
        $this->assertFileExists($this->spawner->startMarkerPath(), 'and the start marker touched');
        $this->assertSame(QueuedJob::QUEUED, QueuedJobDescriptor::get()->byID($id)->JobType, 'still on the Queued queue');
    }

    /**
     * The opt-in is the call, not the class: the same class queued with plain queueJob() waits for
     * its cron.
     */
    public function testAPlainQueueJobOfTheSameClassKeepsWaitingForTheCron(): void
    {
        $started = ImmediateJobRunner::queueAndStart(new StubMultiStepJob('STUDENT', QueuedJob::QUEUED));
        $plain = QueuedJobService::singleton()->queueJob(new StubMultiStepJob('plain', QueuedJob::QUEUED));

        $this->assertCount(1, $this->startCommands());
        $this->assertFileExists($this->requestFile($started));
        $this->assertFileDoesNotExist($this->requestFile($plain));
    }

    /**
     * queueJob() hands back an existing New job with the same signature instead of writing a new
     * one. The caller still asked for that job to run now, so its start is requested.
     */
    public function testADeduplicatedQueueAndStartRequestsTheExistingJob(): void
    {
        $existing = QueuedJobService::singleton()->queueJob(new StubMultiStepJob('same', QueuedJob::QUEUED));
        $this->assertCount(0, $this->spawner->commands, 'precondition: a plain queueJob() starts nothing');

        $id = ImmediateJobRunner::queueAndStart(new StubMultiStepJob('same', QueuedJob::QUEUED));

        $this->assertSame($existing, $id, 'precondition: queueJob() deduplicated');
        $this->assertFileExists($this->requestFile($id));
        $this->assertCount(1, $this->startCommands());
    }

    public function testALargeJobStartsToo(): void
    {
        $id = ImmediateJobRunner::queueAndStart(new StubMultiStepJob('large', QueuedJob::LARGE));

        $this->assertCount(1, $this->startCommands());
        $this->assertFileExists($this->requestFile($id));
        $this->assertSame(QueuedJob::LARGE, QueuedJobDescriptor::get()->byID($id)->JobType);
    }

    /**
     * The same job queued with queueAndStart() twice in one process: one spawn.
     */
    public function testOneStartPerJobPerProcess(): void
    {
        $id = ImmediateJobRunner::queueAndStart(new StubMultiStepJob('both', QueuedJob::QUEUED));
        $again = ImmediateJobRunner::queueAndStart(new StubMultiStepJob('both', QueuedJob::QUEUED));

        $this->assertSame($id, $again, 'precondition: queueJob() deduplicated');
        $this->assertCount(1, $this->startCommands());
    }

    public function testAJobThatIsNotDueIsLeftToTheCron(): void
    {
        $future = date('Y-m-d H:i:s', DBDatetime::now()->getTimestamp() + 3600);
        $id = ImmediateJobRunner::queueAndStart(new StubNonDefaultJob(), $future);

        $this->assertCount(0, $this->spawner->commands);
        $this->assertFileDoesNotExist($this->requestFile($id));
        $this->assertSame(QueuedJob::STATUS_NEW, QueuedJobDescriptor::get()->byID($id)->JobStatus, 'still queued');
    }

    public function testAJobThatAlreadyRanIsNotStarted(): void
    {
        $id = QueuedJobService::singleton()->queueJob(new StubNonDefaultJob());
        $descriptor = QueuedJobDescriptor::get()->byID($id);
        $descriptor->JobStatus = QueuedJob::STATUS_COMPLETE;
        $descriptor->write();

        $this->assertFalse($this->spawner->requestStart($id));
        $this->assertFalse($this->spawner->requestStart(999999), 'nor a missing one');
        $this->assertCount(0, $this->spawner->commands);
    }

    /**
     * An IMMEDIATE job has its own drain: queueAndStart() drains instead of using the start lane.
     * A new immediate job is drained twice over (the extension on insert, then queueAndStart()); the
     * second costs a bash process that finds the lock held, and it is what still drains a
     * deduplicated immediate job, for which the extension does nothing.
     */
    public function testAnImmediateJobIsDrained(): void
    {
        ImmediateJobRunner::queueAndStart(new StubImmediateJob('imm'));

        $this->assertCount(2, $this->spawner->commands);
        $this->assertCount(0, $this->startCommands());
        $this->assertSame([], glob($this->spawner->startRequestFolder() . '/*'));
    }

    public function testCanBeSwitchedOff(): void
    {
        Config::modify()->set(DrainSpawner::class, 'enabled', false);

        $id = ImmediateJobRunner::queueAndStart(new StubNonDefaultJob());

        $this->assertCount(0, $this->spawner->commands);
        $this->assertFileDoesNotExist($this->requestFile($id), 'no request either: the cron runs it');
    }

    public function testWithoutExecNothingIsStarted(): void
    {
        $this->spawner->execAvailable = false;

        $id = ImmediateJobRunner::queueAndStart(new StubNonDefaultJob());

        $this->assertCount(0, $this->spawner->commands);
        $this->assertFileDoesNotExist($this->requestFile($id));
    }

    /**
     * The real guard: a process on a temporary test database never starts a runner.
     */
    public function testNeverStartsOnATestDatabase(): void
    {
        $guarded = new GuardedRecordingDrainSpawner();
        Injector::inst()->registerService($guarded, DrainSpawner::class);

        $id = ImmediateJobRunner::queueAndStart(new StubNonDefaultJob());

        $this->assertCount(0, $guarded->commands);
        $this->assertFileDoesNotExist($this->requestFile($id));
    }

    /**
     * Inside a transaction neither the request nor the spawn happens before the end of the process:
     * a runner that read the request before the commit would not find the row and drop it.
     */
    public function testInsideATransactionTheRequestWaitsForTheEndOfTheProcess(): void
    {
        $this->spawner->transaction = true;

        $a = ImmediateJobRunner::queueAndStart(new StubNonDefaultJob());
        $b = ImmediateJobRunner::queueAndStart(new StubMultiStepJob('t', QueuedJob::QUEUED));

        $this->assertCount(0, $this->spawner->commands, 'nothing now');
        $this->assertFileDoesNotExist($this->requestFile($a));
        $this->assertCount(1, $this->spawner->shutdown, 'one end-of-process start for the lot');

        ($this->spawner->shutdown[0])();

        $this->assertFileExists($this->requestFile($a));
        $this->assertFileExists($this->requestFile($b));
        $this->assertCount(1, $this->startCommands(), 'one spawn for both');
    }

    public function testStartCommandRunsTheRequestedMode(): void
    {
        $command = $this->spawner->buildStartCommand();
        $this->assertNotNull($command);

        $this->assertStringStartsWith('cd ' . escapeshellarg(BASE_PATH) . '; nohup ', $command);
        $this->assertStringContainsString(escapeshellarg(DrainSpawner::wrapperPath()), $command);
        $folder = ImmediateJobRunner::triggerFolder() . '/.qje-spawn';
        $this->assertStringContainsString(escapeshellarg($folder . '/start-pending'), $command);
        $this->assertStringContainsString(escapeshellarg($folder . '/start-lock'), $command);
        $this->assertDirectoryExists($folder . '/start');

        if (class_exists(PolyOutput::class)) {
            $this->assertStringContainsString("'queuedjobs:work' '--requested' '--stop-when-empty'", $command);
        } else {
            $this->assertStringContainsString("'dev/tasks/QueuedJobsWorkTask' 'requested=1' 'stop-when-empty=1'", $command);
        }
        # The immediate lane is unchanged
        $this->assertStringContainsString(escapeshellarg($folder . '/pending'), $this->spawner->buildCommand());
    }
}

/**
 * Access to the per-process start bookkeeping, so each test starts clean.
 */
class RecordingStartSpawner extends DrainSpawner
{
    public static function resetStarts(): void
    {
        static::$startRequested = [];
        static::$deferredStarts = [];
        static::$shutdownStartRegistered = false;
    }
}
