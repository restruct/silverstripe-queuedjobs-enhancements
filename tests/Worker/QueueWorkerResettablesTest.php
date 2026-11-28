<?php

namespace Restruct\QueuedJobsEnhancements\Tests\Worker;

use Restruct\QueuedJobsEnhancements\Tests\Stubs\StubResettableCache;
use Restruct\QueuedJobsEnhancements\Tests\Stubs\StubResettableCacheJob;
use Restruct\QueuedJobsEnhancements\Tests\Stubs\StubSignallessQueueWorker;
use Restruct\QueuedJobsEnhancements\Tests\Stubs\StubThrowingFirstQueueWorker;
use Restruct\QueuedJobsEnhancements\Tests\Stubs\StubThrowingResettable;
use Restruct\QueuedJobsEnhancements\Tests\Stubs\StubWorkerHooks;
use Restruct\QueuedJobsEnhancements\Worker\QueueWorker;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use Symbiote\QueuedJobs\Services\QueuedJobService;

/**
 * QueueWorker.reset_resettables_between_jobs (#11): with the flag on, the runner calls reset() on
 * every Resettable between two jobs, as FlushableTestState does between two tests, so a service's
 * in-memory cache cannot carry from one job into the next. Off (the default), nothing changes.
 */
class QueueWorkerResettablesTest extends SapphireTest
{
    protected $usesDatabase = true;

    /** @var string[] lines the worker wrote */
    protected array $output = [];

    protected function setUp(): void
    {
        parent::setUp();
        StubWorkerHooks::reset();
        StubThrowingResettable::$armed = false;
        StubThrowingResettable::$attempts = 0;
        # FlushableTestState already reset it in parent::setUp(); start the counter from zero
        StubResettableCache::$resets = 0;
        $this->output = [];
        Config::modify()->set(QueuedJobService::class, 'time_limit', 0);
    }

    protected function tearDown(): void
    {
        # Disarm before anything else: the next test's FlushableTestState calls reset() on it
        StubThrowingResettable::$armed = false;
        StubWorkerHooks::reset();
        StubResettableCache::$value = null;
        parent::tearDown();
    }

    protected function runTwoJobs(string $workerClass = StubSignallessQueueWorker::class): int
    {
        QueuedJobService::singleton()->queueJob(new StubResettableCacheJob('a'));
        QueuedJobService::singleton()->queueJob(new StubResettableCacheJob('b'));

        # linger 0 so the run exits as soon as the queue is empty (see QueueWorkerTest::worker())
        return $workerClass::create(['linger' => 0], function (string $message): void {
            $this->output[] = $message;
        })->run();
    }

    public function testFlagIsOffByDefault(): void
    {
        $this->assertFalse((bool) Config::inst()->get(QueueWorker::class, 'reset_resettables_between_jobs'));
    }

    /**
     * The leak the flag exists for, and the 1.x default kept: with the flag off, job b still sees
     * what job a cached.
     */
    public function testFlagOffKeepsStateBetweenJobs(): void
    {
        $this->runTwoJobs();

        $this->assertSame(['a:saw=-', 'b:saw=a'], StubWorkerHooks::$ran);
        $this->assertSame(0, StubResettableCache::$resets, 'nothing may call reset() with the flag off');
    }

    public function testFlagOnResetsStateBetweenJobs(): void
    {
        Config::modify()->set(QueueWorker::class, 'reset_resettables_between_jobs', true);

        $exit = $this->runTwoJobs();

        $this->assertSame(0, $exit);
        $this->assertSame(['a:saw=-', 'b:saw=-'], StubWorkerHooks::$ran);
        # Once after each job
        $this->assertSame(2, StubResettableCache::$resets);
    }

    public function testSkipListKeepsAnImplementorsState(): void
    {
        Config::modify()->set(QueueWorker::class, 'reset_resettables_between_jobs', true);
        Config::modify()->merge(QueueWorker::class, 'resettables_skip', [StubResettableCache::class]);

        $this->runTwoJobs();

        $this->assertSame(['a:saw=-', 'b:saw=a'], StubWorkerHooks::$ran);
        $this->assertSame(0, StubResettableCache::$resets);
    }

    /**
     * A skip entry in the `Class: false` form takes a class off the list again, which is how a
     * project resets a class the module skips by default (config arrays merge, so a list entry
     * cannot be removed from YAML).
     */
    public function testSkipEntrySetToFalseIsNotSkipped(): void
    {
        Config::modify()->set(QueueWorker::class, 'reset_resettables_between_jobs', true);
        Config::modify()->set(QueueWorker::class, 'resettables_skip', [
            StubResettableCache::class,
            StubResettableCache::class => false,
        ]);

        $this->runTwoJobs();

        $this->assertSame(['a:saw=-', 'b:saw=-'], StubWorkerHooks::$ran);
    }

    /**
     * One reset() that throws is reported and does not stop the runner, nor the resets after it.
     * StubThrowingFirstQueueWorker puts the throwing stub first, so "the rest" really comes after it.
     */
    public function testThrowingImplementorIsLoggedAndTheRestStillReset(): void
    {
        Config::modify()->set(QueueWorker::class, 'reset_resettables_between_jobs', true);
        StubThrowingResettable::$armed = true;

        $exit = $this->runTwoJobs(StubThrowingFirstQueueWorker::class);

        $this->assertSame(0, $exit);
        $this->assertSame(['a:saw=-', 'b:saw=-'], StubWorkerHooks::$ran, 'both jobs must run');
        $this->assertSame(2, StubThrowingResettable::$attempts);
        $this->assertSame(2, StubResettableCache::$resets, 'the implementors after the throwing one must still reset');
        $output = implode("\n", $this->output);
        $this->assertStringContainsString(StubThrowingResettable::class, $output);
        $this->assertStringContainsString('stub reset() failure', $output);
    }

    /**
     * Versioned is skipped by default: the runner already puts the reading mode back after each job,
     * and Versioned::reset() would clear it (and needs a current controller, which a Silverstripe 6
     * sake command does not have).
     */
    public function testVersionedIsSkippedByDefault(): void
    {
        if (!class_exists(Versioned::class)) {
            $this->markTestSkipped('silverstripe/versioned is not installed');
        }
        Config::modify()->set(QueueWorker::class, 'reset_resettables_between_jobs', true);
        Versioned::set_stage(Versioned::DRAFT);

        $this->runTwoJobs();

        $this->assertSame('Stage.' . Versioned::DRAFT, Versioned::get_reading_mode());
    }
}

