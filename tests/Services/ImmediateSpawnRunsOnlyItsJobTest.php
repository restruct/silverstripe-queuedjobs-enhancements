<?php

namespace Restruct\QueuedJobsEnhancements\Tests\Services;

use Restruct\QueuedJobsEnhancements\Services\ImmediateJobRunner;
use Restruct\QueuedJobsEnhancements\Tests\Stubs\StubImmediateJob;
use Restruct\QueuedJobsEnhancements\Tests\Stubs\StubNonDefaultJob;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DB;
use SilverStripe\PolyExecution\PolyOutput;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Symbiote\QueuedJobs\Services\QueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJobService;

/**
 * "Bug 3" (issue #5): the background process queueAndRun() spawns for ONE job must not run any
 * other job.
 *
 * Up to 1.5.0 the spawn was `ProcessJobQueueTask job=queuedjob-<ID>`. That runs the job, and then,
 * because every CLI process that instantiates QueuedJobService registers onShutdown(), drains the
 * whole IMMEDIATE queue at exit (SSKB queuedjobs §12). The spawn now runs the module's worker in
 * --job mode, which switches that drain off for its process.
 *
 * Only a real child process shows this: the drain happens at process exit, and the test state
 * switches use_shutdown_function off in-process anyway. So the spawn command ImmediateJobRunner
 * builds is run for real, synchronously, against this test's temporary database (passed down as
 * SS_DATABASE_NAME; environment variables win over .env). Transactions are off so the child sees
 * the rows written here.
 *
 * testControlUpstreamSingleJobRunDrainsImmediateQueue() runs the 1.5.0 command the same way and
 * must see the drain: proof that this harness can detect the bug at all.
 */
class ImmediateSpawnRunsOnlyItsJobTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected $usesTransactions = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open() is needed to run the spawn command.');
        }
        # The command is run in the foreground here; no lock needed
        Config::modify()->set(ImmediateJobRunner::class, 'use_spawn_lock', false);
    }

    public function testSpawnRunsOnlyItsOwnJob(): void
    {
        # What queueAndRun() queues (the job's own queue), plus someone else's immediate job
        $mine = QueuedJobService::singleton()->queueJob(new StubNonDefaultJob());
        $other = QueuedJobService::singleton()->queueJob(new StubImmediateJob('other'));

        $method = new \ReflectionMethod(ImmediateJobRunner::class, 'buildSpawnCommand');
        $method->setAccessible(true);
        $output = $this->runInForeground($method->invoke(null, $mine));

        $this->assertSame(QueuedJob::STATUS_COMPLETE, $this->jobStatus($mine), "the spawned job must run.\n" . $output);
        $this->assertSame(
            QueuedJob::STATUS_NEW,
            $this->jobStatus($other),
            "the spawn for job #{$mine} must not run immediate job #{$other} (bug 3).\n" . $output
        );
    }

    public function testControlUpstreamSingleJobRunDrainsImmediateQueue(): void
    {
        $mine = QueuedJobService::singleton()->queueJob(new StubNonDefaultJob());
        $other = QueuedJobService::singleton()->queueJob(new StubImmediateJob('other'));

        # The 1.5.0 spawn command, minus nohup/flock
        $framework = BASE_PATH . '/vendor/silverstripe/framework';
        $command = class_exists(PolyOutput::class)
            ? sprintf('%s %s tasks:ProcessJobQueueTask --job=queuedjob-%d', escapeshellarg(PHP_BINARY), escapeshellarg($framework . '/bin/sake'), $mine)
            : sprintf('%s %s dev/tasks/ProcessJobQueueTask job=queuedjob-%d', escapeshellarg(PHP_BINARY), escapeshellarg($framework . '/cli-script.php'), $mine);
        $output = $this->runInForeground($command);

        $this->assertSame(QueuedJob::STATUS_COMPLETE, $this->jobStatus($mine), $output);
        $this->assertSame(
            QueuedJob::STATUS_COMPLETE,
            $this->jobStatus($other),
            "control: ProcessJobQueueTask job=... is expected to drain the immediate queue at exit. If it no "
            . "longer does (upstream #506), this harness cannot show bug 3 any more.\n" . $output
        );
    }

    /**
     * Strip the detaching wrapper off a spawn command and run it, against the test database.
     *
     * @return string combined stdout/stderr, for assertion messages
     */
    protected function runInForeground(string $command): string
    {
        # buildSpawnCommand() returns `nohup <cmd> > /dev/null 2>&1 &`
        $command = preg_replace('#^nohup (.*) > /dev/null 2>&1 &$#', '$1', $command);

        $env = getenv();
        $env['SS_DATABASE_NAME'] = DB::get_conn()->getSelectedDatabase();
        # The temporary database name already carries any prefix/suffix
        $env['SS_DATABASE_PREFIX'] = '';
        $env['SS_DATABASE_SUFFIX'] = '';
        unset($env['SS_DATABASE_CHOOSE_NAME']);

        $process = proc_open(
            $command . ' 2>&1',
            [1 => ['pipe', 'w']],
            $pipes,
            BASE_PATH,
            $env
        );
        $this->assertIsResource($process, 'could not start: ' . $command);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $exit = proc_close($process);

        # The parent's ORM cache would otherwise answer the status checks below
        \SilverStripe\ORM\DataObject::flush_and_destroy_cache();

        return sprintf("command: %s\nexit: %d\noutput:\n%s", $command, $exit, $output);
    }

    protected function jobStatus(int $id): string
    {
        return (string) QueuedJobDescriptor::get()->filter('ID', $id)->column('JobStatus')[0];
    }
}
