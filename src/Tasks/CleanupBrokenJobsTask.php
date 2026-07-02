<?php

namespace Restruct\QueuedJobsEnhancements\Tasks;

use SilverStripe\Dev\BuildTask;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Symbiote\QueuedJobs\Services\QueuedJob;

/**
 * Delete all broken queued jobs.
 *
 * Usage: sake dev/tasks/cleanup-broken-jobs
 *
 * Consolidated from Restruct\Silverstripe\AdminTweaks\Dev\CleanupBrokenJobsTask.
 *
 * @config string $segment
 */
class CleanupBrokenJobsTask extends BuildTask
{
    private static string $segment = 'cleanup-broken-jobs';

    protected $title = 'Cleanup Broken Jobs';

    protected $description = 'Deletes all queued jobs with status "Broken"';

    public function run($request): void
    {
        $broken = QueuedJobDescriptor::get()->filter('JobStatus', QueuedJob::STATUS_BROKEN);
        $count = $broken->count();

        if ($count === 0) {
            $this->output('No broken jobs found.');
            return;
        }

        $this->output("Found {$count} broken job(s). Deleting...");

        foreach ($broken as $job) {
            $this->output("  - Deleting: {$job->JobTitle} (ID: {$job->ID})");
            $job->delete();
        }

        $this->output("Done. Deleted {$count} broken job(s).");
    }

    protected function output(string $message): void
    {
        echo $message . PHP_EOL;
    }
}
