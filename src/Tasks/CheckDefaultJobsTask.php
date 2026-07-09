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
 *   sake dev/tasks/check-default-jobs           # report declared jobs + queue status
 *   sake dev/tasks/check-default-jobs yaml=1    # print the defaultJobs YAML to paste
 *
 * @config string $segment
 */
class CheckDefaultJobsTask extends BuildTask
{
    private static string $segment = 'check-default-jobs';

    protected $title = 'Check Default Jobs';

    protected $description = 'Reports job classes declaring $is_default_job = true and whether they are queued. '
        . 'Creates nothing. Use yaml=1 to emit a defaultJobs config block.';

    public function run($request): void
    {
        if ($request && $request->getVar('yaml')) {
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
        $this->output('  sake dev/tasks/check-default-jobs yaml=1');
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
        echo $message . PHP_EOL;
    }
}
