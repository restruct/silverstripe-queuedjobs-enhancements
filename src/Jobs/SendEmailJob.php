<?php

namespace Restruct\QueuedJobsEnhancements\Jobs;

use SilverStripe\Control\Email\Email;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJob;

/**
 * Queued job to send an email asynchronously.
 *
 * Note: extends AbstractQueuedJob which does NOT have Configurable.
 *
 * Promoted 1:1 from a client site (global-namespace SendEmailJob, live in production) as the only
 * correct carrier for deferring a composed Email: ScheduledMethodCall structurally cannot carry
 * one (Email is not a DataObject — it persists class+ID and re-fetches; the SS5-migration attempt
 * called Email::send() STATICALLY on an empty singleton and every notification job fataled).
 *
 * LIMITATION: the round-trip carries To/From/Subject/HTML body/CC/BCC/Reply-To only — NO
 * attachments and NO custom headers. Fine for notifications; do not assume full Email fidelity.
 */
class SendEmailJob extends AbstractQueuedJob implements QueuedJob
{
    public function hydrate(Email $email): void
    {
        // Store email data in jobData (serialised to the DB by QueuedJobs). getTo()/getFrom()/... return
        // arrays of Symfony\Component\Mime\Address OBJECTS; feeding those back into Email::setTo() would
        // double-wrap them (`new Address($addressObject)` -> TypeError "must be of type string"). So flatten
        // each to an [email => name] map, which SS5 Email::setTo()/setFrom()/setCC()/setBCC() accept.
        $this->emailTo = self::addressMap($email->getTo());
        $this->emailFrom = self::addressMap($email->getFrom());
        $this->emailSubject = $email->getSubject();
        // setBody(string) stores the value as the HTML body (Email::setBody -> html()), so read it back via
        // getHtmlBody(). getBody() returns an assembled Symfony TextPart which is NOT string-castable.
        $this->emailBody = (string) $email->getHtmlBody();
        $this->emailCC = self::addressMap($email->getCC());
        $this->emailBCC = self::addressMap($email->getBCC());
        $this->emailReplyTo = self::addressMap($email->getReplyTo());
        $this->totalSteps = 1;
    }

    /**
     * Flatten an array of Symfony Address objects into an [email => name] map (serialisable + re-settable).
     *
     * @param array $addresses Symfony\Component\Mime\Address[]
     * @return array<string,string>
     */
    private static function addressMap(array $addresses): array
    {
        $map = [];
        foreach ($addresses as $address) {
            $map[$address->getAddress()] = $address->getName();
        }
        return $map;
    }

    public function getTitle()
    {
        return 'Send email: ' . ($this->emailSubject ?: '(no subject)');
    }

    public function process()
    {
        if (!$this->emailTo) {
            $this->addMessage('No email recipient set, skipping');
            $this->isComplete = true;
            return;
        }

        $email = Email::create();
        $email->setTo($this->emailTo);
        if ($this->emailFrom) {
            $email->setFrom($this->emailFrom);
        }
        $email->setSubject($this->emailSubject);
        $email->setBody($this->emailBody);
        if ($this->emailCC) {
            $email->setCC($this->emailCC);
        }
        if ($this->emailBCC) {
            $email->setBCC($this->emailBCC);
        }
        if ($this->emailReplyTo) {
            // setReplyTo() takes (string $address, string $name), not a map — unpack the first entry.
            // Copy out of the overloaded jobData property first: reset() needs a reference and would emit
            // "Indirect modification of overloaded property" against the magic __get value otherwise.
            $replyTo = $this->emailReplyTo;
            $email->setReplyTo((string) array_key_first($replyTo), (string) reset($replyTo));
        }

        $email->send();
        $this->currentStep = 1;
        $this->isComplete = true;
    }
}
