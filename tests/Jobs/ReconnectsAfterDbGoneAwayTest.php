<?php

namespace Restruct\QueuedJobsEnhancements\Tests\Jobs;

use Restruct\QueuedJobsEnhancements\Jobs\ReconnectsAfterDbGoneAway;
use Restruct\QueuedJobsEnhancements\Tests\Stubs\StubReconnectingJob;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\Connect\DatabaseException;
use SilverStripe\ORM\DB;

/**
 * ReconnectsAfterDbGoneAway (#9). The reconnect itself is NOT run here: inside a SapphireTest
 * DB::getConfig() still names the main database, not the temporary test database, so a real
 * reconnect would leave the rest of the run on the main database. Covered: which errors count as a
 * dropped connection, and that any other error leaves the connection alone.
 */
class ReconnectsAfterDbGoneAwayTest extends SapphireTest
{
    protected $usesDatabase = true;

    public function testRecognisesADroppedConnection(): void
    {
        # MariaDB/MySQL 2006 (the next query after a restart) and 2013 (a restart during a query)
        $this->assertTrue(StubReconnectingJob::is_db_gone_away(
            new DatabaseException("Couldn't run query: SELECT 1 | MySQL server has gone away")
        ));
        $this->assertTrue(StubReconnectingJob::is_db_gone_away(
            new DatabaseException('Lost connection to MySQL server during query')
        ));
        # control: an ordinary job error is not a connection problem
        $this->assertFalse(StubReconnectingJob::is_db_gone_away(new \Exception("Invalid date: 'tomorrow-ish'")));
    }

    public function testOtherErrorsLeaveTheConnectionAlone(): void
    {
        $job = new StubReconnectingJob();
        $connBefore = DB::get_conn();

        $this->assertFalse($job->callReconnectAfterDbGoneAway(new \RuntimeException('XSD validation failed')));
        # still the same connection object, on the temporary test database
        $this->assertSame($connBefore, DB::get_conn());
        $this->assertStringStartsWith('ss_tmpdb', (string) DB::get_conn()->getSelectedDatabase());
        # and nothing was logged on the job
        $this->assertSame([], array_values((array) $job->getJobData()->messages));
    }

    public function testWaitIsReadFromTheJobClassConfig(): void
    {
        $job = new StubReconnectingJob();
        $method = new \ReflectionMethod($job, 'dbReconnectWaitSeconds');
        $method->setAccessible(true);

        # default when the job class sets nothing
        $this->assertSame(60, $method->invoke($job));
        # AbstractQueuedJob is not Configurable: set it through Config, keyed to the job class
        Config::modify()->set(StubReconnectingJob::class, 'db_reconnect_wait_seconds', 5);
        $this->assertSame(5, $method->invoke($job));
    }

    public function testJobUsesTheTrait(): void
    {
        $this->assertContains(ReconnectsAfterDbGoneAway::class, class_uses(StubReconnectingJob::class));
    }
}
