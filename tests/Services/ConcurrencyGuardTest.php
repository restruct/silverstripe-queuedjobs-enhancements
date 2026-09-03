<?php

namespace Restruct\QueuedJobsEnhancements\Tests\Services;

use Restruct\QueuedJobsEnhancements\Services\ConcurrencyGuard;
use Restruct\QueuedJobsEnhancements\Services\EnhancedQueuedJobService;
use Restruct\QueuedJobsEnhancements\Tests\Stubs\StubGuardedJob;
use Restruct\QueuedJobsEnhancements\Tests\Stubs\StubNonDefaultJob;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\FieldType\DBDatetime;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Symbiote\QueuedJobs\Services\QueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJobService;

/**
 * Concurrency guard: a job class declaring `prevent_concurrent_runs = true`
 * must never have two descriptors active at once — the second one is DEFERRED (StartAfter
 * pushed), never run and never marked complete/broken.
 *
 * Exercised through the real runner path (EnhancedQueuedJobService::processJobQueue ->
 * runJob -> guarded grabMutex), so these tests also empirically pin the ORM semantics the
 * guard relies on ('Worker:not' => null == WORKER IS NOT NULL).
 */
class ConcurrencyGuardTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();
        # runJob()'s time/memory parking is irrelevant here (markStarted() is protected and only
        # called via runQueue()); disable both so a single-step stub can never get parked
        QueuedJobService::config()->set('time_limit', 0);
        QueuedJobService::config()->set('memory_limit', 0);
    }

    private function service(): EnhancedQueuedJobService
    {
        $service = Injector::inst()->create(EnhancedQueuedJobService::class);
        # Injector `properties:` are registered on the QueuedJobService service NAME, not on this
        # subclass FQCN — wire the queue handler ourselves (mirrors symbiote's own default spec)
        $service->queueHandler = Injector::inst()->get('QueueHandler');
        return $service;
    }

    /**
     * Create a sibling descriptor directly in a given state (simulating a concurrent run
     * owned by another process/DB session).
     */
    private function sibling(string $implementation, string $status, ?string $worker = null): QueuedJobDescriptor
    {
        $descriptor = QueuedJobDescriptor::create();
        $descriptor->Implementation = $implementation;
        $descriptor->JobType = QueuedJob::QUEUED;
        $descriptor->JobStatus = $status;
        $descriptor->JobTitle = 'sibling ' . $implementation;
        $descriptor->Signature = md5($implementation . $status . microtime());
        $descriptor->Worker = $worker;
        $descriptor->write();
        return $descriptor;
    }

    /** Queue a fresh, runnable descriptor via the normal API and return it. */
    private function queued(string $jobClass): QueuedJobDescriptor
    {
        $id = $this->service()->queueJob(new $jobClass());
        return QueuedJobDescriptor::get()->byID($id);
    }

    public function testGuardedJobDefersWhenSiblingIsRunning(): void
    {
        $this->sibling(StubGuardedJob::class, QueuedJob::STATUS_RUN);
        $descriptor = $this->queued(StubGuardedJob::class);

        $before = DBDatetime::now()->getTimestamp();
        $this->service()->processJobQueue(QueuedJob::QUEUED);

        $descriptor = QueuedJobDescriptor::get()->byID($descriptor->ID);
        $this->assertSame(QueuedJob::STATUS_NEW, $descriptor->JobStatus, 'deferred job must stay New (not run, not broken)');
        $this->assertNull($descriptor->Worker, 'deferred job must not keep a Worker claim');
        $this->assertGreaterThan($before, strtotime($descriptor->StartAfter ?? ''), 'StartAfter must be pushed into the future');
        $this->assertSame(0, (int)$descriptor->StepsProcessed, 'no job code may have run');
    }

    public function testGuardedJobDefersWhenSiblingIsMemoryParked(): void
    {
        # a Waiting (memory/time-parked) sibling is HALF-DONE and must still block — this is
        # exactly the cross-process duplicate-writes window the guard exists for
        $this->sibling(StubGuardedJob::class, QueuedJob::STATUS_WAIT, null);
        $descriptor = $this->queued(StubGuardedJob::class);

        # run the specific descriptor: the queue loop would pick the Waiting sibling first
        $this->service()->runJob($descriptor->ID);

        $descriptor = QueuedJobDescriptor::get()->byID($descriptor->ID);
        $this->assertSame(QueuedJob::STATUS_NEW, $descriptor->JobStatus);
        $this->assertNotEmpty($descriptor->StartAfter, 'deferred: StartAfter set');
        $this->assertSame(0, (int)$descriptor->StepsProcessed);
    }

    public function testGuardedJobDefersWhenSiblingHoldsCommittedClaim(): void
    {
        # the claim-then-verify window: sibling still status New but already holds a committed
        # Worker token (it won grabMutex a moment ago and hasn't reached Initialising yet)
        $this->sibling(StubGuardedJob::class, QueuedJob::STATUS_NEW, bin2hex(random_bytes(16)));
        $descriptor = $this->queued(StubGuardedJob::class);

        $this->service()->runJob($descriptor->ID);

        $descriptor = QueuedJobDescriptor::get()->byID($descriptor->ID);
        $this->assertSame(QueuedJob::STATUS_NEW, $descriptor->JobStatus, 'must defer for a Worker-claimed sibling');
        $this->assertSame(0, (int)$descriptor->StepsProcessed);
    }

    public function testGuardedJobRunsWhenNoActiveSibling(): void
    {
        $descriptor = $this->queued(StubGuardedJob::class);

        $this->service()->processJobQueue(QueuedJob::QUEUED);

        $descriptor = QueuedJobDescriptor::get()->byID($descriptor->ID);
        $this->assertSame(QueuedJob::STATUS_COMPLETE, $descriptor->JobStatus, 'no sibling -> runs to completion');
    }

    public function testGuardedJobRunsWhenSiblingsAreInactive(): void
    {
        # Paused/Broken/Complete/Cancelled siblings must NOT block (a paused-broken job must not
        # deadlock the next scheduled run) — not even with a stale Worker token left behind
        $this->sibling(StubGuardedJob::class, QueuedJob::STATUS_PAUSED, bin2hex(random_bytes(16)));
        $this->sibling(StubGuardedJob::class, QueuedJob::STATUS_BROKEN);
        $this->sibling(StubGuardedJob::class, QueuedJob::STATUS_COMPLETE);
        $this->sibling(StubGuardedJob::class, QueuedJob::STATUS_CANCELLED);
        $descriptor = $this->queued(StubGuardedJob::class);

        $this->service()->processJobQueue(QueuedJob::QUEUED);

        $descriptor = QueuedJobDescriptor::get()->byID($descriptor->ID);
        $this->assertSame(QueuedJob::STATUS_COMPLETE, $descriptor->JobStatus, 'inactive siblings must not block');
    }

    public function testUnguardedJobIgnoresRunningSibling(): void
    {
        # opt-in only: a job without prevent_concurrent_runs keeps stock behaviour
        $this->sibling(StubNonDefaultJob::class, QueuedJob::STATUS_RUN);
        $descriptor = $this->queued(StubNonDefaultJob::class);

        $this->service()->processJobQueue(QueuedJob::QUEUED);

        $descriptor = QueuedJobDescriptor::get()->byID($descriptor->ID);
        $this->assertSame(QueuedJob::STATUS_COMPLETE, $descriptor->JobStatus, 'unguarded jobs are unaffected');
    }

    public function testIsGuardedReadsJobClassConfig(): void
    {
        $guard = ConcurrencyGuard::singleton();
        $this->assertTrue($guard->isGuarded($this->sibling(StubGuardedJob::class, QueuedJob::STATUS_NEW)));
        $this->assertFalse($guard->isGuarded($this->sibling(StubNonDefaultJob::class, QueuedJob::STATUS_NEW)));
    }

    public function testDeferPushesStartAfterByConfiguredSeconds(): void
    {
        ConcurrencyGuard::config()->set('defer_seconds', 600);
        $descriptor = $this->sibling(StubGuardedJob::class, QueuedJob::STATUS_NEW);

        ConcurrencyGuard::singleton()->defer($descriptor);

        # defer() persists via its own fresh read — re-fetch to assert the stored value
        $descriptor = QueuedJobDescriptor::get()->byID($descriptor->ID);
        $expected = DBDatetime::now()->getTimestamp() + 600;
        # small tolerance: DBDatetime::now() may tick between defer() and the assertion
        $this->assertEqualsWithDelta($expected, strtotime($descriptor->StartAfter), 2);
    }
}
