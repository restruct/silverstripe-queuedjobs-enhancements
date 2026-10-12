<?php

namespace Restruct\QueuedJobsEnhancements\Tasks;

use Restruct\QueuedJobsEnhancements\Worker\WorkerRestartSignal;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Dev\BuildTask;

/**
 * Silverstripe 5: tell every running worker (QueuedJobsWorkTask) to exit after its current job,
 * so Supervisor starts it again on the new code (Laravel's `queue:restart`). Run it from the deploy
 * script once the new release is live:
 *   php vendor/silverstripe/framework/cli-script.php dev/tasks/QueuedJobsRestartTask
 *
 * Silverstripe 6: `sake queuedjobs:restart` (QueueRestartCommand); this task is disabled there.
 *
 * @config string $segment
 */
class QueuedJobsRestartTask extends BuildTask
{
    use WorkerTaskEntryPoint;

    /**
     * Silverstripe 5 URL segment: dev/tasks/QueuedJobsRestartTask
     */
    private static string $segment = 'QueuedJobsRestartTask';

    /**
     * Silverstripe 6 command name (unused there: the task is disabled, see _config/worker.yml)
     */
    protected static string $commandName = 'QueuedJobsRestartTask';

    public const DESCRIPTION = 'Signal running queue workers to exit after their current job (restart on deploy).';

    public const SS6_COMMAND = 'queuedjobs:restart';

    public function __construct()
    {
        parent::__construct();
        # Assigned rather than declared: untyped on SS5, `string` on SS6 (see CleanupBrokenJobsTask)
        $this->title = 'Restart queued jobs workers';
    }

    /**
     * @param HTTPRequest $request
     */
    protected function runTask($request): void
    {
        WorkerRestartSignal::requestRestart();
        echo 'Restart signal stored; workers exit after their current job.' . PHP_EOL;
    }
}
