<?php

namespace Restruct\QueuedJobsEnhancements\Jobs;

use SilverStripe\Core\Config\Config;

/**
 * Opt-in trait for jobs extending AbstractQueuedJob: keeps the job's message log bounded.
 *
 * Why: the job's messages are serialised into QueuedJobDescriptor.SavedJobMessages and written to
 * the database after EVERY step (QueuedJobService::copyJobToDescriptor()), so the cost of a message
 * log is its size times the number of steps. A job that logs per item in a loop therefore does
 * quadratic work and allocates a fresh, ever larger string per step, until it runs out of memory.
 * Keeping the HEAD preserves the setup and context; keeping the TAIL preserves what the job was
 * doing last, which is the part a failure needs. Dropping the middle is what makes it bounded.
 *
 * Use it in the job class:
 *
 *     class MySyncJob extends AbstractQueuedJob
 *     {
 *         use BoundedJobMessages;
 *     }
 *
 * Three separate settings groups, each read from the CONCRETE job class's config (a private static
 * on the job class, or YAML keyed to the job class; inherited by its subclasses). YAML keyed to the
 * trait's name would be silently inert: config is composed over class names only. The trait itself
 * declares no statics, so a job can declare any of them without a property conflict:
 *
 *  1. Bounding (on once the trait is used):
 *       bound_messages: true         false = keep every message (the other two still apply)
 *       keep_first_messages: 10      the head that is always kept
 *       keep_last_messages: 200      the tail that is always kept
 *       omitted_messages_marker: '... %d earlier message(s) omitted to keep the job log bounded ...'
 *                                    the one message that stands in for the dropped middle; %d is
 *                                    the total number dropped so far
 *  2. Severity filter (off by default):
 *       min_message_severity: ''     e.g. 'WARNING' drops INFO and DEBUG messages entirely
 *                                    (ERROR < WARNING < NOTICE < INFO < DEBUG; an unknown severity
 *                                    counts as INFO)
 *  3. Memory suffix (off by default):
 *       message_memory_usage: false  true appends " [current/peakMB]" to every kept message
 *
 * The omitted count is stored in the job's data (the magic jobData property), so it survives the
 * serialise/restore cycle between steps, and the previous marker is recognised on the next trim, so
 * the counts add up instead of the marker itself being counted as a dropped message.
 *
 * NB: a job (or an intermediate base class below it) that declares its own addMessage() wins over
 * this trait's. Such a job calls $this->addBoundedMessage($message, $severity) from its own
 * addMessage() instead of parent::addMessage().
 *
 * The descriptor log becomes a summary, not a transcript: send anything that must be kept in full
 * to a PSR logger as well.
 */
trait BoundedJobMessages
{
    /**
     * Severity ranks for the min_message_severity filter: lower is more severe. A method rather than
     * a static or a constant: a static in a trait is copied into the job class (where a job's own
     * declaration of the same name would conflict), and trait constants need PHP 8.2.
     *
     * @return array<string, int>
     */
    protected function boundedMessageSeverityRanks(): array
    {
        return [
            'EMERGENCY' => 0,
            'ALERT' => 0,
            'CRITICAL' => 0,
            'CRIT' => 0,
            'ERROR' => 0,
            'ERR' => 0,
            'WARNING' => 1,
            'WARN' => 1,
            'NOTICE' => 2,
            'INFO' => 3,
            'DEBUG' => 4,
        ];
    }

    /**
     * Same signature as AbstractQueuedJob::addMessage() on queuedjobs 5 and 6.
     *
     * @param string $message
     * @param string $severity
     */
    public function addMessage($message, $severity = 'INFO')
    {
        $this->addBoundedMessage($message, $severity);
    }

    /**
     * Filter, suffix, add (through the parent class's addMessage(), so its format is kept), trim.
     *
     * @param string $message
     * @param string $severity
     */
    protected function addBoundedMessage($message, $severity = 'INFO'): void
    {
        if (!$this->boundedMessagePassesFilter((string) $severity)) {
            return;
        }

        if ($this->boundedMessageSetting('message_memory_usage', false)) {
            $message .= $this->boundedMessageMemorySuffix();
        }

        parent::addMessage($message, $severity);

        if ($this->boundedMessageSetting('bound_messages', true)) {
            $this->trimBoundedMessages();
        }
    }

    /**
     * Keep the first N and the last M messages, with one marker in between that counts every
     * message dropped so far (also in earlier steps). Public so a job can trim a log it inherited,
     * e.g. right after setup() of a job that was queued before it used this trait.
     */
    public function trimBoundedMessages(): void
    {
        $keepFirst = max(0, (int) $this->boundedMessageSetting('keep_first_messages', 10));
        $keepLast = max(0, (int) $this->boundedMessageSetting('keep_last_messages', 200));

        $messages = array_values((array) $this->messages);

        # Take out the marker of the previous trim, wherever it ended up (the head size may have been
        # changed in between), so it is neither kept twice nor counted as a dropped message.
        $state = $this->boundedMessageState();
        $omitted = (int) ($state['omitted'] ?? 0);
        if (!empty($state['marker'])) {
            $markerIndex = array_search($state['marker'], $messages, true);
            if ($markerIndex !== false) {
                array_splice($messages, $markerIndex, 1);
            }
        }

        $dropped = count($messages) - $keepFirst - $keepLast;
        if ($dropped <= 0) {
            # Nothing (more) to drop: leave the log as it is, marker included ($messages is a copy)
            return;
        }

        $omitted += $dropped;
        $marker = $this->boundedMessageMarker($omitted);

        $this->messages = array_merge(
            array_slice($messages, 0, $keepFirst),
            [$marker],
            # array_slice($x, -0) is the whole array: a tail of 0 must be an empty slice
            $keepLast > 0 ? array_slice($messages, -$keepLast) : []
        );

        $this->setBoundedMessageState(['omitted' => $omitted, 'marker' => $marker]);
    }

    /**
     * How many messages were dropped so far (0 when none).
     */
    public function getOmittedMessageCount(): int
    {
        return (int) ($this->boundedMessageState()['omitted'] ?? 0);
    }

    /**
     * The marker message for a total of $omitted dropped messages.
     */
    protected function boundedMessageMarker(int $omitted): string
    {
        $text = (string) $this->boundedMessageSetting(
            'omitted_messages_marker',
            '... %d earlier message(s) omitted to keep the job log bounded ...'
        );

        # A marker text without %d is used as it is
        return strpos($text, '%d') !== false ? sprintf($text, $omitted) : $text;
    }

    protected function boundedMessagePassesFilter(string $severity): bool
    {
        $ranks = $this->boundedMessageSeverityRanks();
        $min = strtoupper(trim((string) $this->boundedMessageSetting('min_message_severity', '')));
        if ($min === '' || !isset($ranks[$min])) {
            # Off (or a value it does not know: never silently drop messages over a typo)
            return true;
        }

        $rank = $ranks[strtoupper(trim($severity))] ?? $ranks['INFO'];

        return $rank <= $ranks[$min];
    }

    /**
     * Compact memory reading appended to a message, e.g. " [43.1/47.2MB]" (current/peak).
     * Deliberately terse: it is stored with every message and re-serialised on every step.
     */
    protected function boundedMessageMemorySuffix(): string
    {
        return sprintf(
            ' [%.1f/%.1fMB]',
            memory_get_usage() / 1048576,
            memory_get_peak_usage() / 1048576
        );
    }

    /**
     * A setting from the concrete job class's config, or the trait default when it is not set.
     * AbstractQueuedJob is not Configurable, so Config::inst() is read directly (that also reads a
     * private static declared on the job class). A null (unset) value means the default, so only
     * an explicit value changes behaviour.
     *
     * @param mixed $default
     * @return mixed
     */
    protected function boundedMessageSetting(string $name, $default)
    {
        $value = Config::inst()->get(static::class, $name);

        return $value ?? $default;
    }

    /**
     * The trim state lives in the job data (AbstractQueuedJob's magic __get/__set write to
     * $this->jobData), which QueuedJobService serialises with the job after every step; a plain
     * property would be lost between steps.
     *
     * @return array{omitted?: int, marker?: string}
     */
    protected function boundedMessageState(): array
    {
        $state = $this->QJEBoundedMessages;

        return is_array($state) ? $state : [];
    }

    protected function setBoundedMessageState(array $state): void
    {
        $this->QJEBoundedMessages = $state;
    }
}
