<?php

namespace Restruct\QueuedJobsEnhancements\Extensions;

use SilverStripe\Core\Extension;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;

/**
 * Lets the member a job runs as (RunAsID, normally the member who queued it) view that job's
 * descriptor. Applied to QueuedJobDescriptor in _config/config.yml.
 *
 * The rule used to live in JobProgressController, as a fallback after canView(). Moved onto the
 * model so that every caller that asks canView() gets the same answer, instead of each endpoint
 * having to remember the extra rule (or the filter that goes with it).
 *
 * How it combines with the rest: upstream's QueuedJobDescriptor has no canView() of its own, so
 * DataObject::canView() applies on both majors: it asks extendedCan() first and falls back to
 * Permission::check('ADMIN') only when every extension returned null. extendedCan() drops the
 * nulls and returns the lowest remaining result, so a false from any other extension still wins.
 * This extension therefore only ever adds a true for the RunAs member, and says nothing (null)
 * otherwise, which leaves ADMIN (and any other extension) deciding as before.
 *
 * Where upstream consults canView() on a descriptor: only GridField's detail form and its
 * view/edit buttons in QueuedJobsAdmin. That admin's list is not filtered by canView() at all, and
 * reaching it needs CMS_ACCESS_QueuedJobsAdmin, so such a member already sees every row there;
 * this rule only lets them open the read-only detail view of their own jobs (canEdit() is
 * untouched, so still ADMIN only).
 *
 * @property QueuedJobDescriptor $owner
 */
class QueuedJobDescriptorPermissionExtension extends Extension
{
    /**
     * Public: Silverstripe 5 calls extension hooks from outside the class.
     *
     * @param Member|int|null $member null = the current member
     * @param array $context
     * @return bool|null true for the RunAs member, null (no opinion) for everyone else
     */
    public function canView($member = null, $context = [])
    {
        if (!$member) {
            $member = Security::getCurrentUser();
        }
        # Permission::check() also accepts a member ID, so callers may pass one
        $memberId = $member instanceof Member ? (int) $member->ID : (int) $member;
        if ($memberId <= 0) {
            return null;
        }

        $runAsId = (int) $this->getOwner()->RunAsID;
        if ($runAsId > 0 && $runAsId === $memberId) {
            return true;
        }

        return null;
    }
}
