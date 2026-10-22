<?php

namespace Restruct\QueuedJobsEnhancements\Tests\Extensions;

use SilverStripe\Dev\SapphireTest;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Symbiote\QueuedJobs\Services\QueuedJob;

/**
 * QueuedJobDescriptorPermissionExtension through the real canView() of the descriptor: it adds
 * the RunAs member and nobody else, and leaves ADMIN to upstream's default.
 */
class QueuedJobDescriptorPermissionExtensionTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected function createMember(string $email): Member
    {
        $member = Member::create();
        $member->Email = $email;
        $member->write();

        return $member;
    }

    protected function createJob(int $runAsId): QueuedJobDescriptor
    {
        $descriptor = QueuedJobDescriptor::create();
        $descriptor->Implementation = 'TestJob';
        $descriptor->JobStatus = QueuedJob::STATUS_NEW;
        $descriptor->JobTitle = 'Permission test job';
        $descriptor->RunAsID = $runAsId;
        $descriptor->write();

        return $descriptor;
    }

    public function testRunAsMemberCanView(): void
    {
        $member = $this->createMember('runas@example.com');
        $descriptor = $this->createJob($member->ID);

        $this->assertTrue($descriptor->canView($member));
        # A member ID works as well as a Member, as with Permission::check()
        $this->assertTrue($descriptor->canView($member->ID));
        # null = the current member
        $this->logInAs($member);
        $this->assertTrue($descriptor->canView());
    }

    public function testOtherMembersCannotView(): void
    {
        $owner = $this->createMember('owner@example.com');
        $other = $this->createMember('other@example.com');

        $this->assertFalse($this->createJob($owner->ID)->canView($other));
        # A job without a RunAs member is nobody's but ADMIN's
        $this->assertFalse($this->createJob(0)->canView($other));

        $this->logOut();
        $this->assertFalse($this->createJob($owner->ID)->canView());
    }

    public function testAdminStillDecidedByUpstreamDefault(): void
    {
        $owner = $this->createMember('owner@example.com');
        $this->logInWithPermission('ADMIN');

        # ADMIN, who is not the RunAs member, through upstream's default
        $this->assertTrue($this->createJob($owner->ID)->canView(Security::getCurrentUser()));
        # The extension adds view only: edit and delete stay with upstream's ADMIN default
        $this->assertFalse($this->createJob($owner->ID)->canEdit($owner));
        $this->assertFalse($this->createJob($owner->ID)->canDelete($owner));
    }
}
