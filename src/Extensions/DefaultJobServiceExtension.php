<?php

namespace Restruct\QueuedJobsEnhancements\Extensions;

use Restruct\QueuedJobsEnhancements\Services\DefaultJobManager;
use SilverStripe\Core\Extension;

/**
 * Extension on QueuedJobsAdmin that ensures self-declaring default jobs
 * are in the queue whenever an admin visits the QueuedJobs admin.
 *
 * For cron-based checking, add CheckDefaultJobsTask to your crontab:
 *   * * * * * php cli-script.php dev/tasks/check-default-jobs
 *
 * @property \Symbiote\QueuedJobs\Controllers\QueuedJobsAdmin&DefaultJobServiceExtension $owner
 */
class DefaultJobServiceExtension extends Extension
{
    /**
     * Hook into QueuedJobsAdmin init to ensure self-declaring default jobs exist.
     * This runs when someone visits /admin/queuedjobs/ — a natural place to
     * verify job health alongside the existing defaultJobs mechanism.
     */
    protected function onBeforeInit(): void
    {
        DefaultJobManager::ensureDefaultJobs();
    }
}
