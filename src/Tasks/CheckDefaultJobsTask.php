<?php

namespace Restruct\QueuedJobsEnhancements\Tasks;

use Restruct\QueuedJobsEnhancements\Services\DefaultJobManager;
use SilverStripe\Dev\BuildTask;

/**
 * Diagnostic report for self-declaring default jobs ($is_default_job = true).
 *
 * Shows which job classes declare themselves as default jobs and whether each has an
 * active instance in the queue. This task NEVER creates jobs — scheduling is core's
 * responsibility via the `defaultJobs` config, which `QueuedJobService::runQueue()`
 * re-checks on every `ProcessJobQueueTask` run.
 *
 * Usage:
 *   Silverstripe 5:
 *     sake dev/tasks/check-default-jobs           # report declared jobs + queue status
 *     sake dev/tasks/check-default-jobs yaml=1    # print the defaultJobs YAML to paste
 *   Silverstripe 6:
 *     sake tasks:check-default-jobs
 *     sake tasks:check-default-jobs --yaml
 *
 * The entry point (run() on SS5, execute() + getOptions() on SS6) and getDescription() come from
 * CheckDefaultJobsTaskEntryPoint, because BuildTask's shape differs between the two majors in
 * ways one class body cannot satisfy - see that file for why it is done this way.
 *
 * @config string $segment
 */
class CheckDefaultJobsTask extends BuildTask
{
    use CheckDefaultJobsTaskEntryPoint;

    /**
     * Silverstripe 5 URL segment (dev/tasks/check-default-jobs). Inert config on Silverstripe 6,
     * where $commandName names the task instead.
     */
    private static string $segment = 'check-default-jobs';

    /**
     * Silverstripe 6 command name (sake tasks:check-default-jobs). A compatible redeclaration of
     * PolyCommand::$commandName on SS6, a new unused static on SS5.
     */
    protected static string $commandName = 'check-default-jobs';

    // Were declared as properties; a fatal "must be compatible" error on SS6, where BuildTask
    // types $title and makes $description a typed static (see CleanupBrokenJobsTask).
//    protected $title = 'Check Default Jobs';
//
//    protected $description = 'Reports job classes declaring $is_default_job = true and whether they are queued. '
//        . 'Creates nothing. Use yaml=1 to emit a defaultJobs config block.';

    /**
     * Shared by both majors' getDescription() (see the entry-point trait).
     */
    public const DESCRIPTION = 'Reports job classes declaring $is_default_job = true and whether they are queued. '
        . 'Creates nothing. Use yaml=1 (SS5) or --yaml (SS6) to emit a defaultJobs config block.';

    /**
     * Where outputReport()/outputYaml() write to. Set per run by the entry point: echo on SS5,
     * PolyOutput on SS6.
     *
     * @var callable|null
     */
    protected $writer = null;

    public function __construct()
    {
        parent::__construct();
        # Assigned rather than declared: untyped on SS5, `string` on SS6 (see above)
        $this->title = 'Check Default Jobs';
    }

    /**
     * Version-neutral body of the task.
     *
     * @param callable $write function (string $message): void
     * @param bool $yaml true to print the defaultJobs YAML block instead of the report
     */
    public function check(callable $write, bool $yaml = false): void
    {
        $this->writer = $write;

        if ($yaml) {
            $this->outputYaml();
            return;
        }

        $this->outputReport();
    }

    /**
     * Report every declared default job and whether it is currently active in the queue.
     */
    protected function outputReport(): void
    {
        $this->output('=== Self-Declaring Default Jobs ===');
        $this->output('');

        $summary = DefaultJobManager::diagnosticSummary();

        if (empty($summary)) {
            $this->output('No job classes with $is_default_job = true found.');
            $this->output('');
            $this->output('To make a job self-declaring, add to your job class:');
            $this->output('  private static bool $is_default_job = true;');
            $this->output('  private static string $default_schedule = \'+5 minutes\';');
            return;
        }

        foreach ($summary as $shortName => $info) {
            $status = $info['queued']
                ? "ACTIVE ({$info['status']})"
                : 'MISSING';

            $this->output(sprintf('  [%s] %s', $status, $shortName));
            $this->output(sprintf('         Class: %s', $info['class']));
            $this->output(sprintf('         Schedule: %s', $info['schedule']));
            $this->output('');
        }

        $missing = array_filter($summary, fn($info) => !$info['queued']);
        if (!empty($missing)) {
            $this->output(sprintf('%d job(s) are not currently queued.', count($missing)));
            $this->output('');
        }

        # This task deliberately creates nothing — point at the mechanism that does
        $this->output('This task does not create jobs. To have them created and kept alive,');
        $this->output('add them to the core `defaultJobs` config. Run with yaml=1 to print it:');
        $this->output('  sake dev/tasks/check-default-jobs yaml=1   (Silverstripe 6: sake tasks:check-default-jobs --yaml)');
    }

    /**
     * Emit the `defaultJobs` YAML block for the discovered jobs, ready to paste.
     */
    protected function outputYaml(): void
    {
        $yaml = DefaultJobManager::toDefaultJobsYaml();

        if ($yaml === '') {
            $this->output('# No job classes with $is_default_job = true found.');
            return;
        }

        $this->output('# Paste into e.g. app/_config/queuedjobs.yml');
        $this->output('# Core re-checks these on every ProcessJobQueueTask run (QueuedJobService::runQueue()).');
        $this->output('');
        $this->output($yaml);
    }

    protected function output(string $message): void
    {
        # The entry point sets the writer; echo stays the fallback for a direct call
        if ($this->writer) {
            ($this->writer)($message);
            return;
        }
        echo $message . PHP_EOL;
    }
}
