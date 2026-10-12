<?php

namespace Restruct\QueuedJobsEnhancements\Tasks;

use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;

/*
 * Version-specific entry point for CleanupBrokenJobsTask.
 *
 * WHY THIS FILE DECLARES THE SAME TRAIT TWICE
 *
 * BuildTask changed shape between Silverstripe 5 and 6 in ways one class body cannot satisfy:
 *  - SS5: `abstract public function run($request)`, untyped `protected $title` / `$description`.
 *  - SS6: `run(InputInterface, PolyOutput): int` is concrete and `execute()` is the abstract hook;
 *    `protected string $title` and `protected static string $description` are typed, so a child
 *    redeclaring them untyped (or typed, on SS5) is a fatal "must be compatible" error.
 *
 * Two task classes with a file-level `return` guard each does NOT work for a BuildTask: the class
 * manifest still lists the guarded class as a BuildTask descendant, and the task runner reflects
 * on every descendant, which throws for a class that was never declared. So the task class is
 * declared once, and pulls its version-specific METHODS from this trait. The trait is declared
 * conditionally, which PHP allows; Composer's PSR-4 autoloader resolves it by file name. Same
 * pattern as restruct/silverstripe-signed-asset-urls' SignedAssetUrlVerifyTaskEntryPoint.
 *
 * PolyOutput exists only on Silverstripe 6 (framework 6 `src/PolyExecution/`), so its presence is
 * the version switch. The `use` imports above are inert on SS5: an import never autoloads.
 */
if (class_exists(PolyOutput::class)) {
    /**
     * Silverstripe 6: sake command `tasks:cleanup-broken-jobs`, or dev/tasks/cleanup-broken-jobs.
     */
    trait CleanupBrokenJobsTaskEntryPoint
    {
        /**
         * SS6 declares getDescription() static (PolyCommand::getDescription()).
         */
        public static function getDescription(): string
        {
            return static::DESCRIPTION;
        }

        protected function execute(InputInterface $input, PolyOutput $output): int
        {
            $this->cleanup(function (string $message) use ($output): void {
                # OUTPUT_RAW: job titles are free text and must not be read as console tags
                $output->writeln($message, PolyOutput::OUTPUT_RAW);
            });

            return Command::SUCCESS;
        }
    }
} else {
    /**
     * Silverstripe 5: dev/tasks/cleanup-broken-jobs (browser or `sake dev/tasks/...`).
     */
    trait CleanupBrokenJobsTaskEntryPoint
    {
        /**
         * SS5 declares getDescription() as an instance method.
         *
         * @return string
         */
        public function getDescription()
        {
            return static::DESCRIPTION;
        }

        /**
         * @param \SilverStripe\Control\HTTPRequest|null $request
         * @return void
         */
        public function run($request)
        {
            $this->cleanup(function (string $message): void {
                $this->output($message);
            });
        }
    }
}
