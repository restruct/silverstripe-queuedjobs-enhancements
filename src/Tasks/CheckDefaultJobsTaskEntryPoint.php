<?php

namespace Restruct\QueuedJobsEnhancements\Tasks;

use SilverStripe\Control\Director;
use SilverStripe\Core\Convert;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/*
 * Version-specific entry point for CheckDefaultJobsTask.
 *
 * Declared twice, conditionally, for the same reason as CleanupBrokenJobsTaskEntryPoint (see that
 * file): BuildTask's SS5 and SS6 shapes cannot both be satisfied by one class body, and a
 * file-level `return` guard does not work for a BuildTask. PolyOutput exists only on
 * Silverstripe 6, so its presence is the version switch.
 */
if (class_exists(PolyOutput::class)) {
    /**
     * Silverstripe 6: `sake tasks:check-default-jobs [--yaml]`.
     */
    trait CheckDefaultJobsTaskEntryPoint
    {
        public static function getDescription(): string
        {
            return static::DESCRIPTION;
        }

        /**
         * SS6 replacement for SS5's `yaml=1` request var. In a browser, dev/tasks maps query
         * vars onto these options, so ?yaml=1 still works there.
         */
        public function getOptions(): array
        {
            return [
                new InputOption('yaml', null, InputOption::VALUE_NONE, 'Print the defaultJobs YAML block to paste'),
            ];
        }

        protected function execute(InputInterface $input, PolyOutput $output): int
        {
            $this->check(function (string $message) use ($output): void {
                # YAML, class names and job statuses: escaped as console tags and written NORMAL,
                # so they print literally on the CLI and are HTML-escaped by the browser task
                # runner's HTML formatter
                // old: OUTPUT_RAW also skips the HTML formatter, so markup reached the browser
                // # OUTPUT_RAW: YAML and class names must not be read as console formatting tags
                // $output->writeln($message, PolyOutput::OUTPUT_RAW);
                $output->writeln(OutputFormatter::escape($message));
            }, (bool) $input->getOption('yaml'));

            return Command::SUCCESS;
        }
    }
} else {
    /**
     * Silverstripe 5: `sake dev/tasks/check-default-jobs [yaml=1]`.
     */
    trait CheckDefaultJobsTaskEntryPoint
    {
        /**
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
            # Echo through output()'s fallback, exactly as before the split; HTML-escaped when
            # the task runs in a browser, where the task runner's page is HTML
            $html = $this->writesHtml();
            $this->check(function (string $message) use ($html): void {
                // old: echoed as is, also in the browser
                // echo $message . PHP_EOL;
                echo ($html ? Convert::raw2xml($message) : $message) . PHP_EOL;
            }, (bool) ($request && $request->getVar('yaml')));
        }

        /**
         * Whether the output is a browser page (dev/tasks over HTTP) rather than a terminal. A
         * method so that a test can pretend to be a browser: Director::is_cli() asks the SAPI.
         */
        protected function writesHtml(): bool
        {
            return !Director::is_cli();
        }
    }
}
