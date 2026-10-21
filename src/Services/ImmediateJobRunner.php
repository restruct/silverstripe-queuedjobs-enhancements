<?php

namespace Restruct\QueuedJobsEnhancements\Services;

use Psr\Log\LoggerInterface;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Symbiote\QueuedJobs\Services\QueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJobService;

/**
 * Queue a job AND trigger immediate execution — no waiting for cron.
 *
 * Since the drain spawn, queueAndRun() queues the job on the IMMEDIATE queue and nothing else:
 * QueuedJobDescriptorDrainExtension starts the background drain (DrainSpawner, bin/qje-drain.sh)
 * for every new IMMEDIATE job, whoever queued it. queueAndRun() is kept for existing callers, and
 * is the same as `queueJob($job, $startAfter, null, QueuedJob::IMMEDIATE)`. The per-job spawn
 * helpers of 1.6.0 (spawnJobProcess(), buildSpawnCommand(), wrapWithLock(), resolveFlockBinary(),
 * isDue()) and the use_immediate_queue and use_spawn_lock / spawn_lock_file / spawn_lock_timeout
 * settings were deprecated by the drain spawn and removed in 1.7.0. What is left besides
 * queueAndRun() serves DrainSpawner: cliPhpBinary() (the CLI php binary) and triggerFolder().
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
 */
class ImmediateJobRunner
{
    use Configurable;
    use Injectable;

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
     * Resolve a CLI php binary usable for spawning. PHP_BINARY is only usable when we ARE the cli
     * SAPI: under php-fpm it points at the FPM daemon binary (/usr/sbin/php-fpmX.Y), which just
     * prints usage when handed a script and made every spawn of the per-job spawn (removed in 1.7.0)
     * a silent no-op (jobs sat New until the cron runner picked them up; found on a site in prod
     * 2026-07-19). So a real CLI binary is resolved instead, preferring the exact same PHP version.
     * Preference order: our own binary when we are cli(-server),
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
