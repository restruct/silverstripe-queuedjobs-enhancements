<?php

namespace Restruct\QueuedJobsEnhancements\Tests\Services;

use Restruct\QueuedJobsEnhancements\Services\DefaultJobManager;
use Restruct\QueuedJobsEnhancements\Tests\Stubs\StubDefaultJob;
use Restruct\QueuedJobsEnhancements\Tests\Stubs\StubNonDefaultJob;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Symbiote\QueuedJobs\Services\QueuedJob;

class DefaultJobManagerTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static $extra_dataobjects = [];

    public function testDiscoverDefaultJobsFindsAnnotatedClasses(): void
    {
        # StubDefaultJob has $is_default_job = true
        $discovered = DefaultJobManager::discoverDefaultJobs();

        $this->assertArrayHasKey('StubDefaultJob', $discovered);
        $this->assertEquals(StubDefaultJob::class, $discovered['StubDefaultJob']['class']);
        $this->assertEquals('+10 minutes', $discovered['StubDefaultJob']['schedule']);
    }

    public function testDiscoverDefaultJobsIgnoresNonDefault(): void
    {
        $discovered = DefaultJobManager::discoverDefaultJobs();

        $this->assertArrayNotHasKey('StubNonDefaultJob', $discovered);
    }

    public function testEnsureDefaultJobsCreatesWhenMissing(): void
    {
        # No jobs in queue initially
        $this->assertEquals(0, QueuedJobDescriptor::get()->filter('Implementation', StubDefaultJob::class)->count());

        $results = DefaultJobManager::ensureDefaultJobs();

        $this->assertArrayHasKey('StubDefaultJob', $results);
        $this->assertEquals('created', $results['StubDefaultJob']);

        # Verify job was actually created in the queue
        $descriptor = QueuedJobDescriptor::get()->filter('Implementation', StubDefaultJob::class)->first();
        $this->assertNotNull($descriptor);
    }

    public function testEnsureDefaultJobsSkipsExisting(): void
    {
        # Create a job manually first
        $descriptor = QueuedJobDescriptor::create();
        $descriptor->Implementation = StubDefaultJob::class;
        $descriptor->JobStatus = QueuedJob::STATUS_NEW;
        $descriptor->JobTitle = 'Stub default job';
        $descriptor->write();

        $results = DefaultJobManager::ensureDefaultJobs();

        $this->assertArrayHasKey('StubDefaultJob', $results);
        $this->assertEquals('exists', $results['StubDefaultJob']);

        # Should still be only 1 instance
        $this->assertEquals(1, QueuedJobDescriptor::get()->filter('Implementation', StubDefaultJob::class)->count());
    }

    public function testDiagnosticSummaryShowsQueueStatus(): void
    {
        $summary = DefaultJobManager::diagnosticSummary();

        $this->assertArrayHasKey('StubDefaultJob', $summary);
        $this->assertFalse($summary['StubDefaultJob']['queued']);
        $this->assertNull($summary['StubDefaultJob']['status']);

        # Create a running instance
        $descriptor = QueuedJobDescriptor::create();
        $descriptor->Implementation = StubDefaultJob::class;
        $descriptor->JobStatus = QueuedJob::STATUS_RUN;
        $descriptor->JobTitle = 'Stub default job';
        $descriptor->write();

        $summary = DefaultJobManager::diagnosticSummary();

        $this->assertTrue($summary['StubDefaultJob']['queued']);
        $this->assertEquals(QueuedJob::STATUS_RUN, $summary['StubDefaultJob']['status']);
    }

    public function testEnsureDefaultJobsIgnoresTerminalStatuses(): void
    {
        # Create a completed job (terminal status)
        $descriptor = QueuedJobDescriptor::create();
        $descriptor->Implementation = StubDefaultJob::class;
        $descriptor->JobStatus = QueuedJob::STATUS_COMPLETE;
        $descriptor->JobTitle = 'Stub default job';
        $descriptor->write();

        # Should create a new one since the existing one is terminal
        $results = DefaultJobManager::ensureDefaultJobs();

        $this->assertEquals('created', $results['StubDefaultJob']);
        $this->assertEquals(2, QueuedJobDescriptor::get()->filter('Implementation', StubDefaultJob::class)->count());
    }
}
