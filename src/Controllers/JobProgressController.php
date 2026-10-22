<?php

namespace Restruct\QueuedJobsEnhancements\Controllers;

use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Symbiote\QueuedJobs\Services\QueuedJob;

/**
 * REST endpoint for polling job progress.
 * Returns JSON with job status, progress percentage, and latest message.
 *
 * Usage: GET /api/job-progress/{descriptorID}
 *
 * Access is $descriptor->canView($member): ADMIN, or the member the job runs as (through
 * QueuedJobDescriptorPermissionExtension). A job the member may not view answers exactly like a
 * job that does not exist (404), so descriptor IDs cannot be probed. The last message of a Broken
 * job can carry exception text, so only ADMIN gets it; others get BROKEN_MESSAGE instead.
 *
 * @config string $url_segment
 * @config array $url_handlers
 * @config array $allowed_actions
 */
class JobProgressController extends Controller
{
    # Terminal statuses — job is done (success or failure)
    private const TERMINAL_STATUSES = [
        QueuedJob::STATUS_COMPLETE,
        QueuedJob::STATUS_BROKEN,
        QueuedJob::STATUS_CANCELLED,
    ];

    /**
     * What a non-admin gets as `message` for a Broken job, instead of its last message.
     */
    public const BROKEN_MESSAGE = 'Job failed';

    private static string $url_segment = 'api/job-progress';

    private static array $url_handlers = [
        '$ID' => 'progress',
    ];

    private static array $allowed_actions = [
        'progress',
    ];

    public function progress(HTTPRequest $request): HTTPResponse
    {
        # Require logged-in user
        $member = Security::getCurrentUser();
        if (!$member) {
            return $this->jsonResponse(['error' => 'Not authenticated'], 401);
        }

        $id = (int) $request->param('ID');
        if (!$id) {
            return $this->jsonResponse(['error' => 'Missing job ID'], 400);
        }

        $descriptor = QueuedJobDescriptor::get()->byID($id);
        if (!$descriptor) {
            return $this->jsonResponse(['error' => 'Job not found'], 404);
        }

        # Permission check: ADMIN, or the member the job runs as. The RunAs rule is now part of
        # canView() itself (QueuedJobDescriptorPermissionExtension), so every caller of canView()
        # agrees. Not permitted answers like not found: a 403 told a caller the ID exists.
        // old: the RunAs fallback lived here, and a refusal was a distinguishable 403
        // # Permission check — must be admin or the member who queued the job
        // if (!$descriptor->canView($member)) {
        //     # Fallback: allow if this member is the RunAs user
        //     if ((int) $descriptor->RunAsID !== (int) $member->ID) {
        //         return $this->jsonResponse(['error' => 'Not authorised'], 403);
        //     }
        // }
        if (!$descriptor->canView($member)) {
            return $this->jsonResponse(['error' => 'Job not found'], 404);
        }

        $isTerminal = in_array($descriptor->JobStatus, self::TERMINAL_STATUSES);
        $totalSteps = (int) $descriptor->TotalSteps;
        $stepsProcessed = (int) $descriptor->StepsProcessed;

        return $this->jsonResponse([
            'id' => (int) $descriptor->ID,
            'status' => $descriptor->JobStatus,
            'title' => $descriptor->JobTitle,
            'progress' => $stepsProcessed,
            'total' => $totalSteps,
            'percent' => $totalSteps > 0
                ? round(($stepsProcessed / $totalSteps) * 100)
                : 0,
            // old: the last message for everyone, also a Broken job's exception text
            // 'message' => $this->getLastMessageText($descriptor),
            'message' => $this->getMessageFor($descriptor, $member),
            'complete' => $isTerminal,
            'started' => $descriptor->JobStarted,
            'finished' => $isTerminal ? $descriptor->JobFinished : null,
        ]);
    }

    /**
     * The `message` to return to this member: the last message, except for a Broken job seen by a
     * non-admin. A Broken job's last message is often the exception that broke it (upstream
     * stores the exception message; ScheduledMethodCall logs "Class::method ERROR: <message>"),
     * which can expose paths, SQL or other internals to the member who merely queued the job.
     */
    protected function getMessageFor(QueuedJobDescriptor $descriptor, Member $member): ?string
    {
        if ($descriptor->JobStatus === QueuedJob::STATUS_BROKEN && !Permission::checkMember($member, 'ADMIN')) {
            return static::BROKEN_MESSAGE;
        }

        return $this->getLastMessageText($descriptor);
    }

    /**
     * Extract the last message as plain text from the serialized messages blob.
     */
    protected function getLastMessageText(QueuedJobDescriptor $descriptor): ?string
    {
        $lastMsg = $descriptor->getLastMessage();
        if (!$lastMsg) {
            return null;
        }

        # getLastMessage() returns the raw unserialized entry — could be a string
        # or an object with a 'message' key (TimedMessage format used by QueuedJobs)
        if (is_object($lastMsg) && isset($lastMsg->message)) {
            return strip_tags($lastMsg->message);
        }
        if (is_array($lastMsg) && isset($lastMsg['message'])) {
            return strip_tags($lastMsg['message']);
        }

        return strip_tags((string) $lastMsg);
    }

    protected function jsonResponse(array $data, int $statusCode = 200): HTTPResponse
    {
        $response = HTTPResponse::create(json_encode($data));
        $response->setStatusCode($statusCode);
        $response->addHeader('Content-Type', 'application/json');
        # No caching — always fresh progress data
        $response->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate');
        return $response;
    }
}
