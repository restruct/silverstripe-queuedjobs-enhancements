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
 * @config bool $use_spawn_lock
 * @config string $spawn_lock_file
 * @config int $spawn_lock_timeout
 */
class ImmediateJobRunner
{
    use Configurable;
    use Injectable;

    /**
     * Set true if inotifywait (or similar) is monitoring the IMMEDIATE queue.
     * When true, jobs are queued as QueuedJob::IMMEDIATE instead of spawning a CLI process.
     *
     * Requires QueuedJobService.use_shutdown_function: false, otherwise no trigger file
     * is written and nothing can pick the job up (see queueAndRun()).
     */
    private static bool $use_immediate_queue = false;

    /**
     * Serialise spawned runners behind a `flock` lock. Without this, a burst of dispatches
     * forks an unbounded number of PHP processes, each bootstrapping the whole framework.
     * Silently ignored when no flock binary exists (e.g. macOS).
     */
    private static bool $use_spawn_lock = true;

    /**
     * Lock file for the above. Relative paths resolve under TEMP_FOLDER.
     */
    private static string $spawn_lock_file = 'queuedjobs-enhancements-spawn.lock';

    /**
     * Seconds a spawned runner waits for the lock before giving up (the job stays queued,
     * so cron still runs it). 0 or less means "fail immediately" (flock -n).
     */
    private static int $spawn_lock_timeout = 300;

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

        $cmd = static::buildSpawnCommand($descriptorId);
        if ($cmd === null) {
            return;
        }

        exec($cmd);
    }

    /**
     * Build the shell command that runs a single queued job in a detached process.
     * Returns null when the command cannot be built (job then waits for cron).
     *
     * Kept separate from spawnJobProcess() so it can be asserted on in tests without
     * actually shelling out.
     */
    protected static function buildSpawnCommand(int $descriptorId): ?string
    {
        # Use cli-script.php directly — more reliable than sake (see SSKB cli-commands profile)
        $cliScript = BASE_PATH . '/vendor/silverstripe/framework/cli-script.php';
        if (!file_exists($cliScript)) {
            static::log("Cannot spawn job #{$descriptorId}: cli-script.php not found at {$cliScript}");
            return null;
        }

        # Find the CLI PHP binary. PHP_BINARY is only usable when we ARE the cli SAPI — under
        # php-fpm it points at the FPM daemon binary (/usr/sbin/php-fpmX.Y), which just prints
        # usage when handed a script and made every spawn a silent no-op (jobs sat New until the
        # cron runner picked them up; found on a site in prod 2026-07-19). Resolve a real CLI
        # binary instead, preferring the exact same PHP version.
        //        $phpBinary = PHP_BINARY ?: 'php';
        $phpBinary = static::resolveCliPhpBinary();

        # The "queuedjob-" prefix is REQUIRED. ProcessJobQueueTask only enters single-job mode
        # when the job param contains a dash — `if (($job = $request->getVar('job')) && strpos($job, '-'))`
        # — taking the part after the dash as the descriptor ID. This matches the `queuedjob-{ID}`
        # trigger-file names inotifywait passes as `%f`. A bare `job=42` fails that check and falls
        # through to runQueue('Queued'), draining the ENTIRE queued queue instead of this one job
        # (and missing the job entirely if it is a LARGE or IMMEDIATE job).
        $inner = sprintf(
            // '%s %s dev/tasks/ProcessJobQueueTask job=%d',  // old: bare ID, no dash
            '%s %s dev/tasks/ProcessJobQueueTask job=queuedjob-%d',
            escapeshellarg($phpBinary),
            escapeshellarg($cliScript),
            $descriptorId
        );

        # Optionally serialise spawns behind a lock so a burst of dispatches cannot fork an
        # unbounded number of PHP processes (each one bootstraps the whole framework). This
        # mirrors what the inotifywait watcher gets for free: `inotifywait … | sh` feeds
        # commands into a single shell, which runs them one at a time.
        $inner = static::wrapWithLock($inner);

        # nohup detaches from the parent, /dev/null discards output (without the redirect
        # PHP's exec() blocks until stdout closes), & backgrounds it.
        return sprintf('nohup %s > /dev/null 2>&1 &', $inner);
    }

    /**
     * Resolve a CLI php binary usable for spawning (see buildSpawnCommand — PHP_BINARY is the
     * FPM daemon under fpm-fcgi). Preference order: our own binary when we are cli(-server),
     * then version-exact `phpX.Y` next to it / in PHP_BINDIR (Debian/Ubuntu layout), then the
     * generic `php` in PHP_BINDIR, then bare `php` from PATH as a last resort.
     */
    protected static function resolveCliPhpBinary(): string
    {
        if (in_array(PHP_SAPI, ['cli', 'cli-server'], true) && PHP_BINARY) {
            return PHP_BINARY;
        }
        $versioned = sprintf('php%d.%d', PHP_MAJOR_VERSION, PHP_MINOR_VERSION);
        foreach ([PHP_BINDIR . '/' . $versioned, '/usr/bin/' . $versioned, PHP_BINDIR . '/php', '/usr/bin/php'] as $candidate) {
            if (@is_executable($candidate)) {
                return $candidate;
            }
        }
        static::log('No CLI php binary found next to PHP_BINDIR; spawning with bare "php" from PATH.');

        return 'php';
    }

    /**
     * Prefix a command with `flock` so at most one spawned runner executes at a time.
     * Returns the command unchanged when locking is disabled or flock is unavailable.
     */
    protected static function wrapWithLock(string $command): string
    {
        $config = static::singleton()->config();

        if (!$config->get('use_spawn_lock')) {
            return $command;
        }

        $flock = static::resolveFlockBinary();
        if ($flock === null) {
            # macOS has no util-linux flock; degrade to unlocked spawn rather than silently
            # producing a command that does not exist.
            static::log('use_spawn_lock is enabled but no flock binary was found; spawning without a lock.');
            return $command;
        }

        $lockFile = (string) $config->get('spawn_lock_file');
        if ($lockFile === '' || $lockFile[0] !== '/') {
            $lockFile = TEMP_FOLDER . '/' . ($lockFile ?: 'queuedjobs-enhancements-spawn.lock');
        }

        $timeout = (int) $config->get('spawn_lock_timeout');

        # -w waits up to N seconds for the lock then gives up (the job stays queued, so cron
        # still runs it). -n fails immediately. Waiting is usually right here: the process is
        # already detached, so blocking costs nothing and no dispatch gets dropped.
        $waitOpt = $timeout > 0 ? sprintf('-w %d', $timeout) : '-n';

        return sprintf(
            '%s %s %s %s',
            escapeshellarg($flock),
            $waitOpt,
            escapeshellarg($lockFile),
            $command
        );
    }

    /**
     * Locate the util-linux `flock` binary, or null when it is not installed.
     */
    protected static function resolveFlockBinary(): ?string
    {
        foreach (['/usr/bin/flock', '/bin/flock'] as $candidate) {
            if (@is_executable($candidate)) {
                return $candidate;
            }
        }

        $found = @shell_exec('command -v flock 2>/dev/null');
        $found = is_string($found) ? trim($found) : '';

        return $found !== '' ? $found : null;
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
