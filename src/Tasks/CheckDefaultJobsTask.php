<?php

namespace Restruct\QueuedJobsEnhancements\Tasks;

use Restruct\QueuedJobsEnhancements\Services\DefaultJobManager;
use SilverStripe\Dev\BuildTask;

/**
 * Ensures self-declaring default jobs are in the queue, and shows diagnostic info.
 *
 * Discovers all job classes with `$is_default_job = true`, checks whether
 * they have an active instance in the queue, and creates missing ones.
 *
 * Usage:
 *   sake dev/tasks/check-default-jobs              # ensure + report
 *   sake dev/tasks/check-default-jobs dry-run=1    # report only, don't create
 *
 * Add to crontab alongside ProcessJobQueueTask for automatic self-declaring job management.
 *
 * @config string $segment
 */
class CheckDefaultJobsTask extends BuildTask
{
    private static string $segment = 'check-default-jobs';

    protected $title = 'Check Default Jobs';

    protected $description = 'Ensures self-declaring default jobs ($is_default_job = true) are in the queue. Creates missing ones.';

    public function run($request): void
    {
        $dryRun = (bool) ($request->getVar('dry-run') ?: $request->getVar('dry_run'));

        $this->output('=== Self-Declaring Default Jobs ===');
        $this->output('');

        if ($dryRun) {
            $this->output('** DRY RUN — no jobs will be created **');
            $this->output('');
            $this->showDiagnostic();
        } else {
            $this->ensureAndReport();
        }
    }

    protected function ensureAndReport(): void
    {
        $results = DefaultJobManager::ensureDefaultJobs();

        if (empty($results)) {
            $this->output('No job classes with $is_default_job = true found.');
            $this->output('');
            $this->output('To make a job self-declaring, add to your job class:');
            $this->output('  private static bool $is_default_job = true;');
            $this->output('  private static string $default_schedule = \'+5 minutes\';');
            return;
        }

        $discovered = DefaultJobManager::discoverDefaultJobs();

        foreach ($results as $shortName => $action) {
            $class = $discovered[$shortName]['class'] ?? '?';
            $schedule = $discovered[$shortName]['schedule'] ?? '?';

            $label = match ($action) {
                'exists' => 'OK (active)',
                'created' => 'CREATED',
                'error' => 'ERROR',
                default => $action,
            };

            $this->output(sprintf('  [%s] %s', $label, $shortName));
            $this->output(sprintf('         Class: %s', $class));
            $this->output(sprintf('         Schedule: %s', $schedule));
            $this->output('');
        }

        $created = array_filter($results, fn($a) => $a === 'created');
        if (!empty($created)) {
            $this->output(sprintf('Created %d missing default job(s).', count($created)));
        }
    }

    protected function showDiagnostic(): void
    {
        $summary = DefaultJobManager::diagnosticSummary();

        if (empty($summary)) {
            $this->output('No job classes with $is_default_job = true found.');
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
    }

    protected function output(string $message): void
    {
        echo $message . PHP_EOL;
    }
}
