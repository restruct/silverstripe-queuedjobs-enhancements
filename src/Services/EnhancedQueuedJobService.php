<?php

namespace Restruct\QueuedJobsEnhancements\Services;

use Symbiote\QueuedJobs\Services\QueuedJobService;

/**
 * The module's QueuedJobService subclass — the single Injector swap target for all of this
 * module's service-level enhancements. Injector `class:` is one-winner-per-service-name, so
 * new service-level features are added HERE (traits/methods) rather than as competing
 * subclasses; consumers only ever swap once.
 *
 * Current features:
 *  - ConcurrencyGuardedMutex: per-job-class concurrency guard (opt-in via
 *    `private static bool $prevent_concurrent_runs = true` on the job class).
 *
 * OPT-IN: the module does NOT register this swap — enable it per project:
 *
 *     SilverStripe\Core\Injector\Injector:
 *       Symbiote\QueuedJobs\Services\QueuedJobService:
 *         class: Restruct\QueuedJobsEnhancements\Services\EnhancedQueuedJobService
 *
 * An existing `properties:` block (defaultJobs etc.) on that same injector key keeps
 * applying — `class:` and `properties:` merge into the one service spec.
 *
 * Already running your own QueuedJobService subclass? Don't swap to this class — add
 * `use ConcurrencyGuardedMutex;` to your subclass instead (see that trait's docblock).
 */
class EnhancedQueuedJobService extends QueuedJobService
{
    use ConcurrencyGuardedMutex;
}
