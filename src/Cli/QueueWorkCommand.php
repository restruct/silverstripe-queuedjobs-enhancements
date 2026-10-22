<?php

namespace Restruct\QueuedJobsEnhancements\Cli;

use InvalidArgumentException;
use Monolog\Logger;
use Restruct\QueuedJobsEnhancements\Worker\QueueWorker;
use SilverStripe\PolyExecution\PolyCommand;
use SilverStripe\PolyExecution\PolyOutput;
use SilverStripe\PolyExecution\PolyOutputLogHandler;
use Symbiote\QueuedJobs\Services\QueuedJobService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

# Silverstripe 5 has no PolyCommand. The config system class_exists()es EVERY class in the manifest
# on a flush (PrivateStaticTransformer) and that autoloads this file, so without this guard the
# missing parent class fatals the whole SS5 application on flush/dev/build. The YAML `Only:
# classexists` in _config/worker.yml does not prevent that: YAML conditions decide which config
# applies, not which classes get loaded, so a class extending an OPTIONAL dependency fatals the
# WHOLE application at bootstrap unless its file guards itself.
if (!class_exists(PolyCommand::class)) {
    return;
}

/**
 * Silverstripe 6 only: `sake queuedjobs:work`, the one-shot draining runner that bin/qje-drain.sh
 * starts. See QueueWorker.
 *
 * Registered with Sake in _config/worker.yml. On Silverstripe 5 the guard above stops this class
 * from being declared at all; the SS5 entry point is the QueuedJobsWorkTask BuildTask.
 */
class QueueWorkCommand extends PolyCommand
{
    protected static string $commandName = 'queuedjobs:work';

    // protected static string $description = 'Run a long-lived queue worker (Laravel queue:work style)';  // old: resident mode, removed in 1.7.0
    protected static string $description = 'Run the due jobs of a queue in series, then exit (started by bin/qje-drain.sh)';

    /**
     * CLI only: a drain may not end inside a web request's time limit, and should not be startable from a URL.
     */
    private static bool $can_run_in_browser = false;

    public function getTitle(): string
    {
        return 'Queued jobs worker';
    }

    public static function getHelp(): string
    {
        return 'Runs the due jobs of the given queue(s) in series and exits when they are empty (exit 0). '
            . 'Exits early on its limits or when a job is parked as Waiting (exit ' . QueueWorker::EXIT_MORE_WORK
            . ', so bin/qje-drain.sh starts a new runner), and on SIGTERM/SIGINT after the current job step (exit 0). '
            . 'bin/qje-drain.sh starts it; keep the ProcessJobQueueTask crons as a fallback.';
    }

    public function run(InputInterface $input, PolyOutput $output): int
    {
        # Nothing may touch QueuedJobService before $worker->run(): its constructor would register
        # the shutdown drain of the immediate queue (see QueueWorker::disableShutdownDrain())
        try {
            $worker = QueueWorker::create(
                [
                    'queue' => $input->getOption('queue'),
                    // 'sleep' => $input->getOption('sleep'),  // old: resident polling mode, removed in 1.7.0
                    'max-time' => $input->getOption('max-time'),
                    'max-jobs' => $input->getOption('max-jobs'),
                    'memory' => $input->getOption('memory'),
                    // 'job' => $input->getOption('job'),  // old: --job mode of the per-job spawn, removed in 1.7.0
                    # stop-when-empty is not passed on: since 1.7.0 it is the only behaviour
                    // 'stop-when-empty' => $input->getOption('stop-when-empty'),  // old
                    'linger' => $input->getOption('linger'),
                    'requested' => $input->getOption('requested'),
                ],
                function (string $message) use ($output): void {
                    # OUTPUT_RAW: job titles are free text and must not be read as console tags
                    $output->writeln($message, PolyOutput::OUTPUT_RAW);
                }
            );
        } catch (InvalidArgumentException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</>');
            return Command::INVALID;
        }

        return $worker->run(function (QueuedJobService $service) use ($output): void {
            # Make job log messages visible in the runner's output, as ProcessJobQueueTask does on CLI
            $logger = $service->getLogger();
            if ($logger instanceof Logger) {
                $logger->pushHandler(PolyOutputLogHandler::create($output));
            }
        });
    }

    public function getOptions(): array
    {
        $defaults = QueueWorker::DEFAULTS;

        return [
            new InputOption('queue', null, InputOption::VALUE_REQUIRED, 'Queue(s) to work, comma separated, highest priority first (immediate, queued, large)', $defaults['queue']),
            // new InputOption('sleep', null, InputOption::VALUE_REQUIRED, 'Seconds to wait when the queue is empty', $defaults['sleep']),  // old: removed in 1.7.0
            new InputOption('max-time', null, InputOption::VALUE_REQUIRED, 'Exit after this many seconds (0 = no limit)', $defaults['max-time']),
            new InputOption('max-jobs', null, InputOption::VALUE_REQUIRED, 'Exit after this many jobs (0 = no limit)', $defaults['max-jobs']),
            new InputOption('memory', null, InputOption::VALUE_REQUIRED, 'Exit when peak memory reaches this (e.g. 512M; a bare number is MB). Default: the QueuedJobService memory_limit'),
            // new InputOption('job', null, InputOption::VALUE_REQUIRED, 'Run only this job descriptor (ID, or queuedjob-ID), then exit'),  // old: removed in 1.7.0
            // new InputOption('stop-when-empty', null, InputOption::VALUE_NONE, 'Exit as soon as the queue is empty'),
            # Still accepted, because bin/qje-drain.sh (and existing crons or scripts) pass it; without it
            # Symfony would reject the command line. It changes nothing.
            new InputOption('stop-when-empty', null, InputOption::VALUE_NONE, 'No effect since 1.7.0: the runner always exits when the queue is empty (accepted for compatibility)'),
            # No default here: an absent option falls back to the QueueWorker.linger config
            // new InputOption('linger', null, InputOption::VALUE_REQUIRED, 'With --stop-when-empty: seconds to keep looking for new jobs before exiting (default: the QueueWorker.linger config, 2; 0 = exit at once)'),
            new InputOption('linger', null, InputOption::VALUE_REQUIRED, 'Seconds to keep looking for new jobs before exiting when the queue is empty (default: the QueueWorker.linger config, 2; 0 = exit at once)'),
            # The start request lane of the drain spawn (DrainSpawner::requestStart())
            new InputOption('requested', null, InputOption::VALUE_NONE, 'Run only the jobs that asked to start at once (queued with ImmediateJobRunner::queueAndStart()), whatever their queue, instead of --queue'),
        ];
    }
}
