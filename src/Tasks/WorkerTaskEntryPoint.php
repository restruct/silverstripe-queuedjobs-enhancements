<?php

namespace Restruct\QueuedJobsEnhancements\Tasks;

use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;

/*
 * Version-specific entry point for the Silverstripe 5 worker tasks (QueuedJobsWorkTask,
 * QueuedJobsRestartTask).
 *
 * On Silverstripe 6 the worker is a sake command of its own (queuedjobs:work, queuedjobs:restart),
 * and these tasks are switched off in _config/worker.yml. They still have to LOAD there, because the
 * task runner reflects on every BuildTask descendant, and BuildTask's shape differs between the
 * majors in ways one class body cannot satisfy. Hence the same pattern as
 * CleanupBrokenJobsTaskEntryPoint (see that file for the full reasoning): one class, with its
 * version-specific methods pulled from a conditionally declared trait.
 *
 * The using class provides DESCRIPTION, SS6_COMMAND and runTask(HTTPRequest $request).
 */
if (class_exists(PolyOutput::class)) {
    /**
     * Silverstripe 6: disabled by config; if enabled anyway, point at the sake command.
     */
    trait WorkerTaskEntryPoint
    {
        public static function getDescription(): string
        {
            return static::DESCRIPTION;
        }

        protected function execute(InputInterface $input, PolyOutput $output): int
        {
            $output->writeln(sprintf('On Silverstripe 6, run `sake %s` instead.', static::SS6_COMMAND));

            return Command::FAILURE;
        }
    }
} else {
    /**
     * Silverstripe 5: dev/tasks/<segment>, from the CLI.
     */
    trait WorkerTaskEntryPoint
    {
        /**
         * @return string
         */
        public function getDescription()
        {
            return static::DESCRIPTION;
        }

        /**
         * @param \SilverStripe\Control\HTTPRequest $request
         * @return void
         */
        public function run($request)
        {
            $this->runTask($request);
        }
    }
}
