<?php

namespace Restruct\QueuedJobsEnhancements\Worker;

use InvalidArgumentException;
use Restruct\QueuedJobsEnhancements\Services\ImmediateJobRunner;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Config\Configurable;
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
 * A one-shot draining queue runner, modelled on Laravel's `queue:work --stop-when-empty`: it runs
 * the due jobs of the given queue(s) in series and exits when they are empty (after a short linger,
 * see $linger). It is the drain runner that bin/qje-drain.sh starts for the immediate queue (see
 * DrainSpawner). Since 1.7.0 this is its only mode: the resident (long-running, polling) worker
 * mode of 1.6.0, its --sleep option and its restart signal (queuedjobs:restart /
 * QueuedJobsRestartTask, WorkerRestartSignal) were removed, and so was the single-job --job mode
 * that ImmediateJobRunner's per-job spawn used up to 1.6.0.
 *
 * Each pass takes ONE job via upstream's own QueuedJobService::getNextPendingJob() and runs it
 * through QueuedJobService::runJob(), which takes the per-descriptor mutex (grabMutex()), handles
 * RunAs, nests config and parks the job when memory or time runs out. That is exactly the body of
 * QueuedJobService::processJobQueue()'s loop; the worker runs it one job at a time instead of
 * draining the queue in one call, because the checks Laravel makes between jobs (max jobs, SIGTERM,
 * memory) need a point between two jobs to run at. Job selection is not
 * reimplemented here.
 *
 * Version-neutral: the Silverstripe 5 task (QueuedJobsWorkTask) and the Silverstripe 6 sake command
 * (QueueWorkCommand) only parse their options into the array the constructor takes, and hand in a
 * function to write output lines with.
 *
 * The exit code tells the wrapper whether to start another runner: 0 when there is nothing left for
 * this runner (queue empty after the linger, queue paused, or it stopped before running any job),
 * EXIT_MORE_WORK when it stopped early after running jobs, with work possibly left (max-jobs,
 * max-time, memory, or a job parked as Waiting). A stop signal (SIGTERM/SIGINT/SIGQUIT) exits 0:
 * the runner was asked to go away.
 *
 * After each job it ran, the runner deletes upstream's trigger file `queuedjob-<ID>` of that job
 * (see deleteTriggerFile()).
 *
 * Health checks and default jobs are NOT run here: they belong to ProcessJobQueueTask's runQueue(),
 * which the existing cron keeps calling. The worker is an addition to that cron, not a replacement.
 *
 * @config int|float $linger
 */
class QueueWorker
{
    use Configurable;
    use Injectable;

    /**
     * Exit code of a one-shot run that stopped early with work possibly left: sysexits' EX_TEMPFAIL.
     * bin/qje-drain.sh starts a new runner when it sees it.
     */
    public const EXIT_MORE_WORK = 75;

    /**
     * Seconds between two looks at the queue while lingering (see $linger).
     */
    public const LINGER_INTERVAL = 0.5;

    /**
     * After the queue is found empty, keep looking for a new due job every
     * LINGER_INTERVAL seconds for this many seconds before exiting. A job dispatched while the runner
     * is about to exit is then run by this process instead of costing a new boot. 0 disables it.
     * The `linger` option of the task/command overrides this.
     */
    private static $linger = 2;

    /**
     * Option defaults. Names follow Laravel's `queue:work`.
     */
    public const DEFAULTS = [
        # comma list, highest priority first
        'queue' => 'immediate',
        // # seconds to wait when every queue is empty
        // 'sleep' => 1,  // old: the resident worker's polling interval, removed in 1.7.0
        # seconds; 0 = no limit
        'max-time' => 3600,
        # 0 = no limit
        'max-jobs' => 0,
        # null = the QueuedJobService memory_limit (or PHP's memory_limit when that is unset)
        'memory' => null,
        // # run only this descriptor, then exit (used by ImmediateJobRunner's deprecated spawn helpers)
        // 'job' => null,  // old: the --job mode of the per-job spawn, removed in 1.7.0
        // # exit as soon as every queue is empty (Laravel's --stop-when-empty)
        // 'stop-when-empty' => false,  // old: since 1.7.0 stopping when empty is the only behaviour;
        // the entry points still accept the flag, because bin/qje-drain.sh passes it
        # seconds to keep looking for new jobs before exiting when the queue is empty.
        # null = the $linger config (default 2); 0 = exit at once
        'linger' => null,
    ];

    /** @var string[] queue type constants (QueuedJob::IMMEDIATE etc), in priority order */
    protected array $queues = [];

    protected int $maxTime = 0;

    protected int $maxJobs = 0;

    /** @var int bytes; 0 = no limit */
    protected int $memoryLimit = 0;

    /** @var float seconds; see $linger */
    protected float $lingerSeconds = 0;

    /** @var callable function (string $message): void */
    protected $write;

    /** @var int unix timestamp the worker started at */
    protected int $startedAt = 0;

    protected int $jobsRun = 0;

    /** @var string|null set by handleSignal(); the worker exits at the next point between jobs */
    protected ?string $signalReason = null;

    /**
     * @param array $options see DEFAULTS; unknown keys (such as the removed sleep, job and
     *   stop-when-empty) are ignored
     * @param callable|null $write function (string $message): void
     */
    public function __construct(array $options = [], ?callable $write = null)
    {
        $options = array_merge(static::DEFAULTS, array_filter($options, fn ($value) => $value !== null));

        $this->queues = static::parseQueues((string) $options['queue']);
        $this->maxTime = max(0, (int) $options['max-time']);
        $this->maxJobs = max(0, (int) $options['max-jobs']);
        $this->memoryLimit = $options['memory'] !== null && $options['memory'] !== ''
            ? static::parseMemory((string) $options['memory'])
            : static::serviceMemoryLimit();
        $linger = $options['linger'] !== null && $options['linger'] !== ''
            ? $options['linger']
            : static::config()->get('linger');
        $this->lingerSeconds = max(0.0, (float) $linger);
        $this->write = $write ?: function (string $message): void {
            echo $message . PHP_EOL;
        };
    }

    /**
     * Run until the queues are empty (after the linger), a limit is reached or a stop signal
     * arrives. Returns a process exit code (see the class doc).
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
        $this->registerSignalHandlers();

        $this->output(sprintf(
            'Worker started (pid %d) on queue(s) %s',
            getmypid(),
            implode(',', array_map([static::class, 'queueName'], $this->queues))
        ));

        # Microtime, not DBDatetime: a mocked "now" in tests would never move on and linger forever
        $lingerUntil = null;

        while (true) {
            $reason = $this->stopReason();
            if ($reason !== null) {
                $this->output(sprintf('Worker stopping after %d job(s): %s', $this->jobsRun, $reason));
                // return 0;  // old: every stop was 0, so the drain wrapper could not tell an early stop
                return $this->earlyStopExitCode();
            }

            # Paused queue: start nothing. Nothing can be run, which is the same as an empty queue;
            # the jobs stay queued for the next dispatch or the cron once the queue is unpaused.
            if ($service->isMaintenanceLockActive()) {
                $this->output(sprintf('Worker stopping after %d job(s): queue paused (maintenance lock)', $this->jobsRun));
                return 0;
            }

            $descriptor = $this->nextJob($service);
            if (!$descriptor) {
                # Linger: a job queued just after the empty check above would otherwise wait
                # for a new runner (a full boot), or be missed if its dispatch found this
                # runner's lock still held. Short sleeps, so a due job starts within
                # LINGER_INTERVAL. stopReason() is checked at the top of every pass, so a
                # signal or max-time still ends the linger.
                $now = microtime(true);
                if ($lingerUntil === null) {
                    $lingerUntil = $now + $this->lingerSeconds;
                }
                if ($now < $lingerUntil) {
                    usleep((int) round(min(static::LINGER_INTERVAL, $lingerUntil - $now) * 1000000));
                    continue;
                }
                $this->output(sprintf('Worker stopping after %d job(s): queue empty', $this->jobsRun));
                return 0;
            }
            # Found work: the next empty queue starts a fresh linger
            $lingerUntil = null;

            $this->runOneJob($service, (int) $descriptor->ID);

            # A job left Waiting was parked by runJob() because memory or time ran out (or because
            # we asked it to stop after its step). It needs a fresh process to continue, so this
            # runner exits; on EXIT_MORE_WORK the drain wrapper starts a new one, which resumes the
            # job first (getNextPendingJob() returns Waiting jobs before New ones).
            if ($this->isWaiting((int) $descriptor->ID)) {
                $this->output(sprintf(
                    'Worker stopping after %d job(s): job #%d was left Waiting (memory/time limit or stop signal)',
                    $this->jobsRun,
                    $descriptor->ID
                ));
                // return 0;  // old: the drain wrapper could not tell this from an empty queue
                return $this->earlyStopExitCode();
            }
        }
    }

    /**
     * Exit code for a stop with work possibly left (a limit or a parked job; a stop signal always
     * exits 0). EXIT_MORE_WORK only when this run got some work done: a
     * runner that stops before its first job (e.g. a memory limit already passed at boot) would stop
     * the same way every time, and the drain wrapper would start runners in a tight loop. Its jobs
     * stay queued for the next dispatch or the cron.
     */
    protected function earlyStopExitCode(): int
    {
        # A stop signal means someone wants this runner gone (a deploy, a manual stop, Supervisor).
        # EXIT_MORE_WORK would make the drain wrapper start a new runner at once, possibly from an
        # old release's paths. A job the signal left Waiting is run by the next dispatch or the cron.
        if ($this->signalReason !== null) {
            return 0;
        }
        // if ($this->stopWhenEmpty && $this->jobsRun > 0) {  // old: a stop signal also returned EXIT_MORE_WORK
        // if ($this->stopWhenEmpty && $this->jobsRun > 0) {  // old: the resident mode exited 0 at its limits
        if ($this->jobsRun > 0) {
            return static::EXIT_MORE_WORK;
        }

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

        # Whatever the outcome (complete, broken, or parked Waiting), see the method
        $this->deleteTriggerFile($jobId);

        $this->jobsRun++;
        $this->resetState($originalUser);
    }

    /**
     * Delete upstream's trigger file `<getJobDir()>/queuedjob-<ID>` of a job this runner has run.
     *
     * QueuedJobDescriptor::activateOnQueue() writes it for every IMMEDIATE job while
     * QueuedJobService.use_shutdown_function is false, for an inotify watcher to react to, and
     * upstream only deletes it when the job completes (cleanupJob()). The drain spawn does not need
     * the file, and a broken job's file would stay behind for good (a parked one's until the job
     * completes). So it is deleted after every run, whatever the outcome: a job parked as Waiting is
     * found again in the DATABASE by the next runner (getNextPendingJob()), never through its file.
     * Files of jobs this runner did not run are left alone.
     *
     * Quiet: a missing file is normal (with use_shutdown_function true none is written), and a
     * failure here must never stop the runner.
     */
    protected function deleteTriggerFile(int $jobId): void
    {
        try {
            @unlink(ImmediateJobRunner::triggerFolder() . '/queuedjob-' . $jobId);
        } catch (Throwable $e) {
            # e.g. the job folder cannot be created; the file, if any, is harmless
        }
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
     * Why the worker should stop now, or null to carry on. Checked between jobs and while lingering.
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

        return null;
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
     * every pending immediate job, not only ours (upstream QueuedJobService::__construct() and
     * onShutdown()). For the `--job=N` mode of
     * the per-job spawn (removed in 1.7.0) that was "bug 3": a spawn meant for one job ran the whole
     * immediate queue. A worker has no use for the drain, since it processes its queues itself.
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
