<?php

namespace Restruct\QueuedJobsEnhancements\Services;

use Psr\Log\LoggerInterface;
use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use Symbiote\QueuedJobs\Services\QueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJobService;

/**
 * Queue a job AND trigger immediate execution — no waiting for cron.
 *
 * Detection order:
 *   1. If `use_immediate_queue` config is true → queue as QueuedJob::IMMEDIATE
 *      (for projects with inotifywait set up, which handles sub-second execution)
 *   2. If `exec()` available and not CLI → spawn background PHP process
 *   3. Fallback → job stays in queue, cron picks it up within 60s
 *
 * The job is ALWAYS queued first as a safety net. The spawn is best-effort.
 * This class never replaces or subclasses core QueuedJobs classes.
 *
 * @config bool $use_immediate_queue
 */
class ImmediateJobRunner
{
    use Configurable;
    use Injectable;

    /**
     * Set true if inotifywait (or similar) is monitoring the IMMEDIATE queue.
     * When true, jobs are queued as QueuedJob::IMMEDIATE instead of spawning a CLI process.
     */
    private static bool $use_immediate_queue = false;

    /**
     * Queue a job and trigger immediate execution.
     *
     * @return int The QueuedJobDescriptor ID
     */
    public static function queueAndRun(QueuedJob $job, ?string $startAfter = null): int
    {
        $service = QueuedJobService::singleton();

        # If inotifywait is set up, use IMMEDIATE queue — the existing
        # infrastructure handles sub-second execution already
        if (static::singleton()->config()->get('use_immediate_queue')) {
            # queueJob() is ($job, $startAfter, $userId, $queueName) — the queue override is
            # the FOURTH param. Passing it third assigned the queue constant to $userId, which
            # (a) left JobType at $job->getJobType() so the IMMEDIATE override never applied, and
            # (b) forced RunAsID to Member ID 1, bypassing the job's own getRunAsMemberID().
            // return $service->queueJob($job, $startAfter, QueuedJob::IMMEDIATE);  // old: queue landed in $userId
            $descriptorId = $service->queueJob($job, $startAfter, null, QueuedJob::IMMEDIATE);

            # The trigger file that inotifywait watches is only written when BOTH
            # JobType === IMMEDIATE *and* QueuedJobService.use_shutdown_function is false
            # (see QueuedJobDescriptor::activateOnQueue()). That config defaults to TRUE, so
            # without this warning the job silently degrades to "picked up on the next cron
            # tick" — slower than the CLI-spawn path this option replaced.
            if (Config::inst()->get(QueuedJobService::class, 'use_shutdown_function')) {
                static::log(sprintf(
                    'use_immediate_queue is on, but QueuedJobService.use_shutdown_function is still true, '
                    . 'so no trigger file was written for job #%d and no watcher can pick it up. '
                    . 'Set use_shutdown_function: false and run an inotifywait watcher, '
                    . 'or turn off use_immediate_queue to fall back to CLI spawn.',
                    $descriptorId
                ));
            }

            return $descriptorId;
        }

        # Queue normally first (safety net — cron picks it up if spawn fails)
        $descriptorId = $service->queueJob($job, $startAfter);

        # Spawn background CLI process to run this specific job
        static::spawnJobProcess($descriptorId);

        return $descriptorId;
    }

    /**
     * Spawn a background CLI process to execute a specific queued job.
     * Best-effort: logs warning on failure, job stays queued for cron.
     */
    protected static function spawnJobProcess(int $descriptorId): void
    {
        # Don't spawn from CLI — we're likely already in a job runner
        if (Director::is_cli()) {
            return;
        }

        # exec() must be available (not disabled in php.ini)
        if (!function_exists('exec')) {
            return;
        }

        # Use cli-script.php directly — more reliable than sake (see SSKB cli-commands profile)
        $cliScript = BASE_PATH . '/vendor/silverstripe/framework/cli-script.php';
        if (!file_exists($cliScript)) {
            static::log("Cannot spawn job #{$descriptorId}: cli-script.php not found at {$cliScript}");
            return;
        }

        # Find the PHP binary — use PHP_BINARY which gives us the exact binary running the current request
        $phpBinary = PHP_BINARY ?: 'php';

        # Build command: nohup detaches from parent, /dev/null discards output, & runs in background
        #
        # The "queuedjob-" prefix is REQUIRED. ProcessJobQueueTask only enters single-job mode
        # when the job param contains a dash — `if (($job = $request->getVar('job')) && strpos($job, '-'))`
        # — taking the part after the dash as the descriptor ID. This matches the `queuedjob-{ID}`
        # trigger-file names inotifywait passes as `%f`. A bare `job=42` fails that check and falls
        # through to runQueue('Queued'), draining the ENTIRE queued queue instead of this one job
        # (and missing the job entirely if it is a LARGE or IMMEDIATE job).
        $cmd = sprintf(
            // 'nohup %s %s dev/tasks/ProcessJobQueueTask job=%d > /dev/null 2>&1 &',  // old: bare ID, no dash
            'nohup %s %s dev/tasks/ProcessJobQueueTask job=queuedjob-%d > /dev/null 2>&1 &',
            escapeshellarg($phpBinary),
            escapeshellarg($cliScript),
            $descriptorId
        );

        exec($cmd);
    }

    /**
     * Log a warning — job is still safely queued, cron will handle it.
     */
    protected static function log(string $message): void
    {
        try {
            Injector::inst()->get(LoggerInterface::class)->warning(
                '[ImmediateJobRunner] ' . $message
            );
        } catch (\Throwable $e) {
            # Logging failure should never break the request
        }
    }
}
