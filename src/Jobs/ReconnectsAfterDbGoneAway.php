<?php

namespace Restruct\QueuedJobsEnhancements\Jobs;

use SilverStripe\Core\Config\Config;
use SilverStripe\ORM\DB;

/**
 * Opt-in trait for jobs extending AbstractQueuedJob: lets a long-running job survive a database
 * restart in the middle of a run (for example a restart after OS library updates).
 *
 * Why the job must reconnect itself: the Silverstripe MySQLi connector does not reconnect on its
 * own, and QueuedJobService writes the job descriptor right after process() returns, outside the
 * job's own catch. On a dead connection that write throws the same "gone away" uncaught, so a job
 * that merely catches the error still takes the queue runner down, and the descriptor stays
 * Running until stall detection picks it up.
 *
 * Use it in the job's catch block, and retry the same step when it returns true:
 *
 *     class MySyncJob extends AbstractQueuedJob
 *     {
 *         use ReconnectsAfterDbGoneAway;
 *
 *         public function process()
 *         {
 *             try {
 *                 $this->syncNextBatch();
 *             } catch (\Throwable $e) {
 *                 if ($this->reconnectAfterDbGoneAway($e)) {
 *                     return; // the connection is back: the next process() call retries this step
 *                 }
 *                 throw $e;
 *             }
 *         }
 *     }
 *
 * One reconnect cycle per call: a retry cap, if the job wants one, belongs in the job.
 *
 * Setting, read from the CONCRETE job class's config (a private static on the job class, or YAML
 * keyed to it), as BoundedJobMessages does; the trait declares no statics, so a job can declare it
 * without a property conflict:
 *     db_reconnect_wait_seconds: 60   wait before the second attempt (the server is often still
 *                                     restarting at the first)
 */
trait ReconnectsAfterDbGoneAway
{
    /**
     * Is this a dropped database connection? MariaDB/MySQL say "server has gone away" (2006) on the
     * next query after a restart, and "Lost connection to MySQL server" (2013) when the restart lands
     * during a query.
     */
    public static function is_db_gone_away(\Throwable $e): bool
    {
        $msg = $e->getMessage();
        return stripos($msg, 'gone away') !== false || stripos($msg, 'Lost connection to MySQL server') !== false;
    }

    /**
     * When $e is a dropped connection: reconnect, and if that fails (the server is still restarting)
     * wait db_reconnect_wait_seconds and try once more. Returns true when the connection is back (the
     * caller retries its step), false when $e was something else or both attempts failed (the caller
     * fails as it would have).
     */
    protected function reconnectAfterDbGoneAway(\Throwable $e): bool
    {
        if (!static::is_db_gone_away($e)) {
            return false;
        }
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                # connect() has no default for its parameters: pass the config back in, or it throws
                # ArgumentCountError
                DB::get_conn()->connect(DB::getConfig());
                # the connection object exists even when the server refused it: prove it with a query
                DB::query('SELECT 1');
                $this->addMessage("Database connection lost ({$e->getMessage()}), reconnected (attempt {$attempt})", 'WARNING');
                return true;
            } catch (\Throwable $reconnectError) {
                $this->addMessage("Database connection lost, reconnect attempt {$attempt} failed: "
                    . $reconnectError->getMessage(), 'WARNING');
                if ($attempt === 1) {
                    sleep($this->dbReconnectWaitSeconds());
                }
            }
        }
        return false;
    }

    /**
     * The wait before the second reconnect attempt, from the job class's config (default 60)
     */
    protected function dbReconnectWaitSeconds(): int
    {
        $seconds = Config::inst()->get(static::class, 'db_reconnect_wait_seconds');
        return is_numeric($seconds) ? max(0, (int) $seconds) : 60;
    }
}
