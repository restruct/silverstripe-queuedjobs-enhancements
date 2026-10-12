<?php

namespace Restruct\QueuedJobsEnhancements\Tests\Services;

use Restruct\QueuedJobsEnhancements\Services\ImmediateJobRunner;
use Restruct\QueuedJobsEnhancements\Tests\Stubs\StubDefaultJob;
use Restruct\QueuedJobsEnhancements\Tests\Stubs\StubNonDefaultJob;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\PolyExecution\PolyOutput;
use SilverStripe\Security\Member;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Symbiote\QueuedJobs\Services\QueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJobService;

/**
 * Regression tests for the queueJob() argument-position bug.
 *
 * QueuedJobService::queueJob() is ($job, $startAfter, $userId, $queueName). Passing the
 * queue constant as the 3rd argument silently assigned it to $userId, which both left
 * JobType untouched and forced RunAsID to Member ID 1.
 *
 * Note: spawnJobProcess() early-returns under Director::is_cli(), so the CLI-spawn path
 * never shells out during tests.
 */
class ImmediateJobRunnerTest extends SapphireTest
{
    protected $usesDatabase = true;

    /**
     * Create a member guaranteed not to be ID 1, so a clobbered RunAsID is detectable.
     */
    protected function createNonFirstMember(): Member
    {
        $filler = Member::create();
        $filler->Email = 'filler@example.com';
        $filler->write();

        $member = Member::create();
        $member->Email = 'runner@example.com';
        $member->write();

        return $member;
    }

    public function testImmediateQueueSetsJobTypeNotRunAsId(): void
    {
        Config::modify()->set(ImmediateJobRunner::class, 'use_immediate_queue', true);

        $member = $this->createNonFirstMember();
        $this->assertGreaterThan(1, (int) $member->ID, 'Test needs a member whose ID is not 1');
        $this->logInAs($member);

        $descriptorId = ImmediateJobRunner::queueAndRun(new StubNonDefaultJob());
        $descriptor = QueuedJobDescriptor::get()->byID($descriptorId);
        $this->assertNotNull($descriptor);

        # The queue override must actually apply (4th param), not be swallowed as $userId
        $this->assertEquals(
            QueuedJob::IMMEDIATE,
            $descriptor->JobType,
            'use_immediate_queue must set JobType to IMMEDIATE'
        );

        # RunAsID must reflect the acting member, not be clobbered to the queue constant (1)
        $this->assertEquals(
            (int) $member->ID,
            (int) $descriptor->RunAsID,
            'RunAsID must not be overwritten by the queue constant'
        );
    }

    public function testDefaultPathQueuesWithoutClobberingRunAsId(): void
    {
        Config::modify()->set(ImmediateJobRunner::class, 'use_immediate_queue', false);

        $member = $this->createNonFirstMember();
        $this->logInAs($member);

        $descriptorId = ImmediateJobRunner::queueAndRun(new StubNonDefaultJob());
        $descriptor = QueuedJobDescriptor::get()->byID($descriptorId);

        $this->assertNotNull($descriptor);
        $this->assertEquals((int) $member->ID, (int) $descriptor->RunAsID);
        # StubNonDefaultJob::getJobType() returns QUEUED — no override in this path
        $this->assertEquals(QueuedJob::QUEUED, $descriptor->JobType);
    }

    /**
     * Invoke the protected command builder without shelling out.
     */
    protected function buildSpawnCommand(int $descriptorId): ?string
    {
        $method = new \ReflectionMethod(ImmediateJobRunner::class, 'buildSpawnCommand');
        $method->setAccessible(true);

        return $method->invoke(null, $descriptorId);
    }

    public function testSpawnCommandUsesQueuedjobDashPrefix(): void
    {
        Config::modify()->set(ImmediateJobRunner::class, 'use_spawn_lock', false);

        $cmd = $this->buildSpawnCommand(123);
        $this->assertNotNull($cmd, 'cli-script.php should exist on SS5');

        # Without the dash, ProcessJobQueueTask falls through to draining the whole queue
        $this->assertStringContainsString('job=queuedjob-123', $cmd);
        $this->assertStringNotContainsString('job=123', $cmd);

        # Detached, output discarded (exec() blocks otherwise)
        $this->assertStringContainsString('nohup', $cmd);
        $this->assertStringContainsString('> /dev/null 2>&1 &', $cmd);
    }

    public function testSpawnCommandNeverUsesTheFpmBinary(): void
    {
        # PHP_BINARY under fpm-fcgi is the FPM daemon (/usr/sbin/php-fpmX.Y) — spawning with it
        # was a silent no-op (prints usage into /dev/null; a site in prod, 2026-07-19). The command
        # must resolve a real CLI binary. (Test runs under cli, where PHP_BINARY IS valid — the
        # resolver must return it here, and must never emit an fpm daemon path anywhere.)
        Config::modify()->set(ImmediateJobRunner::class, 'use_spawn_lock', false);

        $cmd = $this->buildSpawnCommand(123);
        $this->assertStringNotContainsString('php-fpm', $cmd);

        $method = new \ReflectionMethod(ImmediateJobRunner::class, 'resolveCliPhpBinary');
        $method->setAccessible(true);
        $binary = $method->invoke(null);
        $this->assertSame(PHP_BINARY, $binary, 'under the cli SAPI the current binary is the right answer');
    }

    /**
     * Covers the lock wrapping on every platform by stubbing the binary lookup —
     * macOS has no util-linux `flock`, so relying on the real binary would silently
     * skip this on local dev and only exercise it in CI.
     */
    public function testSpawnCommandIsLockWrappedWhenEnabled(): void
    {
        Config::modify()->set(StubFlockImmediateJobRunner::class, 'use_spawn_lock', true);
        Config::modify()->set(StubFlockImmediateJobRunner::class, 'spawn_lock_timeout', 42);

        $method = new \ReflectionMethod(StubFlockImmediateJobRunner::class, 'buildSpawnCommand');
        $method->setAccessible(true);
        $cmd = $method->invoke(null, 123);

        $this->assertStringContainsString('flock', $cmd);
        $this->assertStringContainsString('-w 42', $cmd);
        $this->assertStringContainsString('job=queuedjob-123', $cmd);
        # flock must wrap the php command, not follow it
        $this->assertLessThan(
            strpos($cmd, 'ProcessJobQueueTask'),
            strpos($cmd, 'flock'),
            'flock must precede the command it guards'
        );
    }

    public function testSpawnCommandUsesNonBlockingLockWhenTimeoutIsZero(): void
    {
        Config::modify()->set(StubFlockImmediateJobRunner::class, 'use_spawn_lock', true);
        Config::modify()->set(StubFlockImmediateJobRunner::class, 'spawn_lock_timeout', 0);

        $method = new \ReflectionMethod(StubFlockImmediateJobRunner::class, 'buildSpawnCommand');
        $method->setAccessible(true);
        $cmd = $method->invoke(null, 5);

        $this->assertStringContainsString(' -n ', $cmd);
        $this->assertStringNotContainsString('-w ', $cmd);
    }

    /**
     * With no flock binary available the command must still be produced, unlocked,
     * rather than referencing a binary that does not exist.
     */
    public function testSpawnCommandDegradesGracefullyWithoutFlockBinary(): void
    {
        Config::modify()->set(StubNoFlockImmediateJobRunner::class, 'use_spawn_lock', true);

        $method = new \ReflectionMethod(StubNoFlockImmediateJobRunner::class, 'buildSpawnCommand');
        $method->setAccessible(true);
        $cmd = $method->invoke(null, 9);

        $this->assertStringNotContainsString('flock', $cmd);
        $this->assertStringContainsString('job=queuedjob-9', $cmd);
    }

    public function testSpawnCommandOmitsLockWhenDisabled(): void
    {
        Config::modify()->set(ImmediateJobRunner::class, 'use_spawn_lock', false);

        $cmd = $this->buildSpawnCommand(7);

        $this->assertStringNotContainsString('flock', $cmd);
        $this->assertStringContainsString('job=queuedjob-7', $cmd);
    }

    /**
     * Regression: queueAndRun($job, $futureStartAfter) spawned a process that ran the job at
     * once. The spawn runs `ProcessJobQueueTask job=queuedjob-<ID>`, which goes straight to
     * QueuedJobService::runJob(), and runJob() never reads StartAfter (only getNextPendingJob()
     * does). A job with a future start must be left to the queue runner instead.
     */
    public function testFutureStartAfterDoesNotSpawn(): void
    {
        Config::modify()->set(ImmediateJobRunner::class, 'use_immediate_queue', false);
        DBDatetime::set_mock_now('2026-10-12 12:00:00');
        RecordingImmediateJobRunner::$spawned = [];

        $descriptorId = RecordingImmediateJobRunner::queueAndRun(new StubNonDefaultJob(), '2026-10-12 13:00:00');

        # Still queued, with the requested start time, so the runner picks it up when due
        $descriptor = QueuedJobDescriptor::get()->byID($descriptorId);
        $this->assertNotNull($descriptor);
        $this->assertSame('2026-10-12 13:00:00', $descriptor->StartAfter);
        $this->assertSame([], RecordingImmediateJobRunner::$spawned, 'a job that is not due yet must not be spawned');
    }

    /**
     * Same defect by another route: queueJob() deduplicates on signature and hands back an
     * existing New descriptor, which can have a future StartAfter even when this call passed
     * none. That descriptor must not be spawned either.
     */
    public function testDeduplicatedFutureDescriptorDoesNotSpawn(): void
    {
        Config::modify()->set(ImmediateJobRunner::class, 'use_immediate_queue', false);
        DBDatetime::set_mock_now('2026-10-12 12:00:00');
        RecordingImmediateJobRunner::$spawned = [];

        $existing = QueuedJobService::singleton()->queueJob(new StubNonDefaultJob(), '2026-10-12 13:00:00');
        $descriptorId = RecordingImmediateJobRunner::queueAndRun(new StubNonDefaultJob());

        $this->assertSame($existing, $descriptorId, 'precondition: queueJob() returned the existing descriptor');
        $this->assertSame([], RecordingImmediateJobRunner::$spawned);
    }

    /**
     * Control for the above: no start time, or one that has passed, still spawns.
     */
    public function testDueJobsStillSpawn(): void
    {
        Config::modify()->set(ImmediateJobRunner::class, 'use_immediate_queue', false);
        DBDatetime::set_mock_now('2026-10-12 12:00:00');
        RecordingImmediateJobRunner::$spawned = [];

        $now = RecordingImmediateJobRunner::queueAndRun(new StubNonDefaultJob());
        $past = RecordingImmediateJobRunner::queueAndRun(new StubDefaultJob(), '2026-10-12 11:59:00');

        $this->assertSame([$now, $past], RecordingImmediateJobRunner::$spawned);
    }

    /**
     * SS6 removed cli-script.php, so buildSpawnCommand() returned null there and every spawn was
     * silently skipped (the job waited for cron). Each major must get a runnable command.
     */
    public function testSpawnCommandMatchesTheInstalledMajor(): void
    {
        Config::modify()->set(ImmediateJobRunner::class, 'use_spawn_lock', false);

        $cmd = $this->buildSpawnCommand(77);
        $this->assertNotNull($cmd);

        if (class_exists(PolyOutput::class)) {
            # SS6: framework's PHP sake script, Symfony-style option
            $this->assertStringContainsString('silverstripe/framework/bin/sake', $cmd);
            $this->assertStringContainsString('tasks:ProcessJobQueueTask --job=queuedjob-77', $cmd);
            $this->assertStringNotContainsString('cli-script.php', $cmd);
        } else {
            $this->assertStringContainsString('silverstripe/framework/cli-script.php', $cmd);
            $this->assertStringContainsString('dev/tasks/ProcessJobQueueTask job=queuedjob-77', $cmd);
        }
    }

    /**
     * A relative spawn_lock_file resolves under TEMP_PATH. It used TEMP_FOLDER, which SS6 no
     * longer defines, so building a locked command fataled there whenever flock was installed.
     */
    public function testRelativeLockFileResolvesUnderTempPath(): void
    {
        Config::modify()->set(StubFlockImmediateJobRunner::class, 'use_spawn_lock', true);
        Config::modify()->set(StubFlockImmediateJobRunner::class, 'spawn_lock_file', 'qje-test.lock');

        $method = new \ReflectionMethod(StubFlockImmediateJobRunner::class, 'wrapWithLock');
        $method->setAccessible(true);
        $cmd = $method->invoke(null, 'true');

        $this->assertStringContainsString(escapeshellarg(TEMP_PATH . '/qje-test.lock'), $cmd);
    }

    /**
     * Guards the documented prerequisite for the inotify path: the trigger file that
     * inotifywait watches is only written when JobType === IMMEDIATE *and*
     * QueuedJobService.use_shutdown_function is false (QueuedJobDescriptor::activateOnQueue()).
     */
    public function testTriggerFileWrittenOnlyWhenShutdownFunctionDisabled(): void
    {
        Config::modify()->set(ImmediateJobRunner::class, 'use_immediate_queue', true);
        Config::modify()->set(QueuedJobService::class, 'use_shutdown_function', false);

        $descriptorId = ImmediateJobRunner::queueAndRun(new StubNonDefaultJob());
        $descriptor = QueuedJobDescriptor::get()->byID($descriptorId);

        $this->assertEquals(QueuedJob::IMMEDIATE, $descriptor->JobType);

        $jobDir = Config::inst()->get(QueuedJobService::class, 'cache_dir');
        if ($jobDir[0] !== '/') {
            # TEMP_PATH: Silverstripe 6 no longer defines TEMP_FOLDER (same value on SS5)
            // $jobDir = TEMP_FOLDER . '/' . $jobDir;
            $jobDir = TEMP_PATH . '/' . $jobDir;
        }

        $this->assertFileExists(
            $jobDir . '/queuedjob-' . $descriptorId,
            'IMMEDIATE + use_shutdown_function:false must write the inotify trigger file'
        );
    }
}

/**
 * Pretends flock is installed, so the lock-wrapping branch is covered on macOS too.
 */
class StubFlockImmediateJobRunner extends ImmediateJobRunner
{
    protected static function resolveFlockBinary(): ?string
    {
        return '/usr/bin/flock';
    }
}

/**
 * Pretends flock is absent, exercising the graceful-degradation branch on Linux too.
 */
class StubNoFlockImmediateJobRunner extends ImmediateJobRunner
{
    protected static function resolveFlockBinary(): ?string
    {
        return null;
    }
}

/**
 * Records spawn requests instead of shelling out, so the "should it spawn" decision in
 * queueAndRun() is observable. (spawnJobProcess() early-returns under CLI anyway, which is why
 * the real one cannot be asserted on.)
 */
class RecordingImmediateJobRunner extends ImmediateJobRunner
{
    /** @var int[] descriptor IDs queueAndRun() asked to spawn */
    public static array $spawned = [];

    protected static function spawnJobProcess(int $descriptorId): void
    {
        static::$spawned[] = $descriptorId;
    }
}
