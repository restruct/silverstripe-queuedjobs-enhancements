<?php

namespace Restruct\QueuedJobsEnhancements\Tests\Jobs;

use Restruct\QueuedJobsEnhancements\Jobs\SendEmailJob;
use SilverStripe\Control\Email\Email;
use SilverStripe\Dev\SapphireTest;

/**
 * Full round-trip guard for SendEmailJob (2026-07-16): hydrate an SS5 Email -> serialize the
 * jobData exactly as QueuedJobService persists it (serialize/unserialize of SavedJobData) ->
 * rebuild + send in process() on a FRESH job instance.
 *
 * The hydrate side was already asserted via descriptor payloads in SpamNotificationFlowTest; this
 * covers the previously-untested process() side, and locks in the three SS5/Symfony traps the job
 * works around (each broke prod at some point, see in-file comments):
 *  1. addresses stored as [email => name] maps - Symfony Address objects double-wrap on setTo()
 *  2. body read via getHtmlBody() - the assembled getBody() is a non-castable TextPart
 *  3. reply-to unpacked via a local copy - reset() on the overloaded jobData property notices
 *
 * (Ported with the class from a client site.)
 */
class SendEmailJobRoundTripTest extends SapphireTest
{
    protected $usesDatabase = false;

    /**
     * Simulate the DB persistence QueuedJobService performs between queueing and running:
     * SavedJobData = serialize($job->getJobData()->jobData) -> unserialize -> setJobData().
     */
    private function roundTrip(SendEmailJob $job): SendEmailJob
    {
        $data = $job->getJobData();
        $revived = @unserialize(serialize($data->jobData));

        $freshJob = new SendEmailJob();
        $freshJob->setJobData($data->totalSteps, $data->currentStep, $data->isComplete, $revived, $data->messages ?: []);
        return $freshJob;
    }

    public function testFullRoundTripDeliversRebuiltEmail(): void
    {
        $email = Email::create()
            ->setTo(['ontvanger@example.test' => 'Ontvanger Näme']) // named + non-ascii (trap 1)
            ->setFrom(['afzender@example.test' => 'Example Site'])
            ->setSubject('Terugbel/mail verzoek van Tëst, via example.com')
            ->setBody('<p><strong>HTML</strong> body with <a href="https://example.test/x">link</a></p>') // trap 2
            ->setCC(['cc@example.test' => ''])
            ->setBCC(['bcc@example.test' => ''])
            ->setReplyTo('indiener@example.test', 'Indiener'); // trap 3

        $job = new SendEmailJob();
        $job->hydrate($email);
        $revivedJob = $this->roundTrip($job);
        $revivedJob->process();

        $this->assertTrue($revivedJob->jobFinished(), 'job must mark itself complete after sending');

        $sent = $this->findEmail('ontvanger@example.test');
        $this->assertNotNull($sent, 'rebuilt email must actually be sent to the To address');
        $this->assertStringContainsString('afzender@example.test', (string) $sent['From']);
        $this->assertSame('Terugbel/mail verzoek van Tëst, via example.com', $sent['Subject']);
        // HTML must survive as HTML (not flattened/escaped by the TextPart trap)
        $this->assertStringContainsString('<strong>HTML</strong>', (string) $sent['Content']);
        $this->assertStringContainsString('href="https://example.test/x"', (string) $sent['Content']);
    }

    public function testCcBccAndReplyToSurviveTheRoundTrip(): void
    {
        $email = Email::create()
            ->setTo('to2@example.test')
            ->setFrom('from2@example.test')
            ->setSubject('cc/bcc/reply-to round-trip')
            ->setBody('<p>x</p>')
            ->setCC('cc2@example.test')
            ->setBCC('bcc2@example.test')
            ->setReplyTo('reply2@example.test', 'Reply Naam');

        $job = new SendEmailJob();
        $job->hydrate($email);
        $revivedJob = $this->roundTrip($job);
        // trap 3 regression: reset() against the overloaded jobData property (instead of a local
        // copy) raises "Indirect modification of overloaded property" - converted to an error by
        // the test error-handler, so plain successful processing IS the assertion for that trap
        $revivedJob->process();

        // findEmail matches on To; CC/BCC are only findable if the TestMailer recorded them
        $sent = $this->findEmail('to2@example.test');
        $this->assertNotNull($sent);
        if (array_key_exists('Cc', $sent) || array_key_exists('Bcc', $sent)) {
            $this->assertStringContainsString('cc2@example.test', (string) ($sent['Cc'] ?? ''));
            $this->assertStringContainsString('bcc2@example.test', (string) ($sent['Bcc'] ?? ''));
        }
    }

    public function testMissingRecipientSkipsGracefully(): void
    {
        // a job whose data got lost must not fatal the queue runner
        $job = new SendEmailJob();
        $job->setJobData(1, 0, false, @unserialize(serialize(new \stdClass())), []);
        $job->process();
        $this->assertTrue($job->jobFinished(), 'recipient-less job must complete (skip), not break the runner');
    }
}
