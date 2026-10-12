<?php

namespace Restruct\QueuedJobsEnhancements\Cli;

use Restruct\QueuedJobsEnhancements\Worker\WorkerRestartSignal;
use SilverStripe\PolyExecution\PolyCommand;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;

# Silverstripe 5 has no PolyCommand. The config system class_exists()es EVERY class in the manifest
# on a flush (PrivateStaticTransformer) and that autoloads this file, so without this guard the
# missing parent class fatals the whole SS5 application on flush/dev/build. The YAML `Only:
# classexists` in _config/worker.yml does not prevent that (SSKB local-module-dev.md, "A class
# extending an OPTIONAL dependency fatals the WHOLE application at bootstrap").
if (!class_exists(PolyCommand::class)) {
    return;
}

/**
 * Silverstripe 6 only: `sake queuedjobs:restart`. Tells every running queuedjobs:work worker to exit
 * after its current job, so Supervisor starts it again on the new code (Laravel's `queue:restart`).
 * Run it from the deploy script after the new release is live. SS5 entry point: QueuedJobsRestartTask.
 */
class QueueRestartCommand extends PolyCommand
{
    protected static string $commandName = 'queuedjobs:restart';

    protected static string $description = 'Signal queuedjobs:work workers to exit after their current job';

    private static bool $can_run_in_browser = false;

    public function getTitle(): string
    {
        return 'Restart queued jobs workers';
    }

    public function run(InputInterface $input, PolyOutput $output): int
    {
        WorkerRestartSignal::requestRestart();
        $output->writeln('Restart signal stored; workers exit after their current job.');

        return Command::SUCCESS;
    }
}
