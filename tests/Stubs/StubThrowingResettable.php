<?php

namespace Restruct\QueuedJobsEnhancements\Tests\Stubs;

use RuntimeException;
use SilverStripe\Core\Resettable;

/**
 * Test stub: a Resettable whose reset() throws while armed. Unarmed it does nothing, because
 * FlushableTestState calls reset() on every implementor before every test of the suite, and an
 * always-throwing stub would break all of them. QueueWorkerResettablesTest arms it for one test and
 * disarms it in tearDown().
 */
class StubThrowingResettable implements Resettable
{
    public static bool $armed = false;

    /** @var int how often reset() was called while armed */
    public static int $attempts = 0;

    public static function reset()
    {
        if (!static::$armed) {
            return;
        }
        static::$attempts++;

        throw new RuntimeException('stub reset() failure');
    }
}
