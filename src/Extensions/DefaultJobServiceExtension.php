<?php

namespace Restruct\QueuedJobsEnhancements\Extensions;

use Psr\SimpleCache\CacheInterface;
use Restruct\QueuedJobsEnhancements\Services\DefaultJobManager;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Extension;
use SilverStripe\Core\Injector\Injector;

/**
 * Extension on QueuedJobsAdmin that ensures self-declaring default jobs
 * are in the queue whenever an admin visits the QueuedJobs admin.
 *
 * For cron-based checking, add CheckDefaultJobsTask to your crontab:
 *   * * * * * php cli-script.php dev/tasks/check-default-jobs
 *
 * @config int $check_interval
 * @property \Symbiote\QueuedJobs\Controllers\QueuedJobsAdmin&DefaultJobServiceExtension $owner
 */
class DefaultJobServiceExtension extends Extension
{
    use Configurable;

    /**
     * Minimum seconds between default-job checks. The hook fires on every request to the
     * admin section (including XHR / form-schema / GridField requests), so without a
     * throttle one page view can trigger the check many times. Set to 0 to check always.
     */
    private static int $check_interval = 300;

    /**
     * Ensure self-declaring default jobs exist. Runs when someone visits
     * /admin/queuedjobs/ — a natural place to verify job health alongside the
     * existing defaultJobs mechanism.
     *
     * Hooked on onAfterInit rather than onBeforeInit: Controller::doInit() fires
     * onBeforeInit *before* it calls $this->init(), and LeftAndMain's canView()
     * permission check lives inside init(). On onBeforeInit an unauthenticated request
     * to /admin/queuedjobs would run this (and potentially write job descriptors)
     * before being redirected to login.
     *
     * doInit() calls onAfterInit unconditionally — even when init() bailed out via
     * Security::permissionFailure() — so the canView() guard below is still required.
     */
    protected function onAfterInit(): void
    {
        if (!$this->owner->canView()) {
            return;
        }

        if (!$this->shouldCheckNow()) {
            return;
        }

        DefaultJobManager::ensureDefaultJobs();
    }

    /**
     * Throttle the check to at most once per `check_interval` seconds.
     * Returns true (consuming the interval) when a check is due.
     */
    protected function shouldCheckNow(): bool
    {
        $interval = (int) static::config()->get('check_interval');
        if ($interval <= 0) {
            return true;
        }

        try {
            /** @var CacheInterface $cache */
            $cache = Injector::inst()->get(CacheInterface::class . '.queuedjobsEnhancements');
        } catch (\Throwable $e) {
            # Cache unavailable — degrade to checking, never block the admin
            return true;
        }

        if ($cache->get('default_jobs_checked')) {
            return false;
        }

        $cache->set('default_jobs_checked', true, $interval);

        return true;
    }
}
