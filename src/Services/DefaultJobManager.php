<?php

namespace Restruct\QueuedJobsEnhancements\Services;

use Psr\Log\LoggerInterface;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJobService;

/**
 * Discovers job classes that declare themselves as default/required jobs
 * via `private static bool $is_default_job = true`, and ensures they're
 * in the queue.
 *
 * Works independently alongside the existing QueuedJobService::checkDefaultJobs()
 * mechanism (which handles YAML-configured defaultJobs). Both mechanisms coexist.
 *
 * Usage on a job class:
 *   private static bool $is_default_job = true;
 *   private static string $default_schedule = '+5 minutes';
 *   private static int $default_job_type = QueuedJob::QUEUED;       // optional
 *   private static array $default_construct = [];                    // optional
 */
class DefaultJobManager
{
    use Configurable;
    use Injectable;

    # Active job statuses (non-terminal)
    private const ACTIVE_STATUSES = [
        QueuedJob::STATUS_NEW,
        QueuedJob::STATUS_INIT,
        QueuedJob::STATUS_RUN,
        QueuedJob::STATUS_WAIT,
        QueuedJob::STATUS_PAUSED,
    ];

    /**
     * Discover all job classes with $is_default_job = true.
     *
     * @return array<string, array{class: string, schedule: string, construct: array, jobType: ?int}>
     */
    public static function discoverDefaultJobs(): array
    {
        $discovered = [];

        $jobClasses = ClassInfo::subclassesFor(AbstractQueuedJob::class, false);

        foreach ($jobClasses as $class) {
            # Skip abstract classes
            if ((new \ReflectionClass($class))->isAbstract()) {
                continue;
            }

            $isDefault = Config::inst()->get($class, 'is_default_job');
            if (!$isDefault) {
                continue;
            }

            $shortName = ClassInfo::shortName($class);

            $discovered[$shortName] = [
                'class' => $class,
                'schedule' => Config::inst()->get($class, 'default_schedule') ?: '+5 minutes',
                'construct' => Config::inst()->get($class, 'default_construct') ?: [],
                'jobType' => Config::inst()->get($class, 'default_job_type'),
            ];
        }

        return $discovered;
    }

    /**
     * Ensure all self-declaring default jobs have an active instance in the queue.
     * Creates missing jobs. Returns array of actions taken (for diagnostic output).
     *
     * @return array<string, string> Short name => action ('exists'|'created'|'error')
     */
    public static function ensureDefaultJobs(): array
    {
        $results = [];
        $discovered = static::discoverDefaultJobs();

        if (empty($discovered)) {
            return $results;
        }

        $service = QueuedJobService::singleton();

        foreach ($discovered as $shortName => $config) {
            $class = $config['class'];

            try {
                # Check if an active instance already exists
                $existing = QueuedJobDescriptor::get()->filter([
                    'Implementation' => $class,
                    'JobStatus' => self::ACTIVE_STATUSES,
                ]);

                if ($existing->exists()) {
                    $results[$shortName] = 'exists';
                    continue;
                }

                # Create and queue a new instance
                $constructArgs = $config['construct'];
                $job = Injector::inst()->createWithArgs($class, $constructArgs);

                $startAfter = date('Y-m-d H:i:s', strtotime($config['schedule']));
                $queue = $config['jobType'] !== null ? (int) $config['jobType'] : null;

                if ($queue !== null) {
                    $service->queueJob($job, $startAfter, $queue);
                } else {
                    $service->queueJob($job, $startAfter);
                }

                $results[$shortName] = 'created';

                Injector::inst()->get(LoggerInterface::class)->info(
                    "[DefaultJobManager] Created missing default job: {$shortName} ({$class})"
                );
            } catch (\Throwable $e) {
                $results[$shortName] = 'error';

                Injector::inst()->get(LoggerInterface::class)->warning(
                    "[DefaultJobManager] Failed to create default job {$shortName}: " . $e->getMessage()
                );
            }
        }

        return $results;
    }

    /**
     * Get a diagnostic summary of discovered self-declaring jobs and their queue status.
     *
     * @return array<string, array{class: string, schedule: string, queued: bool, status: ?string}>
     */
    public static function diagnosticSummary(): array
    {
        $summary = [];

        $discovered = static::discoverDefaultJobs();

        foreach ($discovered as $shortName => $config) {
            $class = $config['class'];

            # Check if an active instance exists in the queue
            $existing = QueuedJobDescriptor::get()->filter([
                'Implementation' => $class,
                'JobStatus' => self::ACTIVE_STATUSES,
            ]);

            $summary[$shortName] = [
                'class' => $class,
                'schedule' => $config['schedule'],
                'queued' => $existing->exists(),
                'status' => $existing->exists() ? $existing->first()->JobStatus : null,
            ];
        }

        return $summary;
    }
}
