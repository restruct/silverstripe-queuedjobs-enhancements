<?php

namespace Restruct\QueuedJobsEnhancements\Tests\Worker;

use Restruct\QueuedJobsEnhancements\Services\DrainSpawner;
use Restruct\QueuedJobsEnhancements\Tests\Stubs\StubMultiStepJob;
use Restruct\QueuedJobsEnhancements\Tests\Stubs\StubNonDefaultJob;
use Restruct\QueuedJobsEnhancements\Services\ImmediateJobRunner;
use Restruct\QueuedJobsEnhancements\Tests\Services\RecordingDrainSpawner;
use Restruct\QueuedJobsEnhancements\Tests\Services\RecordingStartSpawner;
use SilverStripe\Core\Injector\Injector;
use Restruct\QueuedJobsEnhancements\Tests\Stubs\StubWorkerHooks;
use Restruct\QueuedJobsEnhancements\Worker\QueueWorker;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\FieldType\DBDatetime;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Symbiote\QueuedJobs\Services\QueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJobService;

/**
 * The runner's `requested` mode (the start request lane of DrainSpawner::requestStart()), run
 * in-process against the test database. Requests are written with writeStartRequest() directly, so
 * no spawn is involved (a test database never spawns anyway).
 */
class QueueWorkerRequestedTest extends SapphireTest
{
    protected $usesDatabase = true;

    /** @var string[] lines the worker wrote */
    protected array $output = [];

    protected function setUp(): void
    {
        parent::setUp();
        StubWorkerHooks::reset();
        $this->output = [];
        Config::modify()->set(QueuedJobService::class, 'time_limit', 0);
        $this->clearRequests();
    }

    protected function tearDown(): void
    {
        $this->clearRequests();
        StubWorkerHooks::reset();
        parent::tearDown();
    }

    protected function clearRequests(): void
    {
        foreach (glob(DrainSpawner::singleton()->startRequestFolder() . '/*') ?: [] as $file) {
            @unlink($file);
        }
    }

    protected function worker(array $options = []): TestQueueWorker
    {
        return TestQueueWorker::create(
            array_merge(['requested' => true, 'linger' => 0], $options),
            function (string $message): void {
                $this->output[] = $message;
            }
        );
    }

    protected function request(int $id): void
    {
        $this->assertTrue(DrainSpawner::singleton()->writeStartRequest($id));
    }

    protected function requestExists(int $id): bool
    {
        return is_file(DrainSpawner::singleton()->startRequestFolder() . '/' . $id);
    }

    protected function jobStatus(int $id): string
    {
        return (string) QueuedJobDescriptor::get()->filter('ID', $id)->column('JobStatus')[0];
    }

    protected function queue($job, ?string $startAfter = null): int
    {
        return QueuedJobService::singleton()->queueJob($job, $startAfter);
    }

    /**
     * Only the requested job runs; another due job on the same queue keeps waiting for its cron.
     */
    public function testRunsOnlyTheRequestedJobs(): void
    {
        $asked = $this->queue(new StubMultiStepJob('asked', QueuedJob::QUEUED));
        $other = $this->queue(new StubMultiStepJob('other', QueuedJob::QUEUED));
        $this->request($asked);

        $exit = $this->worker()->run();

        $this->assertSame(0, $exit);
        $this->assertSame(['asked#1', 'asked#2', 'asked#3'], StubWorkerHooks::$ran);
        $this->assertSame(QueuedJob::STATUS_COMPLETE, $this->jobStatus($asked));
        $this->assertSame(QueuedJob::STATUS_NEW, $this->jobStatus($other));
        $this->assertSame(QueuedJob::QUEUED, QueuedJobDescriptor::get()->byID($asked)->JobType, 'it ran from its own queue');
        $this->assertFalse($this->requestExists($asked), 'the request is gone once the job ran');
        $this->assertStringContainsString('on requested jobs', implode("\n", $this->output));
    }

    /**
     * mic's case (2026-10-22): one CMS button queues two jobs with queueAndStart() (STUDENT and
     * INSTRUCTOR) while the same job class is also queued normally elsewhere. Only the two are
     * requested, and one runner runs them in series (oldest first); the plain one stays New for
     * the cron.
     */
    public function testTwoQueueAndStartJobsRunAndAPlainOneOfTheSameClassWaits(): void
    {
        # The recorder: acts as if this were not a test database, records the spawn instead of running it
        $spawner = new RecordingDrainSpawner();
        Injector::inst()->registerService($spawner, DrainSpawner::class);
        RecordingStartSpawner::resetStarts();

        $student = ImmediateJobRunner::queueAndStart(new StubMultiStepJob('STUDENT', QueuedJob::QUEUED));
        $instructor = ImmediateJobRunner::queueAndStart(new StubMultiStepJob('INSTRUCTOR', QueuedJob::QUEUED));
        $plain = $this->queue(new StubMultiStepJob('plain', QueuedJob::QUEUED));

        $this->assertTrue($this->requestExists($student));
        $this->assertTrue($this->requestExists($instructor));
        $this->assertFalse($this->requestExists($plain));
        $this->assertCount(2, $spawner->commands, 'a dispatch per queueAndStart(); the wrapper lock lets one runner through');

        $exit = $this->worker()->run();

        $this->assertSame(0, $exit);
        $this->assertSame(
            ['STUDENT#1', 'STUDENT#2', 'STUDENT#3', 'INSTRUCTOR#1', 'INSTRUCTOR#2', 'INSTRUCTOR#3'],
            StubWorkerHooks::$ran,
            'in series, in the order they were queued'
        );
        $this->assertSame(QueuedJob::STATUS_COMPLETE, $this->jobStatus($student));
        $this->assertSame(QueuedJob::STATUS_COMPLETE, $this->jobStatus($instructor));
        $this->assertSame(QueuedJob::STATUS_NEW, $this->jobStatus($plain), 'left for the cron');
        RecordingStartSpawner::resetStarts();
    }

    public function testRunsRequestsOldestFirst(): void
    {
        $first = $this->queue(new StubMultiStepJob('first', QueuedJob::QUEUED));
        $second = $this->queue(new StubMultiStepJob('second', QueuedJob::QUEUED));
        $this->request($second);
        $this->request($first);

        $this->worker()->run();

        $this->assertSame(['first#1', 'first#2', 'first#3', 'second#1', 'second#2', 'second#3'], StubWorkerHooks::$ran);
    }

    /**
     * A request without a runnable job is dropped without running anything: no descriptor, a job
     * already finished, one that runs elsewhere, one that is not due.
     */
    public function testStaleRequestsAreDropped(): void
    {
        $done = $this->queue(new StubMultiStepJob('done', QueuedJob::QUEUED));
        $running = $this->queue(new StubMultiStepJob('running', QueuedJob::QUEUED));
        $later = $this->queue(
            new StubMultiStepJob('later', QueuedJob::QUEUED),
            date('Y-m-d H:i:s', DBDatetime::now()->getTimestamp() + 3600)
        );
        foreach ([$done => QueuedJob::STATUS_COMPLETE, $running => QueuedJob::STATUS_RUN] as $id => $status) {
            $descriptor = QueuedJobDescriptor::get()->byID($id);
            $descriptor->JobStatus = $status;
            $descriptor->write();
        }
        foreach ([$done, $running, $later, 999999] as $id) {
            $this->request($id);
        }

        $exit = $this->worker()->run();

        $this->assertSame(0, $exit);
        $this->assertSame([], StubWorkerHooks::$ran);
        foreach ([$done, $running, $later, 999999] as $id) {
            $this->assertFalse($this->requestExists($id), "request {$id} dropped");
        }
        $this->assertSame(QueuedJob::STATUS_NEW, $this->jobStatus($later), 'the future job stays queued for the cron');
    }

    /**
     * A requested job parked as Waiting (here by a stop signal after its first step) keeps its
     * request; the next runner resumes it and only then drops the request.
     */
    public function testAParkedJobKeepsItsRequestUntilItIsResumed(): void
    {
        $id = $this->queue(new StubMultiStepJob('long', QueuedJob::QUEUED));
        $this->request($id);

        $worker = $this->worker();
        StubWorkerHooks::$onStep = function (string $label, int $step) use ($worker): void {
            if ($step === 1) {
                $worker->handleSignal(SIGTERM);
            }
        };
        $this->assertSame(0, $worker->run(), 'a stop signal exits 0');
        $this->assertSame(QueuedJob::STATUS_WAIT, $this->jobStatus($id));
        $this->assertTrue($this->requestExists($id), 'kept for the next runner');

        StubWorkerHooks::$onStep = null;
        Config::modify()->set(QueuedJobService::class, 'time_limit', 0);
        $this->worker()->run();

        $this->assertSame(['long#1', 'long#2', 'long#3'], StubWorkerHooks::$ran);
        $this->assertSame(QueuedJob::STATUS_COMPLETE, $this->jobStatus($id));
        $this->assertFalse($this->requestExists($id));
    }

    /**
     * Other files in the folder are not requests, and are left alone.
     */
    public function testIgnoresFilesThatAreNotRequests(): void
    {
        $stray = DrainSpawner::singleton()->startRequestFolder() . '/notes.txt';
        file_put_contents($stray, 'x');

        $this->assertSame(0, $this->worker()->run());
        $this->assertFileExists($stray);
        @unlink($stray);
    }

    /**
     * The runner never runs upstream's health check: a Running job whose lock has expired and that
     * made no step since the last check is left alone (its queue's cron decides about it).
     */
    public function testDoesNotRunTheHealthCheck(): void
    {
        $stalled = $this->queue(new StubNonDefaultJob());
        $descriptor = QueuedJobDescriptor::get()->byID($stalled);
        $descriptor->JobStatus = QueuedJob::STATUS_RUN;
        $descriptor->StepsProcessed = 1;
        $descriptor->LastProcessedCount = 1;
        $descriptor->Expiry = date('Y-m-d H:i:s', DBDatetime::now()->getTimestamp() - 3600);
        $descriptor->write();
        $asked = $this->queue(new StubMultiStepJob('asked', QueuedJob::QUEUED));
        $this->request($asked);

        $this->worker()->run();

        $this->assertSame(QueuedJob::STATUS_COMPLETE, $this->jobStatus($asked));
        $this->assertSame(QueuedJob::STATUS_RUN, $this->jobStatus($stalled), 'not restarted by the runner');
    }

    public function testParsesTheFlagFromBothEntryPoints(): void
    {
        foreach ([true, '1', 'true'] as $on) {
            $worker = new class(['requested' => $on]) extends QueueWorker {
                public function isRequested(): bool
                {
                    return $this->requested;
                }
            };
            $this->assertTrue($worker->isRequested(), var_export($on, true));
        }
        foreach ([false, '0', '', null] as $off) {
            $worker = new class(['requested' => $off]) extends QueueWorker {
                public function isRequested(): bool
                {
                    return $this->requested;
                }
            };
            $this->assertFalse($worker->isRequested(), var_export($off, true));
        }
    }
}
