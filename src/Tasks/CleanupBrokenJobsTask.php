<?php

namespace Restruct\QueuedJobsEnhancements\Tasks;

use SilverStripe\Dev\BuildTask;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Symbiote\QueuedJobs\Services\QueuedJob;

/**
 * Delete all broken queued jobs.
 *
 * Usage:
 *   Silverstripe 5: sake dev/tasks/cleanup-broken-jobs
 *   Silverstripe 6: sake tasks:cleanup-broken-jobs
 *
 * Consolidated from Restruct\Silverstripe\AdminTweaks\Dev\CleanupBrokenJobsTask.
 *
 * The entry point (run() on SS5, execute() on SS6) and getDescription() come from
 * CleanupBrokenJobsTaskEntryPoint, because BuildTask's shape differs between the two majors in
 * ways one class body cannot satisfy - see that file for why it is done this way.
 *
 * @config string $segment
 */
class CleanupBrokenJobsTask extends BuildTask
{
    use CleanupBrokenJobsTaskEntryPoint;

    /**
     * Silverstripe 5 URL segment (dev/tasks/cleanup-broken-jobs). Inert config on Silverstripe 6,
     * where $commandName names the task instead.
     */
    private static string $segment = 'cleanup-broken-jobs';

    /**
     * Silverstripe 6 command name (sake tasks:cleanup-broken-jobs). Declared here, not in the
     * trait: SS5's BuildTask has no such property, so this is a new static there, and on SS6 it is
     * a compatible redeclaration of PolyCommand::$commandName (same type, same staticness).
     */
    protected static string $commandName = 'cleanup-broken-jobs';

    // Were declared as properties. Silverstripe 6 types BuildTask::$title as `string` and makes
    // $description a typed static, so either redeclaration is a fatal "must be compatible" error
    // there, on class load - and the class manifest loads every class on a flush, so the whole
    // application went down with it. The title is now assigned in the constructor, the
    // description served by getDescription() from the entry-point trait.
//    protected $title = 'Cleanup Broken Jobs';
//
//    protected $description = 'Deletes all queued jobs with status "Broken"';

    /**
     * Shared by both majors' getDescription() (see the entry-point trait).
     */
    public const DESCRIPTION = 'Deletes all queued jobs with status "Broken"';

    public function __construct()
    {
        parent::__construct();
        # Assigned rather than declared: BuildTask::$title is untyped on SS5 and `string` on SS6,
        # and no single redeclaration is compatible with both.
        $this->title = 'Cleanup Broken Jobs';
    }

    /**
     * Version-neutral body of the task, writing every line through $write.
     *
     * @param callable $write function (string $message): void
     */
    public function cleanup(callable $write): void
    {
        $broken = QueuedJobDescriptor::get()->filter('JobStatus', QueuedJob::STATUS_BROKEN);
        $count = $broken->count();

        if ($count === 0) {
            $write('No broken jobs found.');
            return;
        }

        $write("Found {$count} broken job(s). Deleting...");

        foreach ($broken as $job) {
            $write("  - Deleting: {$job->JobTitle} (ID: {$job->ID})");
            $job->delete();
        }

        $write("Done. Deleted {$count} broken job(s).");
    }

    protected function output(string $message): void
    {
        echo $message . PHP_EOL;
    }
}
