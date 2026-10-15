<?php

namespace Restruct\QueuedJobsEnhancements\Tests\Services;

use Restruct\QueuedJobsEnhancements\Services\DrainSpawner;
use Restruct\QueuedJobsEnhancements\Services\ImmediateJobRunner;
use Restruct\QueuedJobsEnhancements\Tests\Stubs\StubImmediateJob;
use Restruct\QueuedJobsEnhancements\Tests\Stubs\StubMultiStepJob;
use Restruct\QueuedJobsEnhancements\Tests\Stubs\StubNonDefaultJob;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\PolyExecution\PolyOutput;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Symbiote\QueuedJobs\Services\QueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJobService;

/**
 * The drain spawn decision (QueuedJobDescriptorDrainExtension -> DrainSpawner) and the command it
 * builds. RecordingDrainSpawner records the command instead of running it; the real wrapper and
 * runner are run by DrainSpawnEndToEndTest.
 */
class DrainSpawnerTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected RecordingDrainSpawner $spawner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->spawner = new RecordingDrainSpawner();
        # DrainSpawner::singleton() (what the extension calls) now returns the recorder
        Injector::inst()->registerService($this->spawner, DrainSpawner::class);
        Environment::setEnv(DrainSpawner::ENV_PHP_CLI, '');
    }

    protected function tearDown(): void
    {
        Environment::setEnv(DrainSpawner::ENV_PHP_CLI, '');
        parent::tearDown();
    }

    protected function queue($job, ?string $queue = null): int
    {
        return QueuedJobService::singleton()->queueJob($job, null, null, $queue);
    }

    public function testANewImmediateDescriptorSpawnsOnce(): void
    {
        @unlink($this->spawner->markerPath());

        $id = $this->queue(new StubImmediateJob('one'));

        $this->assertCount(1, $this->spawner->commands);
        $this->assertFileExists($this->spawner->markerPath(), 'the marker is touched before the spawn');
        $this->assertSame(QueuedJob::IMMEDIATE, QueuedJobDescriptor::get()->byID($id)->JobType);
    }

    /**
     * Every later write of the same descriptor (status, steps, messages) must not spawn again.
     */
    public function testUpdatesDoNotSpawn(): void
    {
        $id = $this->queue(new StubImmediateJob('one'));
        $this->assertCount(1, $this->spawner->commands, 'precondition');

        $descriptor = QueuedJobDescriptor::get()->byID($id);
        $descriptor->JobTitle = 'renamed';
        $descriptor->write();
        $descriptor->JobStatus = QueuedJob::STATUS_RUN;
        $descriptor->write();
        # A forced write of an unchanged record
        $descriptor->write(false, false, true);

        $this->assertCount(1, $this->spawner->commands);
    }

    public function testOtherQueuesDoNotSpawn(): void
    {
        # StubNonDefaultJob is a QUEUED job; StubMultiStepJob takes its queue as an argument
        $this->queue(new StubNonDefaultJob());
        $this->queue(new StubMultiStepJob('large', QueuedJob::LARGE));
        $this->assertCount(0, $this->spawner->commands);

        # Control: the same QUEUED job class, queued on IMMEDIATE through queueJob()'s 4th argument, does
        $this->queue(new StubMultiStepJob('moved', QueuedJob::QUEUED), QueuedJob::IMMEDIATE);
        $this->assertCount(1, $this->spawner->commands);
    }

    /**
     * queueJob() hands back the existing descriptor for a job with the same signature, without a
     * write: no second spawn.
     */
    public function testADeduplicatedQueueJobDoesNotSpawn(): void
    {
        $first = $this->queue(new StubImmediateJob('same'));
        $second = $this->queue(new StubImmediateJob('same'));

        $this->assertSame($first, $second, 'precondition: queueJob() deduplicated');
        $this->assertCount(1, $this->spawner->commands);
    }

    public function testCanBeSwitchedOff(): void
    {
        Config::modify()->set(DrainSpawner::class, 'enabled', false);

        $this->queue(new StubImmediateJob('off'));

        $this->assertCount(0, $this->spawner->commands);
    }

    /**
     * exec() disabled: nothing is spawned, the job is still queued as IMMEDIATE, and the warning is
     * logged once per process, not once per job.
     */
    public function testWithoutExecNothingIsSpawnedAndItIsLoggedOnce(): void
    {
        $this->spawner->execAvailable = false;
        RecordingDrainSpawner::resetWarnings();

        $a = $this->queue(new StubImmediateJob('a'));
        $b = $this->queue(new StubImmediateJob('b'));

        $this->assertCount(0, $this->spawner->commands);
        $this->assertSame(QueuedJob::IMMEDIATE, QueuedJobDescriptor::get()->byID($a)->JobType);
        $this->assertSame(QueuedJob::STATUS_NEW, QueuedJobDescriptor::get()->byID($b)->JobStatus);
        $warnings = array_filter($this->spawner->logged, fn ($m) => strpos($m, 'exec() is disabled') !== false);
        $this->assertCount(1, $warnings, implode("\n", $this->spawner->logged));
    }

    /**
     * The real guard, not the recorder's override: a process on a temporary test database never
     * spawns (the runner would boot against the site's real database).
     */
    public function testNeverSpawnsOnATestDatabase(): void
    {
        $guarded = new GuardedRecordingDrainSpawner();
        Injector::inst()->registerService($guarded, DrainSpawner::class);

        $this->queue(new StubImmediateJob('guarded'));

        $this->assertCount(0, $guarded->commands);
    }

    /**
     * Inside a transaction the runner cannot see the row before the commit (measured: a 5 s
     * transaction lost the job until the next dispatch). Drain now AND once at the end of the
     * process, however many jobs the transaction queues.
     */
    public function testInsideATransactionItAlsoDrainsAtShutdownOnce(): void
    {
        RecordingDrainSpawner::resetWarnings();
        $this->spawner->transaction = true;

        $this->queue(new StubImmediateJob('t1'));
        $this->queue(new StubImmediateJob('t2'));

        $this->assertCount(2, $this->spawner->commands, 'a drain per job now');
        $this->assertCount(1, $this->spawner->shutdown, 'one end-of-process drain for the lot');

        @unlink($this->spawner->markerPath());
        ($this->spawner->shutdown[0])();
        $this->assertCount(3, $this->spawner->commands, 'the shutdown drain spawns');
        $this->assertFileExists($this->spawner->markerPath(), 'and touches the marker first');
        RecordingDrainSpawner::resetWarnings();
    }

    public function testOutsideATransactionNothingIsDeferred(): void
    {
        RecordingDrainSpawner::resetWarnings();
        $this->spawner->transaction = false;

        $this->queue(new StubImmediateJob('plain'));

        $this->assertCount(1, $this->spawner->commands);
        $this->assertCount(0, $this->spawner->shutdown);
    }

    /**
     * The real transaction check: SapphireTest runs each test inside a transaction.
     */
    public function testDetectsTheOpenTestTransaction(): void
    {
        RecordingDrainSpawner::resetWarnings();
        $this->spawner->transaction = null;

        $this->queue(new StubImmediateJob('real'));

        $this->assertCount(1, $this->spawner->shutdown);
        RecordingDrainSpawner::resetWarnings();
    }

    public function testCommandStartsTheWrapperDetachedFromTheProjectRoot(): void
    {
        $command = $this->spawner->buildCommand();
        $this->assertNotNull($command);

        $this->assertStringStartsWith('cd ' . escapeshellarg(BASE_PATH) . '; nohup ', $command);
        $this->assertStringEndsWith(' > /dev/null 2>&1 &', $command);
        $this->assertStringContainsString(escapeshellarg(DrainSpawner::wrapperPath()), $command);
        $this->assertFileExists(DrainSpawner::wrapperPath());

        # Marker and lock in the .qje-spawn/ subfolder of upstream's job folder (mic's decision
        # 2026-10-15; before, they sat in the job folder itself), and never a name like a trigger file
        $folder = ImmediateJobRunner::triggerFolder();
        // $this->assertStringContainsString(escapeshellarg($folder . '/.qje-spawn.pending'), $command);  // old: in the job folder itself
        // $this->assertStringContainsString(escapeshellarg($folder . '/.qje-spawn.lock'), $command);  // old: in the job folder itself
        $this->assertStringContainsString(escapeshellarg($folder . '/.qje-spawn/pending'), $command);
        $this->assertStringContainsString(escapeshellarg($folder . '/.qje-spawn/lock'), $command);
        $this->assertDirectoryExists($folder . '/.qje-spawn');
        $this->assertStringNotContainsString('queuedjob-', $command);

        if (class_exists(PolyOutput::class)) {
            $this->assertStringContainsString("'queuedjobs:work' '--queue=immediate' '--stop-when-empty'", $command);
        } else {
            $this->assertStringContainsString("'dev/tasks/QueuedJobsWorkTask' 'queue=immediate' 'stop-when-empty=1'", $command);
        }
    }

    public function testLogFileIsAppendedToWhenSet(): void
    {
        Config::modify()->set(DrainSpawner::class, 'log_file', 'qje-drain.log');

        $this->assertStringEndsWith(' >> ' . escapeshellarg(TEMP_PATH . '/qje-drain.log') . ' 2>&1 &', $this->spawner->buildCommand());
    }

    /**
     * QJE_PHP_CLI (environment or .env) wins over the php_cli config, which wins over the automatic
     * resolution.
     */
    public function testPhpBinaryPrecedence(): void
    {
        Config::modify()->set(DrainSpawner::class, 'php_cli', '/opt/config/php');
        Environment::setEnv(DrainSpawner::ENV_PHP_CLI, '/opt/env/php');
        $this->assertSame('/opt/env/php', $this->spawner->phpBinary());
        $this->assertStringContainsString(escapeshellarg('/opt/env/php'), $this->spawner->buildCommand());

        Environment::setEnv(DrainSpawner::ENV_PHP_CLI, '');
        $this->assertSame('/opt/config/php', $this->spawner->phpBinary());

        Config::modify()->set(DrainSpawner::class, 'php_cli', '');
        $this->assertSame(ImmediateJobRunner::cliPhpBinary(), $this->spawner->phpBinary());
        # Under the cli SAPI (PHPUnit) the automatic answer is this very binary
        $this->assertSame(PHP_BINARY, $this->spawner->phpBinary());
    }

    /**
     * php-fpm can never run the runner (handed a script it prints its usage), so it is never used:
     * not from QJE_PHP_CLI, not from the config, and not from the automatic resolution under
     * fpm-fcgi, where PHP_BINARY is the FPM daemon.
     */
    public function testFpmBinaryIsNeverUsed(): void
    {
        Environment::setEnv(DrainSpawner::ENV_PHP_CLI, '/usr/sbin/php-fpm8.3');
        Config::modify()->set(DrainSpawner::class, 'php_cli', '/usr/sbin/php8.3-fpm');

        $this->assertSame(ImmediateJobRunner::cliPhpBinary(), $this->spawner->phpBinary());
        # One warning per skipped source for that one call
        $this->assertCount(2, array_filter($this->spawner->logged, fn ($m) => strpos($m, 'points at php-fpm') !== false));
        $this->assertStringNotContainsString('fpm', $this->spawner->buildCommand());

        $this->assertTrue(DrainSpawner::isFpmBinary('/usr/sbin/php-fpm8.3'));
        $this->assertTrue(DrainSpawner::isFpmBinary('/usr/sbin/php-fpm'));
        $this->assertTrue(DrainSpawner::isFpmBinary('php8.3-fpm'));
        $this->assertFalse(DrainSpawner::isFpmBinary('/usr/bin/php8.3'));
        $this->assertFalse(DrainSpawner::isFpmBinary('/opt/homebrew/opt/php@8.3/bin/php'));

        # The automatic resolution as a web request would run it
        $method = new \ReflectionMethod(ImmediateJobRunner::class, 'resolveCliPhpBinaryFor');
        $method->setAccessible(true);
        $fromFpm = $method->invoke(null, 'fpm-fcgi', '/usr/sbin/php-fpm8.3');
        $this->assertFalse(DrainSpawner::isFpmBinary($fromFpm), "under fpm-fcgi it resolved {$fromFpm}");
        $this->assertStringNotContainsString('fpm', $fromFpm);
        # Never an empty binary either, whatever PHP_BINARY is
        $this->assertNotSame('', $method->invoke(null, 'cli', ''));
    }
}

/**
 * Records instead of running, and acts as if this were not a test database.
 */
class RecordingDrainSpawner extends DrainSpawner
{
    /** @var string[] */
    public array $commands = [];

    /** @var string[] */
    public array $logged = [];

    public bool $execAvailable = true;

    /** @var bool|null null = ask the real connection (where SapphireTest always has one open) */
    public ?bool $transaction = false;

    /** @var callable[] what requestDrain() asked to run at shutdown */
    public array $shutdown = [];

    public static function resetWarnings(): void
    {
        static::$warnedNoExec = false;
        static::$shutdownDrainRegistered = false;
    }

    protected function inTransaction(): bool
    {
        return $this->transaction ?? parent::inTransaction();
    }

    protected function registerShutdown(callable $callback): void
    {
        $this->shutdown[] = $callback;
    }

    protected function canExec(): bool
    {
        return $this->execAvailable;
    }

    protected function isTestDatabase(): bool
    {
        return false;
    }

    protected function launch(string $command): void
    {
        $this->commands[] = $command;
    }

    protected function log(string $message): void
    {
        $this->logged[] = $message;
    }
}

/**
 * Records, but keeps the real test-database guard.
 */
class GuardedRecordingDrainSpawner extends DrainSpawner
{
    /** @var string[] */
    public array $commands = [];

    protected function launch(string $command): void
    {
        $this->commands[] = $command;
    }
}
