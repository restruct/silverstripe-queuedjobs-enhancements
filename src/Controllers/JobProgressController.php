<?php

namespace Restruct\QueuedJobsEnhancements\Controllers;

use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Security\Security;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Symbiote\QueuedJobs\Services\QueuedJob;

/**
 * REST endpoint for polling job progress.
 * Returns JSON with job status, progress percentage, and latest message.
 *
 * Usage: GET /api/job-progress/{descriptorID}
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

        # Permission check — must be admin or the member who queued the job
        if (!$descriptor->canView($member)) {
            # Fallback: allow if this member is the RunAs user
            if ((int) $descriptor->RunAsID !== (int) $member->ID) {
                return $this->jsonResponse(['error' => 'Not authorised'], 403);
            }
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
            'message' => $this->getLastMessageText($descriptor),
            'complete' => $isTerminal,
            'started' => $descriptor->JobStarted,
            'finished' => $isTerminal ? $descriptor->JobFinished : null,
        ]);
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
