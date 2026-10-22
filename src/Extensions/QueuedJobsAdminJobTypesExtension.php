<?php

namespace Restruct\QueuedJobsEnhancements\Extensions;

use Restruct\QueuedJobsEnhancements\Jobs\ScheduledMethodCall;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\Form;
use Symbiote\QueuedJobs\Controllers\QueuedJobsAdmin;

/**
 * Keeps job classes that must not be created by hand out of upstream's "Create job of type"
 * form in QueuedJobsAdmin. Applied to QueuedJobsAdmin in _config/config.yml.
 *
 * Why: that form (shown when QueuedJobDescriptor::canCreate(), ADMIN by default) lists every
 * instantiable AbstractQueuedJob and passes the textarea's lines to its constructor as strings.
 * For ScheduledMethodCall that is (class, method): anyone with the form could make it call any
 * static method of any class.
 *
 * Removing the class from the dropdown's source also refuses a hand-crafted POST: on submit the
 * form validates the dropdown against its source (Silverstripe 5: the form's default
 * RequiredFields validator calls every field's validate(); Silverstripe 6: SelectField's
 * OptionFieldValidator), and upstream's createjob() is only called for a valid form. The form is
 * rebuilt through getEditForm(), and so through this hook, for the submission too.
 *
 * Code that queues these jobs (ScheduledMethodCall::schedule() and friends) is not affected.
 *
 * @config array $hidden_job_types
 * @property QueuedJobsAdmin $owner
 */
class QueuedJobsAdminJobTypesExtension extends Extension
{
    # Extension itself is not Configurable on either major; needed for hidden_job_types
    use Configurable;

    /**
     * Job classes (and their subclasses) left out of the create-job dropdown. A project can add
     * its own; to show one again, replace the list in project config.
     */
    private static array $hidden_job_types = [
        ScheduledMethodCall::class,
    ];

    /**
     * Public: Silverstripe 5 calls extension hooks from outside the class.
     */
    public function updateEditForm(Form $form): void
    {
        $field = $form->Fields()->dataFieldByName('JobType');
        if (!$field instanceof DropdownField) {
            # No create-job form (the member may not create jobs), or upstream changed its shape
            return;
        }

        $hidden = (array) static::config()->get('hidden_job_types');
        $source = $field->getSource();
        foreach (array_keys($source) as $class) {
            foreach ($hidden as $hiddenClass) {
                if (is_a((string) $class, (string) $hiddenClass, true)) {
                    unset($source[$class]);
                    break;
                }
            }
        }
        $field->setSource($source);
    }
}
