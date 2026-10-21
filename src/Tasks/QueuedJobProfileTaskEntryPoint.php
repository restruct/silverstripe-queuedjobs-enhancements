<?php

namespace Restruct\QueuedJobsEnhancements\Tasks;

use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/*
 * Version-specific entry point for QueuedJobProfileTask: run() on Silverstripe 5, execute() and
 * getOptions() on Silverstripe 6. Declared twice, conditionally, for the reasons given in
 * CleanupBrokenJobsTaskEntryPoint (one BuildTask class body cannot satisfy both majors, and a
 * file-level return guard on the task class itself breaks the task runner).
 *
 * PolyOutput exists only on Silverstripe 6, so its presence is the version switch. The `use`
 * imports above are inert on SS5: an import never autoloads.
 */
if (class_exists(PolyOutput::class)) {
    /**
     * Silverstripe 6: sake command `tasks:qjob-profile --class=<FQCN> [--maxcalls=N]`.
     */
    trait QueuedJobProfileTaskEntryPoint
    {
        /**
         * SS6 declares getDescription() static (PolyCommand::getDescription()).
         */
        public static function getDescription(): string
        {
            return static::DESCRIPTION;
        }

        /**
         * symfony/console OPTIONS, so the same spelling works on the CLI and in the browser task
         * runner. (Under SS5 the CLI wanted space-separated `class=…` and the browser a query string,
         * and mixing them up silently returned just the job list.)
         */
        public function getOptions(): array
        {
            return [
                new InputOption('class', null, InputOption::VALUE_REQUIRED, 'Fully qualified queued job class to profile'),
                new InputOption('maxcalls', null, InputOption::VALUE_REQUIRED, 'Stop after this many process() calls (default 500)'),
            ];
        }

        public static function usage(): string
        {
            return 'sake tasks:qjob-profile --class=<FQCN> [--maxcalls=N]';
        }

        protected function execute(InputInterface $input, PolyOutput $output): int
        {
            # Writing through PolyOutput rather than echo/printf, so the browser task runner renders
            # this the same way the CLI does. Plain lines are written RAW: class names and job phases
            # are free text and must not be read as console tags; errors get the error style, with
            # their text escaped for the same reason.
            return $this->profile(
                (string) $input->getOption('class'),
                (int) $input->getOption('maxcalls'),
                function (string $line, bool $isError = false) use ($output): void {
                    if ($isError) {
                        $output->writeln('<error>' . OutputFormatter::escape($line) . '</>');
                        return;
                    }
                    $output->writeln($line, PolyOutput::OUTPUT_RAW);
                }
            );
        }
    }
} else {
    /**
     * Silverstripe 5: dev/tasks/qjob-profile (browser, or `sake dev/tasks/qjob-profile class=...`).
     */
    trait QueuedJobProfileTaskEntryPoint
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

        public static function usage(): string
        {
            return 'dev/tasks/qjob-profile?class=<FQCN>[&maxcalls=N] (CLI: sake dev/tasks/qjob-profile class=<FQCN> [maxcalls=N])';
        }

        /**
         * @param \SilverStripe\Control\HTTPRequest|null $request
         * @return void
         */
        public function run($request)
        {
            $this->profile(
                $request ? (string) $request->getVar('class') : '',
                $request ? (int) $request->getVar('maxcalls') : 0,
                function (string $line, bool $isError = false): void {
                    # Plain text, as the task printed before the move (no HTML in the browser either)
                    echo $line . PHP_EOL;
                }
            );
        }
    }
}
