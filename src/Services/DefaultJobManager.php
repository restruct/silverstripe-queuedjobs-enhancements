<?php

namespace Restruct\QueuedJobsEnhancements\Services;

use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use Symfony\Component\Yaml\Yaml;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJobService;

/**
 * Discovery + diagnostics for job classes that declare themselves as default/required
 * jobs via `private static bool $is_default_job = true`.
 *
 * This class is DIAGNOSTIC ONLY — it does not create jobs.
 *
 * Why: core already ensures default jobs on its own. `QueuedJobService::runQueue()` calls
 * `checkDefaultJobs()` on every invocation, and `ProcessJobQueueTask` calls `runQueue()`,
 * so a YAML `defaultJobs` block is re-checked on every cron tick — with alert emails and
 * health-check integration that a bolt-on re-implementation does not get.
 *
 * A module cannot feed discovered jobs into that mechanism: `QueuedJobService::$defaultJobs`
 * is a public property populated via Injector `properties:`, and neither `runQueue()` nor
 * `checkDefaultJobs()` exposes an extension hook. Rather than duplicate core's creation
 * logic (and need its own trigger to run it), this class simply reports what is declared
 * and can emit the YAML block to paste into your project config.
 *
 * Usage on a job class:
 *   private static bool $is_default_job = true;
 *   private static string $default_schedule = '+5 minutes';
 *   private static int $default_job_type = QueuedJob::QUEUED;       // optional
 *   private static array $default_construct = [];                    // optional
 *
 * @see \Restruct\QueuedJobsEnhancements\Tasks\CheckDefaultJobsTask
 */
class DefaultJobManager
{
    use Configurable;
    use Injectable;

    # Active job statuses (non-terminal) — mirrors core's checkDefaultJobs()
    private const ACTIVE_STATUSES = [
        QueuedJob::STATUS_NEW,
        QueuedJob::STATUS_INIT,
        QueuedJob::STATUS_RUN,
        QueuedJob::STATUS_WAIT,
        QueuedJob::STATUS_PAUSED,
    ];

    /**
     * Discover all concrete job classes with $is_default_job = true.
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
     * Diagnostic summary of discovered self-declaring jobs and their current queue status.
     *
     * @return array<string, array{class: string, schedule: string, queued: bool, status: ?string}>
     */
    public static function diagnosticSummary(): array
    {
        $summary = [];

        foreach (static::discoverDefaultJobs() as $shortName => $config) {
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

    /**
     * Build the core `defaultJobs` config structure for every discovered job.
     * Paste the YAML rendering of this (see toDefaultJobsYaml()) into your project config
     * and core's own mechanism takes over — creation, alert emails and health checks.
     *
     * @return array<string, mixed> Nested Injector config array
     */
    public static function buildDefaultJobsConfig(): array
    {
        $jobs = [];

        foreach (static::discoverDefaultJobs() as $shortName => $config) {
            $entry = [
                'type' => $config['class'],
                # Core merges ['Implementation' => type] into this filter, so an empty
                # filter already matches on implementation alone.
                'filter' => [],
                'recreate' => 1,
                'construct' => $config['construct'],
                'startDateFormat' => 'Y-m-d H:i:s',
                'startTimeString' => $config['schedule'],
                'email' => '',
            ];

            # Only recreated while the matching queue is being processed — so if you set
            # this, make sure a runner processes that queue.
            if ($config['jobType'] !== null) {
                $entry['jobType'] = (int) $config['jobType'];
            }

            $jobs[$shortName] = $entry;
        }

        return [
            Injector::class => [
                QueuedJobService::class => [
                    'properties' => [
                        'defaultJobs' => $jobs,
                    ],
                ],
            ],
        ];
    }

    /**
     * Render buildDefaultJobsConfig() as a YAML block ready to paste into project config.
     * Returns an empty string when nothing declares itself a default job.
     */
    public static function toDefaultJobsYaml(): string
    {
        if (empty(static::discoverDefaultJobs())) {
            return '';
        }

        return Yaml::dump(static::buildDefaultJobsConfig(), 6, 2);
    }
}
