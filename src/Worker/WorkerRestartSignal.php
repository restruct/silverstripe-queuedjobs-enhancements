<?php

namespace Restruct\QueuedJobsEnhancements\Worker;

use SilverStripe\ORM\DataObject;
use Throwable;

/**
 * The "restart your workers" signal written by queuedjobs:restart (Laravel's `queue:restart`).
 *
 * Each worker reads the current token when it starts, and exits between jobs once the token has
 * changed. Comparing tokens rather than timestamps means the clocks of the deploying host and the
 * worker host never have to agree.
 *
 * Kept in the DATABASE on purpose, not in a cache or a file: the deploy script and the worker can
 * run as different users, under different PHP versions and with different cache adapters, so a
 * cache entry or temp file written by one is not reliably visible to the other (SSKB caching.md,
 * "State written from the CLI and read by the web"). The database is the one store both share.
 *
 * @property string $Token
 */
class WorkerRestartSignal extends DataObject
{
    private static string $table_name = 'QueuedJobsWorkerRestartSignal';

    private static array $db = [
        'Token' => 'Varchar(64)',
    ];

    private static string $singular_name = 'Queue worker restart signal';

    /**
     * Ask every running worker to exit after its current job. Returns the new token.
     */
    public static function requestRestart(): string
    {
        $token = bin2hex(random_bytes(16));

        $signal = static::create();
        $signal->Token = $token;
        $signal->write();

        # Only the newest row matters; keep the table at one row
        foreach (static::get()->exclude('ID', $signal->ID) as $old) {
            $old->delete();
        }

        return $token;
    }

    /**
     * The newest token, or null when none was ever written. Also null when the table does not exist
     * yet (module installed, dev/build not run): a worker must keep running in that case, and it
     * still exits once a restart is requested later, because the token then differs from null.
     */
    public static function currentToken(): ?string
    {
        try {
            # Not get_one(): that caches, and this is read again and again in one long process
            $tokens = static::get()->sort('ID', 'DESC')->limit(1)->column('Token');
        } catch (Throwable $e) {
            return null;
        }

        return $tokens[0] ?? null;
    }
}
