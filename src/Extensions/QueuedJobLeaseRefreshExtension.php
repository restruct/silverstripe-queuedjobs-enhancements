<?php

namespace Restruct\QueuedJobsEnhancements\Extensions;

use DateInterval;
use DateTime;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Extension;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\FieldType\DBDatetime;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Symbiote\QueuedJobs\Services\QueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJobService;
use Throwable;

/**
 * Lock refresh ("lease heartbeat"), OFF by default: moves a running job's lock expiry forward after
 * each step, so upstream's health check only restarts a job that made no step for worker_ttl,
 * instead of any job that has been running longer than worker_ttl.
 *
 * The problem (upstream QueuedJobService, branches 5 and 6): grabMutex() sets `Expiry = now +
 * worker_ttl` (PT5M) once, when a runner takes the job, and nothing moves it forward while the job
 * runs. checkJobHealth(), at the start of every ProcessJobQueueTask run on that queue, restarts a
 * Running job when no step was processed since its previous check AND Expiry has passed. On a queue
 * whose cron runs every minute (the usual immediate queue), a job running longer than 5 minutes with
 * one step longer than a minute is therefore restarted in the middle of that step, while the first
 * process still runs it: two processes on the same job.
 *
 * With this switched on, every step write of a Running descriptor by the process that holds its
 * lock sets `Expiry = now + worker_ttl` again. A stall is then "no step for worker_ttl", which is
 * what the health check means. A single step longer than worker_ttl can still be restarted, as
 * before; raise worker_ttl for such jobs.
 *
 * Only the lock holder refreshes. Upstream's lock is a random token that grabMutex() writes into
 * the descriptor's `Worker` column; it is not exposed. The one write that follows grabMutex() in the
 * same process is initialiseJob()'s change of JobStatus to Initialising, so on that write this
 * extension reads `Worker` back from the database and keeps it as this process's token for the job.
 * Every refresh is then a raw `UPDATE ... SET Expiry WHERE ID = ? AND Worker = ? AND JobStatus =
 * 'Running'`: raw, so it never calls onAfterWrite() again; with the token, so it can never extend a
 * lock that another process took over (after a health-check restart, for instance). A process that
 * writes a Running descriptor without having initialised it (the CMS, a script) holds no token and
 * changes nothing.
 *
 * Throttled to once per `min_interval` seconds per job (default 30): a fast job writes its
 * descriptor after every step, sometimes many times a second, and an extra UPDATE per step would add
 * a database write for nothing. 30 seconds against a 5-minute worker_ttl still leaves a margin of
 * four and a half minutes.
 *
 * Remove this once upstream refreshes the lock itself (nothing in upstream does today, 6.2.3); a
 * proposed patch for upstream's runJob() does the same in a few lines.
 *
 * @config bool $enabled
 * @config int $min_interval
 */
class QueuedJobLeaseRefreshExtension extends Extension
{
    # Settings are read through Config::inst() with this class name, not a trait: Extension's own
    # traits differ between Silverstripe 5 and 6

    /**
     * Off by default: it changes how stuck jobs are detected on every queue of the site.
     */
    private static bool $enabled = false;

    /**
     * Seconds between two refreshes of the same job's lock (see the class doc). 0 = every step.
     */
    private static int $min_interval = 30;

    /**
     * @var array<int, array{worker: string, refreshed: int}> this process's lock token per job ID,
     *   and when it last refreshed (0 = not yet)
     */
    protected static array $leases = [];

    /**
     * Public: Silverstripe 5 calls extension hooks from outside the class.
     */
    public function onAfterWrite(): void
    {
        if (!Config::inst()->get(static::class, 'enabled')) {
            return;
        }

        /** @var QueuedJobDescriptor $descriptor */
        $descriptor = $this->getOwner();
        $id = (int) $descriptor->ID;
        if ($id <= 0) {
            return;
        }

        try {
            if ($descriptor->JobStatus === QueuedJob::STATUS_INIT) {
                # Right after grabMutex() in this process (see the class doc): take the token. The
                # second write of initialiseJob() keeps the status and changes nothing here.
                if ($descriptor->isChanged('JobStatus')) {
                    $this->takeToken($id);
                }
                return;
            }

            if ($descriptor->JobStatus !== QueuedJob::STATUS_RUN) {
                # Done, parked, paused or broken: the lock is released or about to be
                unset(static::$leases[$id]);
                return;
            }

            $this->refresh($id);
        } catch (Throwable $e) {
            # A failed refresh must never break the step write of a running job; the job then simply
            # runs under upstream's own expiry
        }
    }

    protected function takeToken(int $id): void
    {
        $worker = DB::prepared_query(
            'SELECT "Worker" FROM "QueuedJobDescriptor" WHERE "ID" = ?',
            [$id]
        )->value();

        if (is_string($worker) && $worker !== '') {
            static::$leases[$id] = ['worker' => $worker, 'refreshed' => 0];
        } else {
            unset(static::$leases[$id]);
        }
    }

    protected function refresh(int $id): void
    {
        if (!isset(static::$leases[$id])) {
            # Not this process's lock
            return;
        }

        $now = DBDatetime::now()->getTimestamp();
        $interval = max(0, (int) Config::inst()->get(static::class, 'min_interval'));
        if (static::$leases[$id]['refreshed'] > 0 && $now - static::$leases[$id]['refreshed'] < $interval) {
            return;
        }

        DB::prepared_query(
            'UPDATE "QueuedJobDescriptor" SET "Expiry" = ? WHERE "ID" = ? AND "Worker" = ? AND "JobStatus" = ?',
            [static::expiryFrom($now), $id, static::$leases[$id]['worker'], QueuedJob::STATUS_RUN]
        );
        static::$leases[$id]['refreshed'] = $now;
    }

    /**
     * now + QueuedJobService.worker_ttl, in the same form as upstream's getWorkerExpiry().
     */
    public static function expiryFrom(int $timestamp): string
    {
        $time = (new DateTime())->setTimestamp($timestamp);
        $timeToLive = Config::inst()->get(QueuedJobService::class, 'worker_ttl');
        if ($timeToLive) {
            $time->add(new DateInterval($timeToLive));
        }

        return DBDatetime::create_field('Datetime', $time->getTimestamp())->Rfc2822();
    }

    /**
     * For tests: forget the tokens of this process.
     */
    public static function reset(): void
    {
        static::$leases = [];
    }
}
