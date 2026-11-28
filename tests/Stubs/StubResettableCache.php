<?php

namespace Restruct\QueuedJobsEnhancements\Tests\Stubs;

use SilverStripe\Core\Resettable;

/**
 * Test stub: an in-memory cache service of the kind Silverstripe expects to implement Resettable.
 * Its static state lives as long as the PHP process, so in a runner that works several jobs in one
 * process it carries from one job into the next unless something calls reset() in between
 * (see QueueWorkerResettablesTest).
 */
class StubResettableCache implements Resettable
{
    /** @var string|null the value the last StubResettableCacheJob left behind */
    public static ?string $value = null;

    /** @var int how often reset() ran; the test reads it to prove a reset happened */
    public static int $resets = 0;

    public static function reset()
    {
        static::$value = null;
        static::$resets++;
    }
}
