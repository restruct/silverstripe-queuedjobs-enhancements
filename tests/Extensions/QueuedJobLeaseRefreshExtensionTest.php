<?php

namespace Restruct\QueuedJobsEnhancements\Tests\Extensions;

use Restruct\QueuedJobsEnhancements\Extensions\QueuedJobLeaseRefreshExtension;
use Restruct\QueuedJobsEnhancements\Tests\Stubs\StubMultiStepJob;
use Restruct\QueuedJobsEnhancements\Tests\Stubs\StubWorkerHooks;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\FieldType\DBDatetime;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Symbiote\QueuedJobs\Services\QueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJobService;

/**
 * The lock refresh: a three-step job run through upstream's own runJob(), with the clock (mocked)
 * moving 3 minutes per step, so the job runs 9 minutes, past the 5-minute worker_ttl.
 */
class QueuedJobLeaseRefreshExtensionTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected const START = '2026-10-22 10:00:00';

    protected const STEP_SECONDS = 180;

    protected function setUp(): void
    {
        parent::setUp();
        StubWorkerHooks::reset();
        QueuedJobLeaseRefreshExtension::reset();
        Config::modify()->set(QueuedJobService::class, 'time_limit', 0);
        Config::modify()->set(QueuedJobService::class, 'worker_ttl', 'PT5M');
        DBDatetime::set_mock_now(static::START);
    }

    protected function tearDown(): void
    {
        DBDatetime::clear_mock_now();
        StubWorkerHooks::reset();
        QueuedJobLeaseRefreshExtension::reset();
        parent::tearDown();
    }

    protected function enable(bool $on = true): void
    {
        Config::modify()->set(QueuedJobLeaseRefreshExtension::class, 'enabled', $on);
    }

    protected function queue(string $label): int
    {
        return QueuedJobService::singleton()->queueJob(new StubMultiStepJob($label, QueuedJob::QUEUED));
    }

    protected function row(int $id): array
    {
        return DB::prepared_query(
            'SELECT "Expiry", "Worker", "JobStatus" FROM "QueuedJobDescriptor" WHERE "ID" = ?',
            [$id]
        )->record();
    }

    protected function expiryAt(int $id): int
    {
        return (int) strtotime((string) $this->row($id)['Expiry']);
    }

    /**
     * Runs the job; at the start of each step, $atStep($step, $id) may look at the database (which
     * then holds the write of the step before), and afterwards the clock moves STEP_SECONDS on.
     */
    protected function runSteps(int $id, ?callable $atStep = null): void
    {
        StubWorkerHooks::$onStep = function (string $label, int $step) use ($id, $atStep): void {
            if ($atStep) {
                $atStep($step, $id);
            }
            DBDatetime::set_mock_now(DBDatetime::now()->getTimestamp() + static::STEP_SECONDS);
        };
        QueuedJobService::singleton()->runJob($id);
    }

    public function testExpiryMovesForwardAfterEachStep(): void
    {
        $this->enable();
        $id = $this->queue('long');
        $start = strtotime(static::START);
        $seen = [];

        $this->runSteps($id, function (int $step, int $id) use (&$seen): void {
            $seen[$step] = $this->expiryAt($id);
        });

        # Step 1 sees the Running write before any step, at the start: start + 5 min (the refresh
        # sets the same value as grabMutex). Steps 2 and 3 see the write after the step before.
        $this->assertSame($start + 300, $seen[1]);
        $this->assertSame($start + 180 + 300, $seen[2]);
        $this->assertSame($start + 360 + 300, $seen[3]);
        $this->assertSame(QueuedJob::STATUS_COMPLETE, $this->row($id)['JobStatus']);
    }

    public function testOffByDefaultTheExpiryStaysAsUpstreamSetIt(): void
    {
        $this->assertFalse((bool) Config::inst()->get(QueuedJobLeaseRefreshExtension::class, 'enabled'), 'off by default');
        $id = $this->queue('plain');
        $seen = [];

        $this->runSteps($id, function (int $step, int $id) use (&$seen): void {
            $seen[$step] = $this->expiryAt($id);
        });

        $start = strtotime(static::START);
        $this->assertSame([1 => $start + 300, 2 => $start + 300, 3 => $start + 300], $seen);
    }

    /**
     * Another process took the lock over (its own Worker token) while this one still runs the job:
     * this process must not extend that lock.
     */
    public function testALockHeldByAnotherWorkerIsLeftAlone(): void
    {
        $this->enable();
        $id = $this->queue('taken');
        $theirs = '2026-10-22 10:01:00';

        $this->runSteps($id, function (int $step, int $id) use ($theirs): void {
            if ($step === 2) {
                DB::prepared_query(
                    'UPDATE "QueuedJobDescriptor" SET "Worker" = ?, "Expiry" = ? WHERE "ID" = ?',
                    ['another-process', $theirs, $id]
                );
            }
        });

        $row = $this->row($id);
        $this->assertSame(strtotime($theirs), strtotime((string) $row['Expiry']), 'their expiry is untouched');
    }

    /**
     * A Running descriptor written by a process that did not initialise it (the CMS, a script) holds
     * no token: nothing is refreshed.
     */
    public function testAProcessThatDidNotStartTheJobRefreshesNothing(): void
    {
        $this->enable();
        $id = $this->queue('elsewhere');
        DB::prepared_query(
            'UPDATE "QueuedJobDescriptor" SET "JobStatus" = ?, "Worker" = ?, "Expiry" = ? WHERE "ID" = ?',
            [QueuedJob::STATUS_RUN, 'runner-token', '2026-10-22 10:05:00', $id]
        );

        DBDatetime::set_mock_now('2026-10-22 10:04:00');
        $descriptor = QueuedJobDescriptor::get()->byID($id);
        $descriptor->JobTitle = 'renamed in the CMS';
        $descriptor->write();

        $this->assertSame(strtotime('2026-10-22 10:05:00'), $this->expiryAt($id));
    }

    public function testRefreshesAreThrottled(): void
    {
        $this->enable();
        Config::modify()->set(QueuedJobLeaseRefreshExtension::class, 'min_interval', 600);
        $id = $this->queue('throttled');
        $seen = [];

        $this->runSteps($id, function (int $step, int $id) use (&$seen): void {
            $seen[$step] = $this->expiryAt($id);
        });

        # The first Running write refreshes; the next ones fall within 600 s of it
        $start = strtotime(static::START);
        $this->assertSame([1 => $start + 300, 2 => $start + 300, 3 => $start + 300], $seen);
    }

    /**
     * The point of it. Before step 3 the job has run 6 minutes, past worker_ttl. The cron's health
     * check runs twice with no step in between (as two checks during one long step would): with the
     * refresh the job is left running; without it, it is restarted under the running process.
     */
    public function testTheHealthCheckLeavesASteppingJobAloneWithTheRefreshOn(): void
    {
        $this->enable();
        $id = $this->queue('on');
        $stalled = [];

        $this->runSteps($id, function (int $step, int $id) use (&$stalled): void {
            if ($step === 3) {
                $service = QueuedJobService::singleton();
                $service->checkJobHealth(QueuedJob::QUEUED);
                $service->checkJobHealth(QueuedJob::QUEUED);
                # Read right after the second check. Not its 'stalled' result: that list is lazy,
                # evaluated after the restart, when the restarted job no longer matches it
                $stalled = DB::prepared_query(
                    'SELECT "JobStatus", "ResumeCounts" FROM "QueuedJobDescriptor" WHERE "ID" = ?',
                    [$id]
                )->record();
            }
        });

        $this->assertSame(QueuedJob::STATUS_RUN, $stalled['JobStatus'], 'still running after the second check');
        $this->assertSame(0, (int) $stalled['ResumeCounts'], 'never restarted');
        $this->assertSame(QueuedJob::STATUS_COMPLETE, $this->row($id)['JobStatus']);
        $this->assertSame(['on#1', 'on#2', 'on#3'], StubWorkerHooks::$ran);
    }

    /**
     * Control for the test above: the same run with the refresh off is restarted by the health check.
     */
    public function testWithoutTheRefreshTheHealthCheckRestartsTheSameJob(): void
    {
        $id = $this->queue('off');
        $stalled = [];

        $this->runSteps($id, function (int $step, int $id) use (&$stalled): void {
            if ($step === 3) {
                $service = QueuedJobService::singleton();
                $service->checkJobHealth(QueuedJob::QUEUED);
                $service->checkJobHealth(QueuedJob::QUEUED);
                # Read right after the second check. Not its 'stalled' result: that list is lazy,
                # evaluated after the restart, when the restarted job no longer matches it
                $stalled = DB::prepared_query(
                    'SELECT "JobStatus", "ResumeCounts" FROM "QueuedJobDescriptor" WHERE "ID" = ?',
                    [$id]
                )->record();
            }
        });

        $this->assertSame(QueuedJob::STATUS_WAIT, $stalled['JobStatus'], 'restarted: set back to Waiting under the running process');
        $this->assertSame(1, (int) $stalled['ResumeCounts']);
        # The running process does not notice: it finishes its last step and writes Complete over the
        # restart. With more steps left, a second runner would meanwhile have taken the Waiting job,
        # and two processes would work on it (the case this extension exists for).
    }
}
