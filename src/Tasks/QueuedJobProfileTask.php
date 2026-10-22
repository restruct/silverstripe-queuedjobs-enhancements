<?php

namespace Restruct\QueuedJobsEnhancements\Tasks;

use ReflectionProperty;
use SilverStripe\Control\Director;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Dev\BuildTask;
use SilverStripe\Security\Permission;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;

/**
 * In-process profiler for queued jobs: answers "does this job accumulate, and where?".
 *
 * Drives a job's setup() + process() loop inside a real CLI bootstrap and samples, per process()
 * call: memory usage (+ delta), peak, the real currentStep, the serialized jobData size and the
 * message count — i.e. the per-step cost the descriptor write pays.
 *
 * Call with no `class` to list the queued jobs it can profile. `maxcalls` caps the run (default 500).
 *
 *   Silverstripe 6: sake tasks:qjob-profile --class='My\Jobs\SyncJob' --maxcalls=50
 *                   (symfony/console OPTIONS, so the same spelling works on the CLI and in the
 *                   browser task runner)
 *   Silverstripe 5: sake dev/tasks/qjob-profile class='My\Jobs\SyncJob' maxcalls=50
 *                   web: dev/tasks/qjob-profile?class=My\Jobs\SyncJob&maxcalls=50
 *
 * NB on Silverstripe 5 the arguments differ by context — on the CLI they are SPACE-SEPARATED, not a
 * query string (`?class=…` is silently ignored there, and you just get the job list back).
 *
 * Run it with the ceiling the CRON actually has, or the result means nothing — a job that only
 * survives because *you* ran it with `-d memory_limit=2G` will still die on the scheduled run:
 *
 *   php -d memory_limit=512M vendor/bin/sake tasks:qjob-profile --class=...
 *
 * Reading the output:
 *  - Δmem ≈ 0 across calls  -> the job does NOT leak. Stop hunting for one; if it still OOMs, the
 *    fatal is thrown somewhere else (a single query fetching too many rows, say) — look at the DATA.
 *  - jobData / msgs climbing -> THERE is your accumulator. Both are re-serialised and written to the
 *    DB after EVERY step, so their cost is size x steps — quadratic. An unbounded message log or a
 *    big array parked on the job is the usual culprit (see the BoundedJobMessages trait).
 *
 * NB the reflection: currentStep is a REAL protected property on AbstractQueuedJob, whereas
 * job-specific state lives in jobData behind the magic __get. Mixing the two up silently reads 0 and
 * makes a leaking job look flat — which is exactly the false negative this task exists to prevent.
 *
 * Dev-only: it EXECUTES the job, so it writes whatever the job writes. And CLI or ADMIN only: in
 * dev mode the task runner (dev/tasks) is open to anyone over HTTP, logged in or not, and this
 * task would run any job class with its default constructor for them.
 *
 * Moved here from restruct/silverstripe-admintweaks (Restruct\Silverstripe\AdminTweaks\Dev\
 * QueuedJobProfileTask) in 1.7.0, under the same name; admintweaks disables its own copy when this
 * class exists. The entry point (run() on SS5, execute() + getOptions() on SS6) and
 * getDescription() come from QueuedJobProfileTaskEntryPoint (see CleanupBrokenJobsTaskEntryPoint
 * for why).
 *
 * @config string $segment
 */
class QueuedJobProfileTask extends BuildTask
{
    use QueuedJobProfileTaskEntryPoint;

    /** Result codes of profile(); the same values as Symfony's Command::SUCCESS/FAILURE/INVALID. */
    public const RESULT_SUCCESS = 0;

    public const RESULT_FAILURE = 1;

    public const RESULT_INVALID = 2;

    public const DEFAULT_MAX_CALLS = 500;

    /**
     * Silverstripe 5 URL segment (dev/tasks/qjob-profile). Inert config on Silverstripe 6.
     */
    private static string $segment = 'qjob-profile';

    /**
     * Silverstripe 6 command name (sake tasks:qjob-profile); see CleanupBrokenJobsTask.
     */
    protected static string $commandName = 'qjob-profile';

    /**
     * Shared by both majors' getDescription() (see the entry-point trait).
     */
    public const DESCRIPTION = 'Runs a job in-process and reports per-call memory/step/jobData/message stats, '
        . 'to find what a job accumulates. Dev-only (it really runs the job).';

    public function __construct()
    {
        parent::__construct();
        # Assigned rather than declared: BuildTask::$title is untyped on SS5 and `string` on SS6
        $this->title = 'Profile a queued job (memory + jobData per process() call)';
    }

    /**
     * Version-neutral body of the task.
     *
     * @param string|null $class the job class to profile; empty = list the job classes
     * @param int|null $maxCalls stop after this many process() calls; empty = DEFAULT_MAX_CALLS
     * @param callable $write function (string $line, bool $isError = false): void
     * @return int one of the RESULT_* constants
     */
    public function profile(?string $class, ?int $maxCalls, callable $write): int
    {
        if (!Director::isDev()) {
            $write('Dev-only: this task actually RUNS the job (and writes whatever the job writes).', true);

            return static::RESULT_FAILURE;
        }

        # isDev() alone is not enough: in dev mode dev/tasks lets any visitor run a task over HTTP
        if (!$this->isCli() && !Permission::check('ADMIN')) {
            $write('Run this task from the CLI, or log in as an administrator: it actually RUNS the job.', true);

            return static::RESULT_FAILURE;
        }

        $class = (string) $class;
        if (!$class) {
            $this->listJobClasses($write);

            # No class named is a usage error, not a success.
            return static::RESULT_INVALID;
        }

        if (!class_exists($class) || !is_subclass_of($class, AbstractQueuedJob::class)) {
            $write("Not a queued job class: {$class}", true);
            $write('');
            $this->listJobClasses($write);

            return static::RESULT_INVALID;
        }

        $maxCalls = max(1, (int) ($maxCalls ?: static::DEFAULT_MAX_CALLS));

        $write(sprintf('Profiling %s (php memory_limit=%s, max %d process() calls)', $class, ini_get('memory_limit'), $maxCalls));
        $write('');
        $write(sprintf('%-5s %-24s %-10s %-10s %-10s %-10s %s', 'call', 'phase/step', 'mem', 'delta', 'peak', 'jobData', 'msgs'));

        /** @var AbstractQueuedJob $job */
        $job = new $class();
        $job->setup();

        # currentStep is a REAL protected property (see class docblock) — reading it off jobData
        # would silently return 0 and make any job look like it never progresses.
        $stepProp = new ReflectionProperty(AbstractQueuedJob::class, 'currentStep');
        $stepProp->setAccessible(true);

        $mb = fn($bytes) => number_format($bytes / 1048576, 1) . 'M';
        $prev = memory_get_usage(true);
        $start = microtime(true);
        $call = 0;

        while (!$job->jobFinished() && $call < $maxCalls) {
            $call++;
            $job->process();

            $mem = memory_get_usage(true);
            $data = $job->getJobData();
            $phase = $data->jobData->phase ?? '';

            $write(sprintf('%-5d %-24s %-10s %-10s %-10s %-10s %d',
                $call,
                substr($phase . '/' . $stepProp->getValue($job), 0, 23),
                $mb($mem),
                number_format(($mem - $prev) / 1024, 0) . 'K',
                $mb(memory_get_peak_usage(true)),
                number_format(strlen(serialize($data->jobData)) / 1024, 1) . 'K',
                count((array) $data->messages)
            ));
            $prev = $mem;
        }

        $write('');
        $write(sprintf('%s after %d call(s) in %.1fs - peak %s, final jobData %sK, %d message(s)',
            $job->jobFinished() ? 'COMPLETE' : 'STOPPED (maxcalls reached)',
            $call,
            microtime(true) - $start,
            $mb(memory_get_peak_usage(true)),
            number_format(strlen(serialize($job->getJobData()->jobData)) / 1024, 1),
            count((array) $job->getJobData()->messages)
        ));

        return static::RESULT_SUCCESS;
    }

    /**
     * Whether the task runs on the command line. A method so that a test can pretend to be an HTTP
     * request: Director::is_cli() asks the SAPI, which is always the CLI under PHPUnit.
     */
    protected function isCli(): bool
    {
        return Director::is_cli();
    }

    /**
     * @param callable $write function (string $line, bool $isError = false): void
     */
    protected function listJobClasses(callable $write): void
    {
        $write('Usage: ' . static::usage());
        $write('');
        $write('Queued jobs found:');
        foreach (ClassInfo::subclassesFor(AbstractQueuedJob::class, false) as $jobClass) {
            $write("  - {$jobClass}");
        }
    }
}
