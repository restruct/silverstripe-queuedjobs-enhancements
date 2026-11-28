<?php

namespace Restruct\QueuedJobsEnhancements\Tests\Stubs;

/**
 * Resets StubThrowingResettable before every other Resettable, so the throwing-implementor test in
 * QueueWorkerResettablesTest does not depend on the class manifest's order.
 */
class StubThrowingFirstQueueWorker extends StubSignallessQueueWorker
{
    protected function resettableClasses(): array
    {
        $classes = array_values(array_filter(
            parent::resettableClasses(),
            fn (string $class) => $class !== StubThrowingResettable::class
        ));
        array_unshift($classes, StubThrowingResettable::class);

        return $classes;
    }
}
