<?php

namespace Restruct\QueuedJobsEnhancements\Tests\Stubs;

/**
 * Shared, in-process state for the worker test stubs: the order jobs ran in, and an optional
 * callback run at each step of StubMultiStepJob. Reset by the tests in setUp().
 */
class StubWorkerHooks
{
    /** @var string[] labels of the job steps run, in order */
    public static array $ran = [];

    /** @var callable|null function (string $label, int $step): void */
    public static $onStep = null;

    public static function reset(): void
    {
        static::$ran = [];
        static::$onStep = null;
    }

    public static function record(string $label): void
    {
        static::$ran[] = $label;
    }
}
