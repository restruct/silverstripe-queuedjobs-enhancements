<?php

namespace Restruct\QueuedJobsEnhancements\Tests\Controllers;

use Restruct\QueuedJobsEnhancements\Controllers\JobProgressController;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\Security\Member;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Symbiote\QueuedJobs\Services\QueuedJob;

# Note: Uses $this->get() instead of Director::test() to properly
# propagate session/auth state from logInWithPermission().
class JobProgressControllerTest extends FunctionalTest
{
    protected $usesDatabase = true;

    public function testUnauthenticatedReturns401(): void
    {
        $this->logOut();

        $response = $this->get('api/job-progress/1');

        $this->assertEquals(401, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertEquals('Not authenticated', $body['error']);
    }

    public function testMissingIdReturns400(): void
    {
        $this->logInWithPermission('ADMIN');

        $response = $this->get('api/job-progress/');

        $this->assertEquals(400, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertEquals('Missing job ID', $body['error']);
    }

    public function testNonExistentJobReturns404(): void
    {
        $this->logInWithPermission('ADMIN');

        $response = $this->get('api/job-progress/999999');

        $this->assertEquals(404, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertEquals('Job not found', $body['error']);
    }

    public function testReturnsProgressForRunningJob(): void
    {
        $this->logInWithPermission('ADMIN');

        # Create a running job
        $descriptor = QueuedJobDescriptor::create();
        $descriptor->Implementation = 'TestJob';
        $descriptor->JobStatus = QueuedJob::STATUS_RUN;
        $descriptor->JobTitle = 'Test running job';
        $descriptor->TotalSteps = 100;
        $descriptor->StepsProcessed = 42;
        $descriptor->write();

        $response = $this->get('api/job-progress/' . $descriptor->ID);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('application/json', $response->getHeader('Content-Type'));

        $body = json_decode($response->getBody(), true);
        $this->assertEquals($descriptor->ID, $body['id']);
        $this->assertEquals('Running', $body['status']);
        $this->assertEquals('Test running job', $body['title']);
        $this->assertEquals(42, $body['progress']);
        $this->assertEquals(100, $body['total']);
        $this->assertEquals(42, $body['percent']);
        $this->assertFalse($body['complete']);
        $this->assertNull($body['finished']);
    }

    public function testReturnsCompleteForFinishedJob(): void
    {
        $this->logInWithPermission('ADMIN');

        $descriptor = QueuedJobDescriptor::create();
        $descriptor->Implementation = 'TestJob';
        $descriptor->JobStatus = QueuedJob::STATUS_COMPLETE;
        $descriptor->JobTitle = 'Test complete job';
        $descriptor->TotalSteps = 50;
        $descriptor->StepsProcessed = 50;
        $descriptor->JobStarted = '2025-01-01 10:00:00';
        $descriptor->JobFinished = '2025-01-01 10:05:00';
        $descriptor->write();

        $response = $this->get('api/job-progress/' . $descriptor->ID);

        $body = json_decode($response->getBody(), true);
        $this->assertTrue($body['complete']);
        $this->assertEquals(100, $body['percent']);
        $this->assertNotNull($body['finished']);
    }

    public function testRunAsUserCanViewOwnJob(): void
    {
        # Create a non-admin user
        $member = Member::create();
        $member->Email = 'testuser@example.com';
        $member->write();

        $this->logInAs($member);

        # Create a job owned by this user
        $descriptor = QueuedJobDescriptor::create();
        $descriptor->Implementation = 'TestJob';
        $descriptor->JobStatus = QueuedJob::STATUS_RUN;
        $descriptor->JobTitle = 'User job';
        $descriptor->RunAsID = $member->ID;
        $descriptor->TotalSteps = 10;
        $descriptor->StepsProcessed = 5;
        $descriptor->write();

        $response = $this->get('api/job-progress/' . $descriptor->ID);

        $this->assertEquals(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertEquals('User job', $body['title']);
    }

    public function testNoCacheHeaders(): void
    {
        $this->logInWithPermission('ADMIN');

        $descriptor = QueuedJobDescriptor::create();
        $descriptor->Implementation = 'TestJob';
        $descriptor->JobStatus = QueuedJob::STATUS_NEW;
        $descriptor->JobTitle = 'Test job';
        $descriptor->write();

        $response = $this->get('api/job-progress/' . $descriptor->ID);

        $this->assertStringContainsString('no-store', $response->getHeader('Cache-Control'));
    }

    protected function createMember(string $email): Member
    {
        $member = Member::create();
        $member->Email = $email;
        $member->write();

        return $member;
    }

    /**
     * A job descriptor running as $runAs, with one saved message (the shape upstream stores).
     */
    protected function createJob(string $status, int $runAsId, string $lastMessage): QueuedJobDescriptor
    {
        $descriptor = QueuedJobDescriptor::create();
        $descriptor->Implementation = 'TestJob';
        $descriptor->JobStatus = $status;
        $descriptor->JobTitle = 'Job of member #' . $runAsId;
        $descriptor->RunAsID = $runAsId;
        $descriptor->SavedJobMessages = serialize(['[2026-10-22 10:00:00][INFO] Started', $lastMessage]);
        $descriptor->write();

        return $descriptor;
    }

    /**
     * Another member's job answers exactly like a job that does not exist, so a member cannot
     * learn which descriptor IDs exist (1.7.0 answered 403 "Not authorised").
     */
    public function testJobOfAnotherMemberReturns404LikeAMissingJob(): void
    {
        $owner = $this->createMember('owner@example.com');
        $other = $this->createMember('other@example.com');
        $descriptor = $this->createJob(QueuedJob::STATUS_RUN, $owner->ID, 'Processing row 3');

        $this->logInAs($other);
        $response = $this->get('api/job-progress/' . $descriptor->ID);
        $missing = $this->get('api/job-progress/999999');

        $this->assertEquals(404, $response->getStatusCode());
        $this->assertSame($missing->getStatusCode(), $response->getStatusCode());
        $this->assertSame($missing->getBody(), $response->getBody());
        $this->assertStringNotContainsString('Processing row 3', $response->getBody());
    }

    /**
     * A Broken job's last message is often its exception text: the RunAs member gets a generic
     * message instead.
     */
    public function testBrokenJobMessageIsGenericForTheRunAsMember(): void
    {
        $member = $this->createMember('runas@example.com');
        $descriptor = $this->createJob(
            QueuedJob::STATUS_BROKEN,
            $member->ID,
            'App\\Sync::run ERROR: SQLSTATE[42S02] table secret_db.x not found in /var/www/app/src/Sync.php'
        );

        $this->logInAs($member);
        $response = $this->get('api/job-progress/' . $descriptor->ID);

        $this->assertEquals(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertSame('Broken', $body['status']);
        $this->assertTrue($body['complete']);
        $this->assertSame(JobProgressController::BROKEN_MESSAGE, $body['message']);
        $this->assertStringNotContainsString('SQLSTATE', $response->getBody());
    }

    /**
     * Messages of jobs that are not Broken still reach the RunAs member.
     */
    public function testRunningJobMessageReachesTheRunAsMember(): void
    {
        $member = $this->createMember('runas@example.com');
        $descriptor = $this->createJob(QueuedJob::STATUS_RUN, $member->ID, 'Processing row 15');

        $this->logInAs($member);
        $body = json_decode($this->get('api/job-progress/' . $descriptor->ID)->getBody(), true);

        $this->assertSame('Processing row 15', $body['message']);
    }

    /**
     * ADMIN sees the real message of a Broken job.
     */
    public function testBrokenJobMessageIsShownToAdmin(): void
    {
        $owner = $this->createMember('owner@example.com');
        $descriptor = $this->createJob(QueuedJob::STATUS_BROKEN, $owner->ID, 'Sync::run ERROR: boom');

        $this->logInWithPermission('ADMIN');
        $body = json_decode($this->get('api/job-progress/' . $descriptor->ID)->getBody(), true);

        $this->assertSame('Sync::run ERROR: boom', $body['message']);
    }
}
