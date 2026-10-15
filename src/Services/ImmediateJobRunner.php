<?php

namespace Restruct\QueuedJobsEnhancements\Services;

use Psr\Log\LoggerInterface;
use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\FieldType\DBDatetime;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Symbiote\QueuedJobs\Services\QueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJobService;

/**
 * Queue a job AND trigger immediate execution — no waiting for cron.
 *
 * Since the drain spawn, queueAndRun() queues the job on the IMMEDIATE queue and nothing else:
 * QueuedJobDescriptorDrainExtension starts the background drain (DrainSpawner, bin/qje-drain.sh)
 * for every new IMMEDIATE job, whoever queued it. queueAndRun() is kept for existing callers, and
 * is the same as `queueJob($job, $startAfter, null, QueuedJob::IMMEDIATE)`. The spawn helpers
 * below (spawnJobProcess(), buildSpawnCommand(), wrapWithLock(), the use_spawn_lock settings) are
 * no longer used and are deprecated; resolveCliPhpBinary() and triggerFolder() are used by
 * DrainSpawner.
 *
 * Up to 1.6.0 (no longer applies):
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
     * @deprecated since the drain spawn: queueAndRun() always queues on the IMMEDIATE queue, so this
     * setting has no effect any more. A site with its own inotifywait watcher sets
     * DrainSpawner.enabled: false instead. Kept so existing YAML that
     * sets it does not break; to be removed in the next major.
     *
     * Up to 1.6.0:
     * Set true if inotifywait (or similar) is monitoring the IMMEDIATE queue.
     * When true, jobs are queued as QueuedJob::IMMEDIATE instead of spawning a CLI process.
     *
     * Requires QueuedJobService.use_shutdown_function: false, otherwise no trigger file
     * is written and nothing can pick the job up (see queueAndRun()).
     */
    private static bool $use_immediate_queue = false;

    /**
     * @deprecated unused since the drain spawn (queueAndRun() no longer spawns per job); to be
     * removed in the next major. The drain's lock is taken by bin/qje-drain.sh.
     *
     * Serialise spawned runners behind a `flock` lock. Without this, a burst of dispatches
     * forks an unbounded number of PHP processes, each bootstrapping the whole framework.
     * Silently ignored when no flock binary exists (e.g. macOS).
     */
    private static bool $use_spawn_lock = true;

    /**
     * @deprecated unused since the drain spawn (queueAndRun() no longer spawns per job); to be
     * removed in the next major. The drain's lock is taken by bin/qje-drain.sh.
     *
     * Lock file for the above. Relative paths resolve under TEMP_PATH.
     */
    private static string $spawn_lock_file = 'queuedjobs-enhancements-spawn.lock';

    /**
     * @deprecated unused since the drain spawn (queueAndRun() no longer spawns per job); to be
     * removed in the next major. The drain's lock is taken by bin/qje-drain.sh.
     *
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
        # Since the drain spawn: the job is always queued on the IMMEDIATE queue, and
        # QueuedJobDescriptorDrainExtension starts the background drain (bin/qje-drain.sh via
        # DrainSpawner) as soon as the descriptor row is written. That replaces both paths below:
        # the per-job `--job=<ID>` spawn (one PHP process per job, unbounded during a burst, and no
        # spawn at all from the CLI) and use_immediate_queue (which relied on an inotify watcher and
        # QueuedJobService.use_shutdown_function: false). A future $startAfter needs no check here:
        # the drain runner's getNextPendingJob() skips a job that is not due, and the cron picks it up
        # later. queueJob() is ($job, $startAfter, $userId, $queueName): the queue is the FOURTH
        # argument (see the old code below for what passing it third did).
        return QueuedJobService::singleton()->queueJob($job, $startAfter, null, QueuedJob::IMMEDIATE);

        # old (1.6.0), kept for reference:
        // $service = QueuedJobService::singleton();
        //
        // # If inotifywait is set up, use IMMEDIATE queue — the existing
        // # infrastructure handles sub-second execution already
        // if (static::singleton()->config()->get('use_immediate_queue')) {
        //     # queueJob() is ($job, $startAfter, $userId, $queueName) — the queue override is
        //     # the FOURTH param. Passing it third assigned the queue constant to $userId, which
        //     # (a) left JobType at $job->getJobType() so the IMMEDIATE override never applied, and
        //     # (b) forced RunAsID to Member ID 1, bypassing the job's own getRunAsMemberID().
        //     // return $service->queueJob($job, $startAfter, QueuedJob::IMMEDIATE);  // old: queue landed in $userId
        //     $descriptorId = $service->queueJob($job, $startAfter, null, QueuedJob::IMMEDIATE);
        //
        //     # The trigger file that inotifywait watches is only written when BOTH
        //     # JobType === IMMEDIATE *and* QueuedJobService.use_shutdown_function is false
        //     # (see QueuedJobDescriptor::activateOnQueue()). That config defaults to TRUE, so
        //     # without this warning the job silently degrades to "picked up on the next cron
        //     # tick" — slower than the CLI-spawn path this option replaced.
        //     if (Config::inst()->get(QueuedJobService::class, 'use_shutdown_function')) {
        //         static::log(sprintf(
        //             'use_immediate_queue is on, but QueuedJobService.use_shutdown_function is still true, '
        //             . 'so no trigger file was written for job #%d and no watcher can pick it up. '
        //             . 'Set use_shutdown_function: false and run an inotifywait watcher, '
        //             . 'or turn off use_immediate_queue to fall back to CLI spawn.',
        //             $descriptorId
        //         ));
        //     }
        //
        //     return $descriptorId;
        // }
        //
        // # Queue normally first (safety net — cron picks it up if spawn fails)
        // $descriptorId = $service->queueJob($job, $startAfter);
        //
        // # Spawn background CLI process to run this specific job - but only when it is due.
        // # The spawned process runs it through QueuedJobService::runJob(), which never looks at
        // # StartAfter (only getNextPendingJob() does), so spawning for a future start ran the job
        // # straight away, ignoring the requested time. A future job is left to the queue runner,
        // # which picks it up once StartAfter has passed.
        // # The start time is read back from the DESCRIPTOR, not taken from $startAfter: queueJob()
        // # returns an existing New/Initialising descriptor with the same signature instead of
        // # creating one, and that one may carry a future StartAfter of its own.
        // // static::spawnJobProcess($descriptorId);  // old: spawned regardless of $startAfter
        // $descriptor = QueuedJobDescriptor::get()->byID($descriptorId);
        // if (static::isDue($descriptor ? $descriptor->StartAfter : $startAfter)) {
        //     static::spawnJobProcess($descriptorId);
        // }
        //
        // return $descriptorId;
    }

    /**
     * @deprecated unused since the drain spawn (queueAndRun() no longer spawns per job); to be
     * removed in the next major. The drain's lock is taken by bin/qje-drain.sh.
     *
     * Is a job with this StartAfter due now? Null (no start time) is due. A value strtotime()
     * cannot parse is treated as due too, which keeps the behaviour from before this check
     * existed rather than silently never spawning.
     */
    protected static function isDue(?string $startAfter): bool
    {
        if ($startAfter === null || $startAfter === '') {
            return true;
        }
        $timestamp = strtotime($startAfter);
        if ($timestamp === false) {
            return true;
        }

        # DBDatetime::now() rather than time(): honours set_mock_now() in tests, like core does
        return $timestamp <= DBDatetime::now()->getTimestamp();
    }

    /**
     * @deprecated unused since the drain spawn (queueAndRun() no longer spawns per job); to be
     * removed in the next major. The drain's lock is taken by bin/qje-drain.sh.
     *
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
     * @deprecated unused since the drain spawn (queueAndRun() no longer spawns per job); to be
     * removed in the next major. The drain's lock is taken by bin/qje-drain.sh.
     *
     * Build the shell command that runs a single queued job in a detached process.
     * Returns null when the command cannot be built (job then waits for cron).
     *
     * Kept separate from spawnJobProcess() so it can be asserted on in tests without
     * actually shelling out.
     */
    protected static function buildSpawnCommand(int $descriptorId): ?string
    {
        # Silverstripe 5: use cli-script.php directly - more reliable than the sake shell wrapper
        # (see SSKB cli-commands profile). Silverstripe 6 removed cli-script.php; its sake is a
        # PHP script (framework bin/sake, Symfony Console), so it is run with the same PHP binary.
        # Detected by file rather than by version so the check is the thing we actually need.
        $frameworkDir = BASE_PATH . '/vendor/silverstripe/framework';
        $cliScript = $frameworkDir . '/cli-script.php';
        $sakeScript = $frameworkDir . '/bin/sake';
        # The spawn runs this module's worker for this ONE job, not ProcessJobQueueTask. Any CLI
        # process that instantiates QueuedJobService drains the whole IMMEDIATE queue at exit
        # (its constructor registers onShutdown(); SSKB queuedjobs §12), so `ProcessJobQueueTask
        # job=queuedjob-<ID>` ran our job and then every other pending immediate job too ("bug 3",
        # issue #5). The worker switches that drain off for its own process, and in --job mode it
        # also checks the job's status and StartAfter before running it.
        if (file_exists($cliScript)) {
            $script = $cliScript;
            // # SS5 single-job mode only triggers when the value contains a dash (see below)
            // $taskArgs = sprintf('dev/tasks/ProcessJobQueueTask job=queuedjob-%d', $descriptorId);
            $taskArgs = sprintf('dev/tasks/QueuedJobsWorkTask job=%d max-jobs=1', $descriptorId);
        } elseif (file_exists($sakeScript)) {
            $script = $sakeScript;
            // # SS6 accepts "queuedjob-<ID>" on every 6.x (a bare ID only from queuedjobs 6.2.1)
            // $taskArgs = sprintf('tasks:ProcessJobQueueTask --job=queuedjob-%d', $descriptorId);
            $taskArgs = sprintf('queuedjobs:work --job=%d --max-jobs=1', $descriptorId);
        } else {
            # Was: only cli-script.php was looked for, so every SS6 spawn returned null here
            // static::log("Cannot spawn job #{$descriptorId}: cli-script.php not found at {$cliScript}");
            static::log("Cannot spawn job #{$descriptorId}: neither cli-script.php nor bin/sake found in {$frameworkDir}");
            return null;
        }

        # Find the CLI PHP binary. PHP_BINARY is only usable when we ARE the cli SAPI — under
        # php-fpm it points at the FPM daemon binary (/usr/sbin/php-fpmX.Y), which just prints
        # usage when handed a script and made every spawn a silent no-op (jobs sat New until the
        # cron runner picked them up; found on a site in prod 2026-07-19). Resolve a real CLI
        # binary instead, preferring the exact same PHP version.
        //        $phpBinary = PHP_BINARY ?: 'php';
        $phpBinary = static::resolveCliPhpBinary();

        # (Applied to the ProcessJobQueueTask spawn up to 1.5.0; the worker takes a bare ID.)
        # The "queuedjob-" prefix is REQUIRED. ProcessJobQueueTask only enters single-job mode
        # when the job param contains a dash — `if (($job = $request->getVar('job')) && strpos($job, '-'))`
        # — taking the part after the dash as the descriptor ID. This matches the `queuedjob-{ID}`
        # trigger-file names inotifywait passes as `%f`. A bare `job=42` fails that check and falls
        # through to runQueue('Queued'), draining the ENTIRE queued queue instead of this one job
        # (and missing the job entirely if it is a LARGE or IMMEDIATE job).
        // $inner = sprintf(
        //     // '%s %s dev/tasks/ProcessJobQueueTask job=%d',  // old: bare ID, no dash
        //     '%s %s dev/tasks/ProcessJobQueueTask job=queuedjob-%d',
        //     escapeshellarg($phpBinary),
        //     escapeshellarg($cliScript),
        //     $descriptorId
        // );
        $inner = sprintf(
            '%s %s %s',
            escapeshellarg($phpBinary),
            escapeshellarg($script),
            $taskArgs
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
        // if (in_array(PHP_SAPI, ['cli', 'cli-server'], true) && PHP_BINARY) {
        //     return PHP_BINARY;
        // }
        # old: the SAPI checks lived here; moved to resolveCliPhpBinaryFor() so a test can hand in
        # the fpm-fcgi SAPI and php-fpm binary that a web request has
        return static::resolveCliPhpBinaryFor(PHP_SAPI, (string) PHP_BINARY);
    }

    /**
     * resolveCliPhpBinary() for a given SAPI and PHP_BINARY. Our own binary is only used when we are
     * cli(-server) AND it is set; never under fpm-fcgi, where it is the FPM daemon.
     */
    protected static function resolveCliPhpBinaryFor(string $sapi, string $binary): string
    {
        if (in_array($sapi, ['cli', 'cli-server'], true) && $binary !== '') {
            return $binary;
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
     * The CLI php binary the background runner is started with (see resolveCliPhpBinary()).
     * Public entry point for DrainSpawner, which adds QJE_PHP_CLI and the php_cli config in front.
     */
    public static function cliPhpBinary(): string
    {
        return static::resolveCliPhpBinary();
    }

    /**
     * Upstream's job folder: the `cache_dir` of QueuedJobService, relative paths under TEMP_PATH,
     * created when missing. The `queuedjob-<ID>` trigger files go here, and so do the drain marker
     * and lock (DrainSpawner). Read through upstream's own protected
     * QueuedJobDescriptor::getJobDir(), so the resolution can never drift from upstream's.
     */
    public static function triggerFolder(): string
    {
        $method = new \ReflectionMethod(QueuedJobDescriptor::class, 'getJobDir');
        $method->setAccessible(true);

        return (string) $method->invoke(QueuedJobDescriptor::singleton());
    }

    /**
     * @deprecated unused since the drain spawn (queueAndRun() no longer spawns per job); to be
     * removed in the next major. The drain's lock is taken by bin/qje-drain.sh.
     *
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
            # TEMP_PATH, not TEMP_FOLDER: Silverstripe 6 no longer defines TEMP_FOLDER, so this line
            # fataled ("Undefined constant") on SS6 whenever flock was installed. On SS5 the two
            # are the same value (framework 5 constants.php defines TEMP_FOLDER from TEMP_PATH).
            // $lockFile = TEMP_FOLDER . '/' . ($lockFile ?: 'queuedjobs-enhancements-spawn.lock');
            $lockFile = TEMP_PATH . '/' . ($lockFile ?: 'queuedjobs-enhancements-spawn.lock');
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
     * @deprecated unused since the drain spawn (queueAndRun() no longer spawns per job); to be
     * removed in the next major. The drain's lock is taken by bin/qje-drain.sh.
     *
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
