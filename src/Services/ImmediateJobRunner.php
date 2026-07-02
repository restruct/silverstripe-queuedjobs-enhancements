<?php

namespace Restruct\QueuedJobsEnhancements\Services;

use Psr\Log\LoggerInterface;
use SilverStripe\Control\Director;
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
            return $service->queueJob($job, $startAfter, QueuedJob::IMMEDIATE);
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
        $cmd = sprintf(
            'nohup %s %s dev/tasks/ProcessJobQueueTask job=%d > /dev/null 2>&1 &',
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
