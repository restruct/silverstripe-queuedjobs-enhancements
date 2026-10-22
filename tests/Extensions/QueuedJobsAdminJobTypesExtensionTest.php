<?php

namespace Restruct\QueuedJobsEnhancements\Tests\Extensions;

use Restruct\QueuedJobsEnhancements\Jobs\ScheduledMethodCall;
use Restruct\QueuedJobsEnhancements\Tests\Stubs\StubNonDefaultJob;
use SilverStripe\Dev\FunctionalTest;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;

/**
 * Upstream's "Create job of type" form in the Jobs admin, with QueuedJobsAdminJobTypesExtension
 * applied: ScheduledMethodCall is not offered, and a POST naming it anyway creates nothing, while
 * the same POST for an ordinary job still works (the control that shows the POST is valid).
 */
class QueuedJobsAdminJobTypesExtensionTest extends FunctionalTest
{
    protected $usesDatabase = true;

    private const FORM_URL = 'admin/queuedjobs/Symbiote-QueuedJobs-DataObjects-QueuedJobDescriptor/EditForm';

    protected function createJobVia(string $jobType, string $params): void
    {
        $this->post(self::FORM_URL, [
            'JobType' => $jobType,
            'JobParams' => $params,
            'action_createjob' => 'Create new job',
        ]);
    }

    public function testScheduledMethodCallIsNotOffered(): void
    {
        $this->logInWithPermission('ADMIN');

        $body = $this->get('admin/queuedjobs')->getBody();

        $this->assertStringContainsString('name="JobType"', $body, 'the create-job form is rendered');
        $this->assertStringContainsString(htmlspecialchars(StubNonDefaultJob::class), $body);
        $this->assertStringNotContainsString(htmlspecialchars(ScheduledMethodCall::class), $body);
    }

    public function testCraftedPostCannotCreateAScheduledMethodCall(): void
    {
        $this->logInWithPermission('ADMIN');

        # Control: the same POST for an ordinary job type does create a job
        $this->createJobVia(StubNonDefaultJob::class, '');
        $this->assertSame(
            1,
            QueuedJobDescriptor::get()->filter('Implementation', StubNonDefaultJob::class)->count(),
            'the create-job POST works for a job type that is offered'
        );

        # The attack: any static method, here one that is harmless
        $this->createJobVia(ScheduledMethodCall::class, "SilverStripe\\Core\\Environment\ngetEnv");
        $this->assertSame(
            0,
            QueuedJobDescriptor::get()->filter('Implementation', ScheduledMethodCall::class)->count()
        );
    }
}
