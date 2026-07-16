<?php

namespace Restruct\QueuedJobsEnhancements\Jobs;

use Restruct\QueuedJobsEnhancements\Services\ImmediateJobRunner;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJobService;

/**
 * A universal queued job that calls a method on an object or class.
 * The bridge between "I have a method" and "I want it queued."
 *
 * Usage:
 *   // Schedule for later
 *   ScheduledMethodCall::schedule($myObject, 'processData', [$arg1], ['description' => 'Process data'], '+5 minutes');
 *
 *   // Execute immediately (CLI spawn, no cron wait)
 *   ScheduledMethodCall::scheduleImmediate($myObject, 'sendEmail', [$recipient], ['description' => 'Send email']);
 *
 *   // Static method call
 *   ScheduledMethodCall::schedule(MyService::class, 'cleanup', [], ['description' => 'Run cleanup']);
 *
 * Consolidated from Restruct\Silverstripe\AdminTweaks\Jobs\ScheduledMethodCall.
 *
 * @property int|null $objectID DataObject ID for instance method calls (jobData)
 * @property string $objectClass Fully qualified class name of the target (jobData)
 * @property string $method Method name to call (jobData)
 * @property array $arguments Arguments passed to the method (jobData)
 * @property string $description Human-readable job description (jobData)
 * @property int $jobType QueuedJob type constant (jobData)
 * @property string $appendSig Random signature suffix for ignoreIdentical (jobData)
 */
class ScheduledMethodCall extends AbstractQueuedJob
{
    /**
     * Schedule a method call as a queued job.
     *
     * @param DataObject|object|string $objectOrClass Object instance, or class name for static calls
     * @param string $method Method to call
     * @param array $args Arguments for the method
     * @param array $options {description, jobType, totalSteps, ignoreIdentical}
     * @param string $when When to execute (strtotime-compatible, default: 'now')
     * @return int QueuedJobDescriptor ID
     */
    public static function schedule(
        object|string $objectOrClass,
        string $method,
        array $args = [],
        array $options = [],
        string $when = 'now',
    ): int {
        return QueuedJobService::singleton()->queueJob(
            Injector::inst()->create(self::class, $objectOrClass, $method, $args, $options),
            DBDatetime::create()->setValue(strtotime($when))->Rfc2822()
        );
    }

    /**
     * Schedule a method call for immediate execution (CLI spawn, no cron wait).
     *
     * @see schedule() for parameter docs
     * @return int QueuedJobDescriptor ID
     */
    public static function scheduleImmediate(
        object|string $objectOrClass,
        string $method,
        array $args = [],
        array $options = [],
    ): int {
        $job = Injector::inst()->create(self::class, $objectOrClass, $method, $args, $options);
        return ImmediateJobRunner::queueAndRun($job);
    }

    /**
     * @param DataObject|object|string|null $objectOrClass Object, or class name for static calls
     * @param string|null $method Method to call
     * @param array $args Arguments for the method
     * @param array $options {description, jobType, totalSteps, ignoreIdentical}
     */
    public function __construct(
        object|string|null $objectOrClass = null,
        ?string $method = null,
        array $args = [],
        array $options = [],
    ) {
        parent::__construct();

        if ($objectOrClass && $method) {
            # Store object reference — DataObjects by ID+class, others by class name
            if ($objectOrClass instanceof DataObject) {
                $this->objectID = $objectOrClass->ID;
                $this->objectClass = $objectOrClass->ClassName;
            } elseif (is_object($objectOrClass)) {
                $this->objectClass = get_class($objectOrClass);
            } else {
                $this->objectClass = $objectOrClass;
            }

            $this->method = $method;
            $this->arguments = $args;

            $mergedOptions = array_merge(
                [
                    'description' => '[ no description ]',
                    'jobType' => QueuedJob::QUEUED,
                    'totalSteps' => 1,
                    'ignoreIdentical' => false,
                ],
                $options
            );
            $this->description = $mergedOptions['description'];
            $this->jobType = $mergedOptions['jobType'];
            $this->totalSteps = $mergedOptions['totalSteps'];
            # Append random signature to allow identical jobs in queue simultaneously
            $this->appendSig = $mergedOptions['ignoreIdentical'] ? $this->randomSignature() : '';

            $this->addMessage('Job will call ' . $this->getContextDescription());
        }
    }

    public function getTitle(): string
    {
        return $this->description ?: 'ScheduledMethodCall';
    }

    public function getJobType(): int
    {
        return (int) ($this->jobType ?: QueuedJob::QUEUED);
    }

    public function getSignature(): string
    {
        return parent::getSignature() . ($this->appendSig ?: '');
    }

    public function process(): void
    {
        $this->currentStep++;

        $objectOrClassName = $this->objectClass;
        if ($this->objectID) {
            $objectOrClassName = DataObject::get_by_id($this->objectClass, $this->objectID);
            # Fail fast when the record was deleted between scheduling and execution (ported from
            # admintweaks 3.16.0). A property-set on null would raise an Error that the old catch
            # below swallowed — throwing here gives the service a clear, attributable Broken message.
            if (!$objectOrClassName) {
                throw new \RuntimeException(sprintf(
                    'ScheduledMethodCall: %s #%d no longer exists — cannot call %s() (record deleted since scheduling?)',
                    $this->objectClass,
                    $this->objectID,
                    $this->method
                ));
            }
            # Expose job instance to the called method (allows it to add messages, etc.)
            $objectOrClassName->scheduled_job_instance = $this;
        }

        try {
            $result = call_user_func_array([$objectOrClassName, $this->method], $this->arguments);
            if ($result) {
                $this->addMessage($result);
            } else {
                $this->addMessage(sprintf(
                    "%s::%s call returned no result, may indicate that method doesn't exist",
                    $this->objectClass,
                    $this->method
                ), 'WARNING');
            }
        } catch (\Throwable $e) {
            $this->addMessage(sprintf(
                "%s::%s ERROR: %s (%s)",
                $this->objectClass,
                $this->method,
                $e->getCode(),
                $e->getMessage()
            ));
            # RE-THROW instead of `$this->jobStatus = STATUS_BROKEN; return;` (ported from
            # admintweaks 3.15.0). That assignment only wrote a magic jobData property that
            # QueuedJobService never reads — the service saw no exception and no isComplete, so it
            # looped process() indefinitely with addMessage() re-serialised onto the descriptor
            # every pass (a site in prod, 2026-07-15: descriptor #152177 reached step 2563/1 and OOM'd
            # the runner at ~400MB). Throwing is the documented failure signal: runJob() catches
            # Throwable, logs it against the descriptor and calls markJobAsBroken() (verified
            # QueuedJobService::runJob() L928-933, queuedjobs 5.3.3).
            //            $this->jobStatus = QueuedJob::STATUS_BROKEN;
            //            return;
            throw $e;
        }

//        $this->addMessage('Called: ' . $this->getContextDescription());

        if ($this->currentStep >= $this->totalSteps) {
            $this->isComplete = true;
        }
    }

    protected function getContextDescription(): string
    {
        return sprintf(
            "method %s on %s (%s)",
            $this->method,
            $this->objectClass,
            $this->objectID ? "ID:{$this->objectID}" : 'statically'
        );
    }

    protected function randomSignature(): string
    {
        return '_' . substr(md5(uniqid('', true)), 0, 8);
    }
}
