<?php

namespace Restruct\QueuedJobsEnhancements\Tests\Services;

use Restruct\QueuedJobsEnhancements\Services\DrainSpawner;
use Restruct\QueuedJobsEnhancements\Services\ImmediateJobRunner;
use Restruct\QueuedJobsEnhancements\Tests\Stubs\StubDefaultJob;
use Restruct\QueuedJobsEnhancements\Tests\Stubs\StubMultiStepJob;
use Restruct\QueuedJobsEnhancements\Tests\Stubs\StubNonDefaultJob;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\FieldType\DBDatetime;
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
 * Up to 1.6.0 this also covered the per-job spawn helpers (the spawn command, its flock wrapping
 * and the due check before spawning); those were removed in 1.7.0 with the helpers. Nothing here
 * shells out: the drain spawn is recorded by RecordingDrainSpawner where a test needs to see it.
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
            'queueAndRun() must set JobType to IMMEDIATE'
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
        $member = $this->createNonFirstMember();
        $this->logInAs($member);

        $descriptorId = ImmediateJobRunner::queueAndRun(new StubNonDefaultJob());
        $descriptor = QueuedJobDescriptor::get()->byID($descriptorId);

        $this->assertNotNull($descriptor);
        $this->assertEquals((int) $member->ID, (int) $descriptor->RunAsID);
        // # StubNonDefaultJob::getJobType() returns QUEUED — no override in this path
        // $this->assertEquals(QueuedJob::QUEUED, $descriptor->JobType);
        # Since the drain spawn (mic's design, 2026-10-15) there is one path: queueAndRun() always
        # queues on IMMEDIATE, whatever the job's own queue, and the drain runs it
        $this->assertEquals(QueuedJob::IMMEDIATE, $descriptor->JobType);
    }

    // public function testSpawnCommandNeverUsesTheFpmBinary(): void  // old name: up to 1.6.0 it also checked the per-job spawn command
    public function testCliPhpBinaryIsTheCurrentBinaryUnderCli(): void
    {
        # PHP_BINARY under fpm-fcgi is the FPM daemon (/usr/sbin/php-fpmX.Y) — spawning with it
        # was a silent no-op (prints usage into /dev/null; a site in prod, 2026-07-19). The resolver
        # must find a real CLI binary. (Test runs under cli, where PHP_BINARY IS valid: the
        # resolver must return it here. That the drain command never carries an fpm daemon path is
        # DrainSpawnerTest's; up to 1.6.0 this test also checked the per-job spawn command.)
        $method = new \ReflectionMethod(ImmediateJobRunner::class, 'resolveCliPhpBinary');
        $method->setAccessible(true);
        $binary = $method->invoke(null);
        $this->assertSame(PHP_BINARY, $binary, 'under the cli SAPI the current binary is the right answer');
    }

    /**
     * Regression: queueAndRun($job, $futureStartAfter) spawned a process that ran the job at
     * once. The spawn runs `ProcessJobQueueTask job=queuedjob-<ID>`, which goes straight to
     * QueuedJobService::runJob(), and runJob() never reads StartAfter (only getNextPendingJob()
     * does). A job with a future start must be left to the queue runner instead.
     *
     * Since 1.7.0 there is no per-job spawn left to hold back (the drain runner's
     * getNextPendingJob() skips a job that is not due), so what remains is that the job is queued
     * with the requested start time. The assertion that it was not spawned went with the spawn.
     */
    // public function testFutureStartAfterDoesNotSpawn(): void  // old name: it also asserted that no per-job spawn happened
    public function testFutureStartAfterIsKept(): void
    {
        DBDatetime::set_mock_now('2026-10-12 12:00:00');

        $descriptorId = ImmediateJobRunner::queueAndRun(new StubNonDefaultJob(), '2026-10-12 13:00:00');

        # Still queued, with the requested start time, so the runner picks it up when due
        $descriptor = QueuedJobDescriptor::get()->byID($descriptorId);
        $this->assertNotNull($descriptor);
        $this->assertSame('2026-10-12 13:00:00', $descriptor->StartAfter);
    }

    /**
     * Control for the above: no start time, or one that has passed, still spawns.
     *
     * Up to 1.6.0 "spawns" meant spawnJobProcess() for that one job. Since the drain spawn (mic's
     * design, 2026-10-15) queueAndRun() never calls it: every new IMMEDIATE descriptor starts a
     * drain instead, which is asserted on with a recording DrainSpawner. (spawnJobProcess() and the
     * RecordingImmediateJobRunner that recorded its calls were removed in 1.7.0.)
     */
    public function testDueJobsStillSpawn(): void
    {
        DBDatetime::set_mock_now('2026-10-12 12:00:00');
        $drain = new RecordingDrainSpawner();
        Injector::inst()->registerService($drain, DrainSpawner::class);

        $now = ImmediateJobRunner::queueAndRun(new StubNonDefaultJob());
        $past = ImmediateJobRunner::queueAndRun(new StubDefaultJob(), '2026-10-12 11:59:00');

        $this->assertNotSame($now, $past, 'precondition: two descriptors');
        $this->assertCount(2, $drain->commands, 'one drain request per new job');
    }

    /**
     * queueAndRun() keeps the guarantees of 1.6.0's use_immediate_queue path for every caller:
     * JobType IMMEDIATE (the 4th queueJob() argument), RunAsID left to the job, and a drain
     * requested. (Up to 1.6.0 this ran once with use_immediate_queue off and once on, to show the
     * flag no longer changed anything; the flag was removed in 1.7.0, the two jobs stay.)
     */
    public function testQueueAndRunQueuesImmediateAndRequestsADrain(): void
    {
        $drain = new RecordingDrainSpawner();
        Injector::inst()->registerService($drain, DrainSpawner::class);
        $member = $this->createNonFirstMember();
        $this->logInAs($member);

        foreach ([0, 1] as $n) {
            $descriptor = QueuedJobDescriptor::get()->byID(
                ImmediateJobRunner::queueAndRun(new StubMultiStepJob('job-' . $n, QueuedJob::QUEUED))
            );

            $this->assertSame(QueuedJob::IMMEDIATE, $descriptor->JobType, "job {$n}");
            $this->assertSame((int) $member->ID, (int) $descriptor->RunAsID);
        }
        $this->assertCount(2, $drain->commands);
    }

    /**
     * Guards the documented prerequisite for the inotify path: the trigger file that
     * inotifywait watches is only written when JobType === IMMEDIATE *and*
     * QueuedJobService.use_shutdown_function is false (QueuedJobDescriptor::activateOnQueue()).
     */
    public function testTriggerFileWrittenOnlyWhenShutdownFunctionDisabled(): void
    {
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
