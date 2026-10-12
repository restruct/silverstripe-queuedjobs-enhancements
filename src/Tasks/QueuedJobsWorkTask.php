<?php

namespace Restruct\QueuedJobsEnhancements\Tasks;

use InvalidArgumentException;
use Monolog\Handler\FilterHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Restruct\QueuedJobsEnhancements\Worker\QueueWorker;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Dev\BuildTask;
use Symbiote\QueuedJobs\Services\QueuedJobService;

/**
 * Silverstripe 5 entry point of the long-running queue worker (see QueueWorker).
 *
 * Usage (run PHP directly, not through the `sake` shell wrapper, so Supervisor's SIGTERM reaches
 * the PHP process):
 *   php vendor/silverstripe/framework/cli-script.php dev/tasks/QueuedJobsWorkTask queue=immediate
 *   ... max-time=3600 max-jobs=0 sleep=1 memory=512M job=123 stop-when-empty=1
 * A leading `--` on an option is accepted too (cli-script strips it), so `--queue=immediate` works.
 *
 * Silverstripe 6: `sake queuedjobs:work` (QueueWorkCommand); this task is disabled there.
 *
 * @config string $segment
 */
class QueuedJobsWorkTask extends BuildTask
{
    use WorkerTaskEntryPoint;

    /**
     * Silverstripe 5 URL segment: dev/tasks/QueuedJobsWorkTask
     */
    private static string $segment = 'QueuedJobsWorkTask';

    /**
     * Silverstripe 6 command name (unused there: the task is disabled, see _config/worker.yml)
     */
    protected static string $commandName = 'QueuedJobsWorkTask';

    public const DESCRIPTION = 'Run a long-lived queue worker (Laravel queue:work style). CLI only.';

    public const SS6_COMMAND = 'queuedjobs:work';

    public function __construct()
    {
        parent::__construct();
        # Assigned rather than declared: untyped on SS5, `string` on SS6 (see CleanupBrokenJobsTask)
        $this->title = 'Queued jobs worker';
    }

    /**
     * @param HTTPRequest $request
     */
    protected function runTask($request): void
    {
        # A worker runs until stopped; in a web request it would hold a PHP worker until it timed out
        if (!Director::is_cli()) {
            echo 'This task only runs from the command line.' . PHP_EOL;
            return;
        }

        # Nothing may touch QueuedJobService before $worker->run(): its constructor would register
        # the shutdown drain of the immediate queue (see QueueWorker::disableShutdownDrain())
        $options = [];
        foreach (array_keys(QueueWorker::DEFAULTS) as $name) {
            $options[$name] = $request->getVar($name);
        }
        # cli-script only parses arguments containing "=" into vars; a bare `stop-when-empty` or
        # `--stop-when-empty` lands in the `args` list instead (framework 5 CLIRequestBuilder)
        $flag = $options['stop-when-empty'];
        $bareArgs = array_map(fn ($arg) => ltrim((string) $arg, '-'), (array) $request->getVar('args'));
        $options['stop-when-empty'] = in_array('stop-when-empty', $bareArgs, true)
            || ($flag !== null && $flag !== '0' && $flag !== 'false');

        # Straight to STDOUT: on Silverstripe 5 a task's echo is buffered into the response and
        # only appears when the task ends, which for a worker means never (or hours late)
        $write = function (string $message): void {
            fwrite(defined('STDOUT') ? STDOUT : fopen('php://stdout', 'w'), $message . PHP_EOL);
        };

        try {
            $worker = QueueWorker::create($options, $write);
        } catch (InvalidArgumentException $e) {
            $write($e->getMessage());
            # Non-zero, so Supervisor/cron see a misconfigured command as a failure
            exit(2);
        }

        $exitCode = $worker->run(function (QueuedJobService $service): void {
            # Make job log messages visible in the worker's output, the same way
            # ProcessJobQueueTask does on CLI (warnings and below to stdout, errors to stderr)
            $logger = $service->getLogger();
            if ($logger instanceof Logger) {
                $logger->pushHandler(new FilterHandler(new StreamHandler('php://stdout'), Logger::DEBUG, Logger::WARNING));
                $logger->pushHandler(new StreamHandler('php://stderr', Logger::ERROR));
            }
        });

        if ($exitCode !== 0) {
            exit($exitCode);
        }
    }
}
