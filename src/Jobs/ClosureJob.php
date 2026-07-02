<?php

namespace Restruct\QueuedJobsEnhancements\Jobs;

use Opis\Closure\SerializableClosure;
use Restruct\QueuedJobsEnhancements\Services\ImmediateJobRunner;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\FieldType\DBDatetime;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJobService;

/**
 * Queue a closure for background execution — no dedicated job class needed.
 *
 * Usage:
 *   ClosureJob::dispatch(
 *       function () use ($itemId) {
 *           $item = MyModel::get()->byID($itemId);
 *           $item->doExpensiveWork();
 *       },
 *       description: 'Process item #' . $itemId
 *   );
 *
 *   // Immediate (CLI spawn, no cron wait):
 *   ClosureJob::dispatchImmediate(fn() => MyService::cleanup(), 'Run cleanup');
 *
 * Closures are serialized via Opis\Closure\SerializableClosure (SS5, installed
 * via silverstripe/closure). For SS6, swap to Laravel\SerializableClosure.
 *
 * Note on double serialization: The closure is wrapped in SerializableClosure
 * and then serialize()'d to a string BEFORE storing in jobData. When QueuedJobs
 * serializes the jobData, it just sees a plain string — no closure objects in
 * the serialization path.
 *
 * @property string $serializedClosure Pre-serialized SerializableClosure string (jobData)
 * @property string $description Human-readable job description (jobData)
 */
class ClosureJob extends AbstractQueuedJob
{
    /**
     * Queue a closure for background execution.
     *
     * @param \Closure $closure The closure to execute
     * @param string $description Human-readable description for CMS display
     * @param string $when When to execute (strtotime-compatible, default: 'now')
     * @return int QueuedJobDescriptor ID
     */
    public static function dispatch(\Closure $closure, string $description = '', string $when = 'now'): int
    {
        $job = Injector::inst()->create(self::class, $closure, $description);
        return QueuedJobService::singleton()->queueJob(
            $job,
            $when !== 'now' ? DBDatetime::create()->setValue(strtotime($when))->Rfc2822() : null
        );
    }

    /**
     * Queue a closure for immediate execution (CLI spawn, no cron wait).
     *
     * @param \Closure $closure The closure to execute
     * @param string $description Human-readable description for CMS display
     * @return int QueuedJobDescriptor ID
     */
    public static function dispatchImmediate(\Closure $closure, string $description = ''): int
    {
        $job = Injector::inst()->create(self::class, $closure, $description);
        return ImmediateJobRunner::queueAndRun($job);
    }

    public function __construct(?\Closure $closure = null, ?string $description = null)
    {
        parent::__construct();

        if ($closure) {
            # Wrap in SerializableClosure and pre-serialize to string.
            # This avoids native serialize() failure on closures when
            # QueuedJobService serializes the SavedJobData.
            $this->serializedClosure = serialize(new SerializableClosure($closure));
            $this->description = $description ?: 'Closure job';
            $this->totalSteps = 1;
        }
    }

    public function getTitle(): string
    {
        return $this->description ?: 'Closure job';
    }

    public function getJobType(): int
    {
        return QueuedJob::QUEUED;
    }

    public function process(): void
    {
        /** @var SerializableClosure $wrapper */
        $wrapper = unserialize($this->serializedClosure);
        $closure = $wrapper->getClosure();
        $result = $closure();

        if ($result) {
            $this->addMessage(is_string($result) ? $result : json_encode($result));
        }

        $this->currentStep = 1;
        $this->isComplete = true;
    }
}
