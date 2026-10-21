<?php

namespace Restruct\QueuedJobsEnhancements\Tests\Jobs;

use Restruct\QueuedJobsEnhancements\Tests\Stubs\StubBoundedDefaultsJob;
use Restruct\QueuedJobsEnhancements\Tests\Stubs\StubBoundedMessagesJob;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJobService;

/**
 * BoundedJobMessages: the head and tail are kept with one marker in between, the omitted count adds
 * up across trims and across the serialise/restore of the job between steps, and the severity
 * filter and the memory suffix are separate opt-ins. StubBoundedMessagesJob keeps 2 + 3.
 */
class BoundedJobMessagesTest extends SapphireTest
{
    protected $usesDatabase = true;

    /**
     * The message texts, without upstream's "[date][SEVERITY] " prefix (the marker has none).
     *
     * @return string[]
     */
    protected function texts(AbstractQueuedJob $job): array
    {
        return array_map(
            fn ($m) => preg_replace('/^\[[^\]]*\]\[[A-Z_]*\] /', '', $m),
            array_values((array) $job->getJobData()->messages)
        );
    }

    protected function add(AbstractQueuedJob $job, int $from, int $to, string $severity = 'INFO'): void
    {
        for ($i = $from; $i <= $to; $i++) {
            $job->addMessage('m' . $i, $severity);
        }
    }

    protected function markers(array $texts): array
    {
        return array_values(array_filter($texts, fn ($t) => strpos($t, 'omitted') !== false));
    }

    /**
     * The job as QueuedJobService restores it in a later step: a fresh instance, given the job
     * data and messages that copyJobToDescriptor() serialised separately.
     */
    protected function restore(AbstractQueuedJob $job): AbstractQueuedJob
    {
        $data = $job->getJobData();
        $savedJobData = serialize($data->jobData);
        $savedMessages = serialize($data->messages);

        $class = get_class($job);
        $restored = new $class();
        $restored->setJobData(
            $data->totalSteps,
            $data->currentStep,
            $data->isComplete,
            unserialize($savedJobData),
            unserialize($savedMessages)
        );

        return $restored;
    }

    public function testBelowTheCapNothingIsTrimmed(): void
    {
        $job = new StubBoundedMessagesJob();
        $this->add($job, 1, 5);

        $this->assertSame(['m1', 'm2', 'm3', 'm4', 'm5'], $this->texts($job));
        $this->assertSame(0, $job->getOmittedMessageCount());
    }

    public function testKeepsHeadAndTailWithOneMarker(): void
    {
        $job = new StubBoundedMessagesJob();
        $this->add($job, 1, 10);

        $this->assertSame(
            ['m1', 'm2', '... 5 earlier message(s) omitted to keep the job log bounded ...', 'm8', 'm9', 'm10'],
            $this->texts($job)
        );
        $this->assertSame(5, $job->getOmittedMessageCount());
        # The kept messages keep upstream's format
        $this->assertMatchesRegularExpression('/^\[[^\]]+\]\[INFO\] m10$/', end($job->getJobData()->messages));
    }

    public function testCountsAddUpAcrossRepeatedTrims(): void
    {
        $job = new StubBoundedMessagesJob();
        $this->add($job, 1, 10);
        $this->add($job, 11, 30);

        $texts = $this->texts($job);
        $this->assertCount(1, $this->markers($texts), implode("\n", $texts));
        $this->assertSame(['m1', 'm2', '... 25 earlier message(s) omitted to keep the job log bounded ...', 'm28', 'm29', 'm30'], $texts);
        # kept + omitted = everything ever added
        $this->assertSame(30, 5 + $job->getOmittedMessageCount());
    }

    /**
     * Between steps the job is serialised into the descriptor and rebuilt from it: the omitted
     * count (in the job data) and the marker must survive that, so the counts keep adding up.
     */
    public function testCountsAddUpAcrossStepBoundaries(): void
    {
        $job = new StubBoundedMessagesJob();
        $this->add($job, 1, 10);

        $job = $this->restore($job);
        $this->assertSame(5, $job->getOmittedMessageCount(), 'the count survives the restore');
        $this->add($job, 11, 14);

        $job = $this->restore($job);
        $this->add($job, 15, 20);

        $texts = $this->texts($job);
        $this->assertCount(1, $this->markers($texts), implode("\n", $texts));
        $this->assertSame(['m1', 'm2', '... 15 earlier message(s) omitted to keep the job log bounded ...', 'm18', 'm19', 'm20'], $texts);
    }

    /**
     * The previous marker is recognised wherever it is, so a changed head size between steps (a
     * deploy) neither keeps two markers nor counts the old marker as a dropped message.
     */
    public function testTheMarkerIsRecognisedWhenTheHeadSizeChanges(): void
    {
        $job = new StubBoundedMessagesJob();
        $this->add($job, 1, 10);
        Config::modify()->set(StubBoundedMessagesJob::class, 'keep_first_messages', 1);
        $this->add($job, 11, 11);

        $texts = $this->texts($job);
        $this->assertSame(['m1', '... 7 earlier message(s) omitted to keep the job log bounded ...', 'm9', 'm10', 'm11'], $texts);
    }

    /**
     * Through the real runner: a multi-step job logging in a loop leaves a bounded log on the
     * descriptor, which is the point of the trait.
     */
    public function testTheDescriptorLogStaysBoundedThroughTheService(): void
    {
        QueuedJobService::config()->set('time_limit', 0);
        QueuedJobService::config()->set('memory_limit', 0);
        $service = QueuedJobService::singleton();
        $id = $service->queueJob(new StubBoundedMessagesJob(5, 6));

        $service->runJob($id);

        $descriptor = QueuedJobDescriptor::get()->byID($id);
        $this->assertSame(QueuedJob::STATUS_COMPLETE, $descriptor->JobStatus);
        $messages = array_values(unserialize($descriptor->SavedJobMessages));
        $this->assertLessThanOrEqual(2 + 1 + 3, count($messages), implode("\n", $messages));
        $this->assertCount(1, $this->markers($messages), implode("\n", $messages));
        $this->assertStringEndsWith('step 6 message 5', end($messages));
    }

    public function testTheDefaults(): void
    {
        $job = new StubBoundedDefaultsJob();
        $this->add($job, 1, 300);

        $texts = $this->texts($job);
        $this->assertCount(10 + 1 + 200, $texts);
        $this->assertSame('m10', $texts[9]);
        $this->assertSame('... 90 earlier message(s) omitted to keep the job log bounded ...', $texts[10]);
        $this->assertSame('m101', $texts[11]);
        $this->assertSame('m300', $texts[210]);
        # Filter and suffix are off by default: DEBUG kept, no memory reading
        $job->addMessage('debug line', 'DEBUG');
        $texts = $this->texts($job);
        $this->assertSame('debug line', end($texts));
    }

    public function testBoundingCanBeSwitchedOff(): void
    {
        Config::modify()->set(StubBoundedMessagesJob::class, 'bound_messages', false);
        $job = new StubBoundedMessagesJob();
        $this->add($job, 1, 10);

        $this->assertCount(10, $this->texts($job));
        $this->assertSame(0, $job->getOmittedMessageCount());
    }

    public function testACustomMarker(): void
    {
        Config::modify()->set(StubBoundedMessagesJob::class, 'omitted_messages_marker', '[%d skipped, see the log file]');
        $job = new StubBoundedMessagesJob();
        $this->add($job, 1, 10);
        $this->assertSame('[5 skipped, see the log file]', $this->texts($job)[2]);

        # A marker text without %d is used as it is, and still recognised on the next trim
        Config::modify()->set(StubBoundedMessagesJob::class, 'omitted_messages_marker', 'some messages skipped');
        $this->add($job, 11, 12);
        $this->assertSame(['m1', 'm2', 'some messages skipped', 'm10', 'm11', 'm12'], $this->texts($job));
        $this->assertSame(7, $job->getOmittedMessageCount());
    }

    public function testTheSeverityFilter(): void
    {
        Config::modify()->set(StubBoundedMessagesJob::class, 'min_message_severity', 'warning');
        $job = new StubBoundedMessagesJob();
        $job->addMessage('an error', 'ERROR');
        $job->addMessage('a warning', 'WARNING');
        $job->addMessage('a notice', 'NOTICE');
        $job->addMessage('an info');
        $job->addMessage('a debug line', 'DEBUG');
        $job->addMessage('an unknown severity counts as info', 'MEM_USAGE');

        $this->assertSame(['an error', 'a warning'], $this->texts($job));
        $this->assertSame(0, $job->getOmittedMessageCount(), 'filtered messages are not counted as omitted');
    }

    public function testAnUnknownMinimumSeverityFiltersNothing(): void
    {
        Config::modify()->set(StubBoundedMessagesJob::class, 'min_message_severity', 'WARNIGN');
        $job = new StubBoundedMessagesJob();
        $job->addMessage('a debug line', 'DEBUG');

        $this->assertSame(['a debug line'], $this->texts($job));
    }

    public function testTheMemorySuffix(): void
    {
        Config::modify()->set(StubBoundedMessagesJob::class, 'message_memory_usage', true);
        $job = new StubBoundedMessagesJob();
        $job->addMessage('with memory');

        $this->assertMatchesRegularExpression('/^with memory \[[0-9]+\.[0-9]\/[0-9]+\.[0-9]MB\]$/', $this->texts($job)[0]);
    }
}
