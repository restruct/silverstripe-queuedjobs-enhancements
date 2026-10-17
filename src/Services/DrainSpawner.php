<?php

namespace Restruct\QueuedJobsEnhancements\Services;

use Psr\Log\LoggerInterface;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\Connect\TempDatabase;
use SilverStripe\ORM\DB;
use Throwable;

/**
 * Drain spawn: start a background drain of the immediate queue whenever an IMMEDIATE job is queued.
 *
 * Called by QueuedJobDescriptorDrainExtension right after a new IMMEDIATE descriptor row was
 * written. It touches the marker file, then starts bin/qje-drain.sh detached:
 *
 *   cd <project>; nohup bash bin/qje-drain.sh <marker> <lock> <php> <runner...> > /dev/null 2>&1 &
 *
 * The wrapper takes a lock without waiting, so during a burst only the first dispatch starts a PHP
 * runner; the others find the lock held and exit before PHP boots. The runner is the module's
 * worker in one-shot mode on the immediate queue. The ORDER matters: the row is written before the
 * marker is touched, and the wrapper removes the marker before the runner starts and checks it after
 * the runner exits. A job queued after the runner's last look at the queue has therefore always
 * touched the marker after its removal, and the wrapper starts another runner for it.
 *
 * Nothing stays resident: no Supervisor program, no inotify, no polling. The cron keeps running
 * ProcessJobQueueTask as the safety net (health checks, default jobs, jobs whose spawn failed).
 *
 * Inside an open database transaction the new row is not visible to the runner until the commit. A
 * runner that finishes its linger before the commit would miss the job, and the marker is already
 * gone, so it would wait for the next dispatch or the cron (measured: a 5 s transaction lost the job
 * on SS5 and SS6). So a dispatch inside a transaction drains now (which covers a commit within the
 * linger) AND once more at the end of the process (register_shutdown_function, after the commit of
 * any normal request or task).
 *
 * Best effort. When exec() is disabled, or the command cannot be built, nothing is started and the
 * job simply waits in the IMMEDIATE queue for whatever else runs it (cron, a worker, upstream's own
 * shutdown drain on CLI).
 *
 * @config bool $enabled
 * @config string $php_cli
 * @config string $log_file
 */
class DrainSpawner
{
    use Configurable;
    use Injectable;

    /**
     * Environment variable (or .env entry) with the CLI php binary to run the runner with. Wins over
     * the php_cli config. Under php-fpm, PHP_BINARY is the FPM daemon, so a server whose CLI binary
     * is not found next to PHP_BINDIR needs one of the two.
     */
    public const ENV_PHP_CLI = 'QJE_PHP_CLI';

    /**
     * Marker and lock file names. They live in SPAWN_FOLDER, a subfolder of upstream's job folder
     * (QueuedJobDescriptor::getJobDir(), the folder of the `queuedjob-<ID>` trigger files), so the
     * path follows QueuedJobService.cache_dir exactly as upstream's trigger files do, but a watcher on
     * the job folder itself (upstream's inotifywait recipe, `--event attrib`, not recursive) never
     * sees the marker being touched. In the job folder itself, every dispatch made that recipe run
     * `ProcessJobQueueTask job=.qje-spawn.pending`, which on Silverstripe 5 logs an ERROR (mic's
     * decision, 2026-10-15).
     */
    // public const MARKER_FILE = '.qje-spawn.pending';  // old: directly in the job folder
    // public const LOCK_FILE = '.qje-spawn.lock';  // old: directly in the job folder
    public const SPAWN_FOLDER = '.qje-spawn';

    public const MARKER_FILE = 'pending';

    public const LOCK_FILE = 'lock';

    /**
     * Switch the drain spawn off, e.g. on a site that already runs the immediate queue another way
     * (an inotifywait watcher; up to 1.6.0 also a resident queuedjobs:work worker, removed in 1.7.0). The
     * jobs are queued as IMMEDIATE either way.
     */
    private static bool $enabled = true;

    /**
     * CLI php binary for the runner, when neither QJE_PHP_CLI nor the automatic resolution fits
     * (see phpBinary()). Empty = not set.
     */
    private static string $php_cli = '';

    /**
     * Append the wrapper's and the runners' output to this file, for debugging. Empty = discard
     * (/dev/null). Relative paths resolve under TEMP_PATH.
     */
    private static string $log_file = '';

    /** @var bool so the "exec() is disabled" warning is logged once per process */
    protected static bool $warnedNoExec = false;

    /** @var bool the end-of-process drain for dispatches inside a transaction is registered */
    protected static bool $shutdownDrainRegistered = false;

    /**
     * Start a drain now. Returns whether a drain was started.
     */
    public function requestDrain(): bool
    {
        if (!static::config()->get('enabled')) {
            return false;
        }

        if (!$this->canExec()) {
            # Without exec() there is no way to start a background process. Say so once per process
            # (a burst would otherwise log it for every job) and leave the job to upstream: it is
            # queued as IMMEDIATE either way.
            if (!static::$warnedNoExec) {
                static::$warnedNoExec = true;
                $this->log('exec() is disabled, so immediate jobs are not drained in the background; '
                    . 'they wait for the cron (or another runner of the immediate queue).');
            }
            return false;
        }

        # A process on a temporary test database (SapphireTest) must never start a runner: the
        # runner would boot against the site's real database from .env, not the test database, and
        # run the site's immediate queue while the tests run.
        if ($this->isTestDatabase()) {
            return false;
        }

        # Inside a transaction the row is invisible to the runner until the commit: drain again at
        # the end of this process, once, however many jobs it queues (see the class docblock).
        if ($this->inTransaction() && !static::$shutdownDrainRegistered) {
            static::$shutdownDrainRegistered = true;
            $this->registerShutdown([$this, 'runDeferredDrain']);
        }

        return $this->spawn();
    }

    /**
     * The end-of-process drain registered for a dispatch inside a transaction. Public for
     * register_shutdown_function() (and tests). By the time it runs, the transaction of a normal
     * request or task was committed (or rolled back, and then the drain finds nothing).
     */
    public function runDeferredDrain(): void
    {
        try {
            $this->spawn();
        } catch (Throwable $e) {
            # A shutdown function must never turn a finished request into an error page
            $this->log('Deferred drain failed: ' . $e->getMessage());
        }
    }

    /**
     * Touch the marker and start the wrapper. Returns whether it was started.
     */
    protected function spawn(): bool
    {
        $command = $this->buildCommand();
        if ($command === null) {
            return false;
        }

        # The marker BEFORE the spawn, and only after the descriptor row was written (the caller
        # runs in onAfterWrite): see the class docblock for why this order loses no job.
        if (!@touch($this->markerPath())) {
            $this->log(sprintf('Could not touch the drain marker %s; draining anyway.', $this->markerPath()));
        }

        $this->launch($command);

        return true;
    }

    /**
     * The full shell command that starts the wrapper detached, or null when it cannot be built.
     */
    public function buildCommand(): ?string
    {
        $runner = $this->runnerCommand();
        if ($runner === null) {
            return null;
        }

        $wrapper = static::wrapperPath();
        if (!is_file($wrapper)) {
            $this->log(sprintf('Drain wrapper %s not found; not draining.', $wrapper));
            return null;
        }

        $args = array_merge([$this->bashBinary(), $wrapper, $this->markerPath(), $this->lockPath()], $runner);

        # cd first, in the foreground: the runner's relative paths (none today, but a project's
        # .env loader or a job may use the working directory) then match a run from the project
        # root, as a cron or Supervisor command does. `;` rather than `&&`, so a failed cd still
        # drains. nohup detaches from the request, the redirect is required (without it exec()
        # waits until the background process closes stdout), and & backgrounds it.
        return sprintf(
            'cd %s; nohup %s %s 2>&1 &',
            escapeshellarg(BASE_PATH),
            implode(' ', array_map('escapeshellarg', $args)),
            $this->outputRedirect()
        );
    }

    /**
     * The runner as an argument list: the module's worker, one-shot, on the immediate queue.
     * Silverstripe 5 runs it through cli-script.php (the task), Silverstripe 6 through the PHP sake
     * script (the command); detected by file rather than by version, so the check is the thing
 * actually needed (as ImmediateJobRunner's per-job spawn did up to 1.6.0).
     *
     * @return string[]|null
     */
    public function runnerCommand(): ?array
    {
        $frameworkDir = BASE_PATH . '/vendor/silverstripe/framework';
        $cliScript = $frameworkDir . '/cli-script.php';
        $sakeScript = $frameworkDir . '/bin/sake';

        if (file_exists($cliScript)) {
            $runner = [$cliScript, 'dev/tasks/QueuedJobsWorkTask', 'queue=immediate', 'stop-when-empty=1'];
        } elseif (file_exists($sakeScript)) {
            $runner = [$sakeScript, 'queuedjobs:work', '--queue=immediate', '--stop-when-empty'];
        } else {
            $this->log(sprintf('Cannot drain: neither cli-script.php nor bin/sake found in %s', $frameworkDir));
            return null;
        }

        array_unshift($runner, $this->phpBinary());

        return $runner;
    }

    /**
     * The CLI php binary, in this order:
     *   1. the QJE_PHP_CLI environment variable (Environment::getEnv(), so a .env entry works too:
     *      php-fpm normally clears the real environment),
     *   2. the php_cli config,
     *   3. ImmediateJobRunner's automatic resolution (this binary under the CLI, else the
     *      version-exact phpX.Y next to PHP_BINDIR).
     * A php-fpm binary is never used: handed a script, it prints its usage and exits, so every
     * drain would silently do nothing. A configured php-fpm value is skipped with a warning.
     */
    public function phpBinary(): string
    {
        $candidates = [
            static::ENV_PHP_CLI . ' environment variable' => (string) Environment::getEnv(static::ENV_PHP_CLI),
            'DrainSpawner.php_cli config' => (string) static::config()->get('php_cli'),
        ];
        foreach ($candidates as $source => $binary) {
            $binary = trim($binary);
            if ($binary === '') {
                continue;
            }
            if (static::isFpmBinary($binary)) {
                $this->log(sprintf('%s points at php-fpm (%s), which cannot run a CLI script; ignoring it.', $source, $binary));
                continue;
            }
            return $binary;
        }

        return ImmediateJobRunner::cliPhpBinary();
    }

    /**
     * Whether a binary path is a php-fpm daemon (php-fpm, php-fpm8.3, php8.3-fpm, ...).
     */
    public static function isFpmBinary(string $binary): bool
    {
        return (bool) preg_match('#(^|/)(php-fpm[^/]*|php[0-9.]*-fpm)$#', trim($binary));
    }

    /**
     * The folder of the marker and the lock (see SPAWN_FOLDER), created when it is missing. The
     * job folder itself is created by upstream's getJobDir() (through triggerFolder()).
     */
    public function spawnFolder(): string
    {
        $folder = ImmediateJobRunner::triggerFolder() . '/' . static::SPAWN_FOLDER;
        if (!is_dir($folder)) {
            # @: two processes may create it at the same moment; is_dir() below is the real check
            @mkdir($folder, 0775, true);
        }

        return $folder;
    }

    public function markerPath(): string
    {
        // return ImmediateJobRunner::triggerFolder() . '/' . static::MARKER_FILE;  // old: in the job folder itself
        return $this->spawnFolder() . '/' . static::MARKER_FILE;
    }

    public function lockPath(): string
    {
        // return ImmediateJobRunner::triggerFolder() . '/' . static::LOCK_FILE;  // old: in the job folder itself
        return $this->spawnFolder() . '/' . static::LOCK_FILE;
    }

    public static function wrapperPath(): string
    {
        # src/Services -> module root
        return dirname(__DIR__, 2) . '/bin/qje-drain.sh';
    }

    protected function bashBinary(): string
    {
        foreach (['/bin/bash', '/usr/bin/bash', '/usr/local/bin/bash'] as $candidate) {
            if (@is_executable($candidate)) {
                return $candidate;
            }
        }

        return 'bash';
    }

    protected function outputRedirect(): string
    {
        $logFile = trim((string) static::config()->get('log_file'));
        if ($logFile === '') {
            return '> /dev/null';
        }
        if ($logFile[0] !== '/') {
            $logFile = TEMP_PATH . '/' . $logFile;
        }

        return '>> ' . escapeshellarg($logFile);
    }

    /**
     * Whether exec() can be called. A function listed in disable_functions is not defined at all on
     * PHP 8, so function_exists() covers that.
     */
    protected function canExec(): bool
    {
        return function_exists('exec');
    }

    protected function isTestDatabase(): bool
    {
        try {
            return TempDatabase::create()->isUsed();
        } catch (Throwable $e) {
            # No connection yet, or the class changed shape: not a test database as far as we know
            return false;
        }
    }

    /**
     * Whether a transaction is open on the connection the descriptor was written to. Silverstripe 6
     * with replicas: the primary, where writes go (SSKB orm transactions note); SS5 has one.
     */
    protected function inTransaction(): bool
    {
        try {
            $conn = defined(DB::class . '::CONN_PRIMARY') ? DB::get_conn(DB::CONN_PRIMARY) : DB::get_conn();

            return $conn && $conn->transactionDepth() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Separate so tests can capture the callback instead of registering it.
     */
    protected function registerShutdown(callable $callback): void
    {
        register_shutdown_function($callback);
    }

    /**
     * Run the command. Separate so tests can record it instead of shelling out.
     */
    protected function launch(string $command): void
    {
        exec($command);
    }

    protected function log(string $message): void
    {
        try {
            Injector::inst()->get(LoggerInterface::class)->warning('[DrainSpawner] ' . $message);
        } catch (Throwable $e) {
            # Logging failure must never break the write that queued the job
        }
    }
}
