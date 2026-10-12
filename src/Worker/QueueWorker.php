<?php

namespace Restruct\QueuedJobsEnhancements\Worker;

use InvalidArgumentException;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\i18n\i18n;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Security\Security;
use SilverStripe\Subsites\Model\Subsite;
use SilverStripe\Versioned\Versioned;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJobService;
use Throwable;

/**
 * A long-running queue worker, modelled on Laravel's `queue:work`.
 *
 * Each pass takes ONE job via upstream's own QueuedJobService::getNextPendingJob() and runs it
 * through QueuedJobService::runJob(), which takes the per-descriptor mutex (grabMutex()), handles
 * RunAs, nests config and parks the job when memory or time runs out. That is exactly the body of
 * QueuedJobService::processJobQueue()'s loop; the worker runs it one job at a time instead of
 * draining the queue in one call, because the checks Laravel makes between jobs (max jobs, restart
 * signal, SIGTERM, memory) need a point between two jobs to run at. Job selection is not
 * reimplemented here.
 *
 * Version-neutral: the Silverstripe 5 task (QueuedJobsWorkTask) and the Silverstripe 6 sake command
 * (QueueWorkCommand) only parse their options into the array the constructor takes, and hand in a
 * function to write output lines with.
 *
 * Health checks and default jobs are NOT run here: they belong to ProcessJobQueueTask's runQueue(),
 * which the existing cron keeps calling. The worker is an addition to that cron, not a replacement.
 */
class QueueWorker
{
    use Injectable;

    /**
     * Option defaults. Names follow Laravel's `queue:work`.
     */
    public const DEFAULTS = [
        # comma list, highest priority first
        'queue' => 'immediate',
        # seconds to wait when every queue is empty
        'sleep' => 1,
        # seconds; 0 = no limit
        'max-time' => 3600,
        # 0 = no limit
        'max-jobs' => 0,
        # null = the QueuedJobService memory_limit (or PHP's memory_limit when that is unset)
        'memory' => null,
        # run only this descriptor, then exit
        'job' => null,
        # exit as soon as every queue is empty (Laravel's --stop-when-empty)
        'stop-when-empty' => false,
    ];

    /** @var string[] queue type constants (QueuedJob::IMMEDIATE etc), in priority order */
    protected array $queues = [];

    protected float $sleep = 1;

    protected int $maxTime = 0;

    protected int $maxJobs = 0;

    /** @var int bytes; 0 = no limit */
    protected int $memoryLimit = 0;

    protected ?int $jobId = null;

    protected bool $stopWhenEmpty = false;

    /** @var callable function (string $message): void */
    protected $write;

    /** @var int unix timestamp the worker started at */
    protected int $startedAt = 0;

    protected int $jobsRun = 0;

    /** @var string|null the restart token as it was when this worker started */
    protected ?string $restartToken = null;

    /** @var string|null set by handleSignal(); the worker exits at the next point between jobs */
    protected ?string $signalReason = null;

    /**
     * @param array $options see DEFAULTS; unknown keys are ignored
     * @param callable|null $write function (string $message): void
     */
    public function __construct(array $options = [], ?callable $write = null)
    {
        $options = array_merge(static::DEFAULTS, array_filter($options, fn ($value) => $value !== null));

        $this->queues = static::parseQueues((string) $options['queue']);
        $this->sleep = max(0.0, (float) $options['sleep']);
        $this->maxTime = max(0, (int) $options['max-time']);
        $this->maxJobs = max(0, (int) $options['max-jobs']);
        $this->memoryLimit = $options['memory'] !== null && $options['memory'] !== ''
            ? static::parseMemory((string) $options['memory'])
            : static::serviceMemoryLimit();
        $this->jobId = $options['job'] !== null && $options['job'] !== '' ? static::parseJobId((string) $options['job']) : null;
        $this->stopWhenEmpty = (bool) $options['stop-when-empty'];
        $this->write = $write ?: function (string $message): void {
            echo $message . PHP_EOL;
        };
    }

    /**
     * Run until a limit is reached, a restart or stop signal arrives, or (with --job) the one job
     * has run. Returns a process exit code.
     *
     * Entry points must not touch QueuedJobService before calling this (see disableShutdownDrain());
     * anything they need to do with the service, such as adding a log handler, goes in $onServiceReady.
     *
     * @param callable|null $onServiceReady function (QueuedJobService $service): void
     */
    public function run(?callable $onServiceReady = null): int
    {
        # MUST come before the first QueuedJobService::singleton() of this process: see the method
        $this->disableShutdownDrain();

        $service = QueuedJobService::singleton();
        if ($onServiceReady) {
            $onServiceReady($service);
        }
        $this->startedAt = DBDatetime::now()->getTimestamp();
        $this->restartToken = WorkerRestartSignal::currentToken();
        $this->registerSignalHandlers();

        if ($this->jobId !== null) {
            return $this->runSingleJob($service, $this->jobId);
        }

        $this->output(sprintf(
            'Worker started (pid %d) on queue(s) %s',
            getmypid(),
            implode(',', array_map([static::class, 'queueName'], $this->queues))
        ));

        while (true) {
            $reason = $this->stopReason();
            if ($reason !== null) {
                $this->output(sprintf('Worker stopping after %d job(s): %s', $this->jobsRun, $reason));
                return 0;
            }

            # Paused queue: start nothing, but keep checking for stop conditions
            if ($service->isMaintenanceLockActive()) {
                # Nothing can be run, which for --stop-when-empty is the same as an empty queue
                if ($this->stopWhenEmpty) {
                    $this->output(sprintf('Worker stopping after %d job(s): queue paused (maintenance lock)', $this->jobsRun));
                    return 0;
                }
                $this->idle();
                continue;
            }

            $descriptor = $this->nextJob($service);
            if (!$descriptor) {
                if ($this->stopWhenEmpty) {
                    $this->output(sprintf('Worker stopping after %d job(s): queue empty', $this->jobsRun));
                    return 0;
                }
                $this->idle();
                continue;
            }

            $this->runOneJob($service, (int) $descriptor->ID);

            # A job left Waiting was parked by runJob() because memory or time ran out (or because
            # we asked it to stop after its step). It needs a fresh process to continue, so this
            # worker exits; Supervisor starts a new one, which resumes the job first
            # (getNextPendingJob() returns Waiting jobs before New ones).
            if ($this->isWaiting((int) $descriptor->ID)) {
                $this->output(sprintf(
                    'Worker stopping after %d job(s): job #%d was left Waiting (memory/time limit or stop signal)',
                    $this->jobsRun,
                    $descriptor->ID
                ));
                return 0;
            }
        }
    }

    /**
     * --job=N: run that one descriptor, if it may run now, then exit. Used by
     * ImmediateJobRunner's background spawn, so it must never touch any other job.
     */
    protected function runSingleJob(QueuedJobService $service, int $jobId): int
    {
        $descriptor = QueuedJobDescriptor::get()->byID($jobId);
        if (!$descriptor) {
            $this->output(sprintf('Job #%d does not exist.', $jobId));
            return 1;
        }

        # Same rule as every runner: a paused queue starts nothing. The job stays queued.
        if ($service->isMaintenanceLockActive()) {
            $this->output(sprintf('Queue is paused (maintenance lock); job #%d left for later.', $jobId));
            return 0;
        }

        # runJob() itself looks at neither the status nor StartAfter (only getNextPendingJob()
        # does), so both are checked here: a finished, broken or paused job is not restarted, and a
        # job with a future StartAfter is left for the queue runner to pick up when it is due.
        if (!in_array($descriptor->JobStatus, [QueuedJob::STATUS_NEW, QueuedJob::STATUS_WAIT], true)) {
            $this->output(sprintf('Job #%d has status %s; not running it.', $jobId, $descriptor->JobStatus));
            return 0;
        }
        if ($descriptor->StartAfter && strtotime($descriptor->StartAfter) > DBDatetime::now()->getTimestamp()) {
            $this->output(sprintf('Job #%d is not due until %s; left for the queue runner.', $jobId, $descriptor->StartAfter));
            return 0;
        }

        $this->runOneJob($service, $jobId);

        return 0;
    }

    /**
     * Next job over the queues in priority order, using upstream's own selection.
     */
    protected function nextJob(QueuedJobService $service): ?QueuedJobDescriptor
    {
        foreach ($this->queues as $queue) {
            $descriptor = $service->getNextPendingJob($queue);
            if ($descriptor) {
                return $descriptor;
            }
        }

        return null;
    }

    /**
     * Run one job through runJob(), with the state a job may change put back afterwards.
     */
    protected function runOneJob(QueuedJobService $service, int $jobId): void
    {
        # Same as processJobQueue(): clear the subsite so a previous job's subsite cannot leak
        if (class_exists(Subsite::class)) {
            Subsite::changeSubsite(0);
        }

        $originalUser = Security::getCurrentUser();

        # runJob() already nests Config and switches/restores the RunAs user. It does not restore
        # the Versioned reading mode or the locale, which a job may well change, and in a
        # long-lived process that change would carry into every later job.
        $run = function () use ($service, $jobId) {
            return $service->runJob($jobId);
        };
        if (class_exists(Versioned::class)) {
            $inner = $run;
            $run = function () use ($inner) {
                return Versioned::withVersionedMode($inner);
            };
        }

        try {
            i18n::with_locale(i18n::get_locale(), $run);
        } catch (Throwable $e) {
            # runJob() catches what a job throws; this is what is left (e.g. a descriptor deleted
            # under us). Log it and carry on with the next job rather than kill the worker.
            $this->output(sprintf('Job #%d failed to run: %s', $jobId, $e->getMessage()));
        }

        $this->jobsRun++;
        $this->resetState($originalUser);
    }

    /**
     * Put back per-request state between jobs. What runJob() already does is not repeated here.
     */
    protected function resetState($originalUser): void
    {
        # A job may log a member in; the next job must not inherit them
        if (Security::getCurrentUser() !== $originalUser) {
            Security::setCurrentUser($originalUser);
        }

        # Drop the DataObject get_one/get_by_id cache. Besides memory, this matters for
        # correctness: on Silverstripe 5 runJob() reads the descriptor through
        # DataObject::get_by_id(), which caches, so a descriptor read by an earlier job would be
        # served stale to a later one in the same process.
        DataObject::flush_and_destroy_cache();

        gc_collect_cycles();
    }

    /**
     * Why the worker should stop now, or null to carry on. Checked between jobs and while idle.
     */
    protected function stopReason(): ?string
    {
        if ($this->signalReason !== null) {
            return $this->signalReason;
        }
        if ($this->maxJobs > 0 && $this->jobsRun >= $this->maxJobs) {
            return sprintf('max-jobs (%d) reached', $this->maxJobs);
        }
        if ($this->maxTime > 0 && DBDatetime::now()->getTimestamp() - $this->startedAt >= $this->maxTime) {
            return sprintf('max-time (%ds) reached', $this->maxTime);
        }
        # Peak, not current, usage: that is what QueuedJobService::isMemoryTooHigh() compares, so
        # once the peak is over the service limit every later job would be parked after one step.
        if ($this->memoryLimit > 0 && memory_get_peak_usage(false) >= $this->memoryLimit) {
            return sprintf('memory limit (%s) reached', static::humanBytes($this->memoryLimit));
        }
        if (WorkerRestartSignal::currentToken() !== $this->restartToken) {
            return 'restart requested (queuedjobs:restart)';
        }

        return null;
    }

    protected function idle(): void
    {
        if ($this->sleep > 0) {
            # A signal interrupts usleep(), so SIGTERM while idle exits without waiting it out
            usleep((int) round($this->sleep * 1000000));
        }
    }

    protected function isWaiting(int $jobId): bool
    {
        # Fresh read: runJob() may have changed the status in the database
        $status = QueuedJobDescriptor::get()->filter('ID', $jobId)->column('JobStatus');

        return ($status[0] ?? null) === QueuedJob::STATUS_WAIT;
    }

    /**
     * Make sure THIS process does not drain the IMMEDIATE queue when it exits.
     *
     * QueuedJobService's constructor registers onShutdown() on every CLI process while
     * use_shutdown_function is true (the default), and onShutdown() runs processJobQueue(IMMEDIATE):
     * every pending immediate job, not only ours (SSKB queuedjobs §12). For `--job=N` that was
     * "bug 3": a spawn meant for one job ran the whole immediate queue. A worker has no use for the
     * drain either, since it processes its queues itself.
     *
     * The setting is read in the constructor, so it only works while the service has not been
     * instantiated yet in this process; the worker calls this first thing. A registered shutdown
     * function cannot be removed, so if something did instantiate the service earlier, all this
     * can do is say so.
     */
    protected function disableShutdownDrain(): void
    {
        if (static::serviceAlreadyInstantiated()) {
            $this->output(
                'Warning: QueuedJobService was instantiated before the worker started, so its shutdown '
                . 'handler may drain the immediate queue when this process exits.'
            );
        }
        Config::modify()->set(QueuedJobService::class, 'use_shutdown_function', false);
    }

    /**
     * Whether the Injector already holds a QueuedJobService singleton. Read-only reflection on the
     * Injector's private service cache; returns false if that ever changes shape.
     */
    protected static function serviceAlreadyInstantiated(): bool
    {
        try {
            $injector = \SilverStripe\Core\Injector\Injector::inst();
            $property = new \ReflectionProperty($injector, 'serviceCache');
            $property->setAccessible(true);
            $cache = $property->getValue($injector);

            return is_array($cache) && isset($cache[QueuedJobService::class]);
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * SIGTERM/SIGINT/SIGQUIT: finish the current job STEP, then exit. Needs ext-pcntl; without it
     * the signal ends the process the usual way, and the job is recovered by the health check.
     */
    protected function registerSignalHandlers(): void
    {
        if (!function_exists('pcntl_async_signals') || !function_exists('pcntl_signal')) {
            return;
        }
        pcntl_async_signals(true);
        foreach ([SIGTERM, SIGINT, SIGQUIT] as $signal) {
            pcntl_signal($signal, [$this, 'handleSignal']);
        }
    }

    /**
     * Signal handler. Public so pcntl can call it (and tests can).
     */
    public function handleSignal(int $signal): void
    {
        $this->signalReason = sprintf('received signal %d', $signal);

        # Stop the running job after its current step, through upstream's own time-limit path:
        # runJob() checks hasPassedTimeLimit() after every process() call, and when it is true it
        # sets the job to Waiting and releases its lock (releaseJobLock()), so the next worker
        # resumes it at the next step. hasPassedTimeLimit() is `now > startedAt + time_limit`; a
        # NEGATIVE limit makes that true at once whatever startedAt is (it is never in the
        # future). This config change is inside runJob()'s Config::nest(), so it ends with the job.
        # Between jobs there is no job to stop, and the worker simply exits.
        Config::modify()->set(QueuedJobService::class, 'time_limit', -1);
    }

    /**
     * @return string[] queue constants in the given order, without duplicates
     */
    public static function parseQueues(string $queues): array
    {
        $parsed = [];
        foreach (array_filter(array_map('trim', explode(',', $queues))) as $name) {
            $queue = AbstractQueuedJob::getQueue($name);
            if ($queue === null) {
                throw new InvalidArgumentException(sprintf(
                    'Unknown queue "%s"; use immediate, queued or large (or 1, 2, 3).',
                    $name
                ));
            }
            $parsed[] = (string) $queue;
        }
        if (!$parsed) {
            throw new InvalidArgumentException('No queue given.');
        }

        return array_values(array_unique($parsed));
    }

    /**
     * Accepts a bare descriptor ID, or anything ending in "-<ID>" (the "queuedjob-<ID>" trigger-file
     * name ProcessJobQueueTask takes), so the same value works for both.
     */
    public static function parseJobId(string $job): int
    {
        if (!preg_match('#^(?:.*-)?(\d+)$#', trim($job), $matches) || (int) $matches[1] < 1) {
            throw new InvalidArgumentException(sprintf('job must be the ID of a job descriptor, got "%s".', $job));
        }

        return (int) $matches[1];
    }

    /**
     * "512M", "1G", "524288K" or bytes with a "B" suffix. A bare number is MEGABYTES, as with
     * Laravel's --memory. 0 means no limit.
     */
    public static function parseMemory(string $memory): int
    {
        $memory = strtolower(trim($memory));
        if (!preg_match('#^(\d+(?:\.\d+)?)\s*([bkmg]?)$#', $memory, $matches)) {
            throw new InvalidArgumentException(sprintf('memory must be like 512M or 1G, got "%s".', $memory));
        }
        $multiplier = ['b' => 1, 'k' => 1024, 'm' => 1048576, 'g' => 1073741824, '' => 1048576][$matches[2]];

        return (int) round((float) $matches[1] * $multiplier);
    }

    /**
     * The limit QueuedJobService parks jobs at: its memory_limit config, else PHP's memory_limit
     * (mirrors the protected QueuedJobService::getMemoryLimit()). Bytes; 0 = no limit.
     */
    public static function serviceMemoryLimit(): int
    {
        $configured = Config::inst()->get(QueuedJobService::class, 'memory_limit');
        $limit = static::parseIniBytes((string) $configured);
        if ($limit > 0) {
            return $limit;
        }

        return max(0, static::parseIniBytes((string) ini_get('memory_limit')));
    }

    /**
     * php.ini style: a bare number is bytes, -1 is unlimited (returned as 0).
     */
    protected static function parseIniBytes(string $value): int
    {
        $value = strtolower(trim($value));
        if ($value === '' || $value === '-1') {
            return 0;
        }
        $unit = substr($value, -1);
        $number = (float) $value;
        switch ($unit) {
            case 'g':
                return (int) round($number * 1073741824);
            case 'm':
                return (int) round($number * 1048576);
            case 'k':
                return (int) round($number * 1024);
            default:
                return (int) round($number);
        }
    }

    public static function queueName(string $queue): string
    {
        return [
            QueuedJob::IMMEDIATE => 'immediate',
            QueuedJob::QUEUED => 'queued',
            QueuedJob::LARGE => 'large',
        ][$queue] ?? $queue;
    }

    protected static function humanBytes(int $bytes): string
    {
        return sprintf('%.0fM', $bytes / 1048576);
    }

    public function getJobsRun(): int
    {
        return $this->jobsRun;
    }

    protected function output(string $message): void
    {
        ($this->write)($message);
    }
}
