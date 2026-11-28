<?php

namespace Restruct\QueuedJobsEnhancements\Tests\Stubs;

use Restruct\QueuedJobsEnhancements\Worker\QueueWorker;

/**
 * The worker without real signal handlers, as QueueWorkerTest's TestQueueWorker: pcntl handlers
 * installed in the PHPUnit process would outlive the test. A class file of its own (unlike
 * TestQueueWorker, which lives inside QueueWorkerTest.php) so the autoloader can find it from any
 * test file, in any load order.
 */
class StubSignallessQueueWorker extends QueueWorker
{
    protected function registerSignalHandlers(): void
    {
    }
}
