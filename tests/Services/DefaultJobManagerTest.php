<?php

namespace Restruct\QueuedJobsEnhancements\Tests\Services;

use Restruct\QueuedJobsEnhancements\Services\DefaultJobManager;
use Restruct\QueuedJobsEnhancements\Tests\Stubs\StubDefaultJob;
use SilverStripe\Dev\SapphireTest;
use Symfony\Component\Yaml\Yaml;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Symbiote\QueuedJobs\Services\QueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJobService;

/**
 * DefaultJobManager is diagnostic only — it discovers, reports, and can emit the core
 * `defaultJobs` config. It never creates jobs; core's checkDefaultJobs() does that, and
 * it runs on every ProcessJobQueueTask invocation via QueuedJobService::runQueue().
 */
class DefaultJobManagerTest extends SapphireTest
{
    protected $usesDatabase = true;

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

    public function testDiagnosticSummaryIgnoresTerminalStatuses(): void
    {
        # A completed job is terminal — it does not count as an active instance
        $descriptor = QueuedJobDescriptor::create();
        $descriptor->Implementation = StubDefaultJob::class;
        $descriptor->JobStatus = QueuedJob::STATUS_COMPLETE;
        $descriptor->JobTitle = 'Stub default job';
        $descriptor->write();

        $summary = DefaultJobManager::diagnosticSummary();

        $this->assertFalse($summary['StubDefaultJob']['queued']);
    }

    public function testNeverCreatesJobs(): void
    {
        $before = QueuedJobDescriptor::get()->count();

        DefaultJobManager::discoverDefaultJobs();
        DefaultJobManager::diagnosticSummary();
        DefaultJobManager::toDefaultJobsYaml();

        $this->assertEquals(
            $before,
            QueuedJobDescriptor::get()->count(),
            'DefaultJobManager must never create job descriptors'
        );
    }

    public function testBuildDefaultJobsConfigMatchesCoreSchema(): void
    {
        $config = DefaultJobManager::buildDefaultJobsConfig();

        $jobs = $config['SilverStripe\Core\Injector\Injector']
            [QueuedJobService::class]['properties']['defaultJobs'];

        $this->assertArrayHasKey('StubDefaultJob', $jobs);
        $entry = $jobs['StubDefaultJob'];

        # Keys core's checkDefaultJobs() requires: type + filter, plus (for recreation)
        # recreate + construct + startDateFormat + startTimeString
        $this->assertEquals(StubDefaultJob::class, $entry['type']);
        $this->assertArrayHasKey('filter', $entry);
        $this->assertEquals(1, $entry['recreate']);
        $this->assertArrayHasKey('construct', $entry);
        $this->assertEquals('Y-m-d H:i:s', $entry['startDateFormat']);
        $this->assertEquals('+10 minutes', $entry['startTimeString']);
    }

    public function testToDefaultJobsYamlIsParseableAndRoundTrips(): void
    {
        $yaml = DefaultJobManager::toDefaultJobsYaml();
        $this->assertNotSame('', $yaml);

        $this->assertEquals(DefaultJobManager::buildDefaultJobsConfig(), Yaml::parse($yaml));
    }
}
