<?php

declare(strict_types=1);

namespace ProcessWire;

wire('classLoader')->addNamespace('FormBuilderProcessorMailchimp\App', __DIR__ . '/app');

use DateTimeImmutable;
use Exception;
use FormBuilderProcessorMailchimp\App\{ DataMaintenance, MailchimpClient };
use stdClass;

class FormBuilderProcessorMailchimp extends FormBuilderProcessorAction implements Module, ConfigurableModule
{
    /**
     * Name of ProcessWire log
     */
    private const LOG_NAME = 'formbuilder-processor-mailchimp';

    /**
     * Holds the persisted Mailchimp API data for action configuration and form processing
     */
    private ?stdClass $mailchimpData = null;

    private ?stdClass $processingConfig = null;

    /**
     * Process submitted form
     */
    public function processReady(): void
    {
        if (!$this->mailchimp_audience_id || !$this->mailchimp_api_ready) {
            return;
        }

        // Set parsed configs for processing this form submission
        $this->processingConfig = $this->getFormProcessingConfigs();

        // Load stored Mailchimp data
        $this->mailchimpData = $this->getMailchimpApiData();

        // Form submission Data
        $postData = $this->input->post->getArray();

        $this->debugEvent('Form submitted', $postData);

        // Check if submission qualifies for Mailchimp processing
        if (!$this->isMailchimpSubmittable($postData)) {
            return;
        }

        $subscriberData = $this->parseFormSubmission($postData);

        $this->debugEvent('Form submission parsed for Mailchimp', $subscriberData);

        if (!$subscriberData) {
            return;
        }

        $this->subscribe($subscriberData);
    }


    /**
     * Submit subscription to Mailchimp
     * @param array<string, mixed> $subscriberData Processed POST data for submission
     * @param string               $audienceId     Optional audience ID override for hooking
     */
    private function subscribe(array $subscriberData): void
    {
        $audienceId ??= $this->mailchimp_audience_id;
        $mailchimpClient = MailchimpClient::init($this->mailchimp_api_key);
        $subscriptionAction = $this->processingConfig->action;

        match ($subscriptionAction) {
            'add_update' => $mailchimpClient->subscribeOrUpdate($subscriberData, $audienceId),
            'add' => $mailchimpClient->subscribe($subscriberData, $audienceId),
        };

        $this->logResult($mailchimpClient, $subscriberData);
    }

    /**
     * Sends success or error details to ProcessWire log
     * @param  MailchimpClient $mailchimp
     */
    private function logResult(MailchimpClient $mailchimpClient, array $subscriberData): void
    {
        $lastResponse = $mailchimpClient->mailchimp->getLastResponse();
        $responseStatus = $lastResponse['headers']['http_code'];
        $responseBody = json_decode($lastResponse['body']);

        if ($responseStatus !== 200 && !$this->wire()->config->debug) {
            $submissionEmail = $subscriberData[$this->emailAddressField] ?? 'Not present';

            $this->logMailchimpSubmissionFailure(
                $responseBody->title,
                "Status: {$responseBody->status}",
                "Detail: {$responseBody->detail}",
                "Submission Email: {$submissionEmail}",
                "Instance: {$responseBody->instance}",
            );

            return;
        }

        // Debug logs all responses
        $this->debugEvent('Mailchimp response', $lastResponse['body']);
    }

    /**
     * Checks suitibility for submission. Rejects if:
     * - If there is a checkbox designated as an opt-in and whether this submission qualifies
     * - If the email address field is missing or has no value
     */
    private function isMailchimpSubmittable(array $formData): bool
    {
        $optInCheckbox = $this->processingConfig->optInCheckboxField;
        $emailField = $this->processingConfig->emailAddressField;

        if (!array_key_exists($emailField, $formData)) {
            $this->logRejectedFormSubmission('Submitted data did not contain an email field');

            return false;
        }

        if (empty($formData[$emailField])) {
            $this->logRejectedFormSubmission('Submitted data did not contain an email address');


            return false;
        }

        if (!$optInCheckbox) {
            return true;
        }

        // Opt-in checkbox is configured but value isn't present in the POST data
        if (!array_key_exists($optInCheckbox, $formData) && $optInCheckbox) {
            $this->logRejectedFormSubmission(
                'An opt-in checkbox field configured but missing in the submitted form'
            );

            return false;
        }

        return filter_var($formData[$optInCheckbox], FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Parses POST data and prepares Mailchimp payload according to configuration
     * @param array<string, mixed> $formData          Form submission data
     */
    private function parseFormSubmission(array $formData): array
    {
        $processingConfig = $this->processingConfig;

        $mergeFields = array_filter([
            ...$this->getSubmissionMergeFields($formData),
            ...$this->getSubmissionAddressMergeFields($formData),
        ]);

        return array_filter([
            'email_address' => mb_strtolower($formData[$processingConfig->emailAddressField], 'UTF-8'),
            'merge_fields' => $mergeFields,
            'interests' => $this->getSubmissionInterestCategories($formData),
            'tags' => $processingConfig->audienceTags,
            'email_type' => $this->getSubmissionEmailType($formData),
            'ip_signup' => $processingConfig->collectIp ? wire('session')->getIP() : null,
            ...$this->getSubscriberStatus(),
        ]);
    }

    /**
     * Gets subscriber status depending on subscription action
     */
    private function getSubscriberStatus(): array
    {
        $status = $this->processingConfig->status;

        return match ($this->processingConfig->action) {
            'unsubscribe' => ['status' => 'unsubscribed'],
            'add' => ['status' => $status->new],
            default => [
                'status_if_new' => $status->new,
                'status' => $status->update,
            ],
        };
    }

    /**
     * Parse configs and optionally submission data for email type, falls back to 'html'
     * @param  array    $formData         Submitted form data
     */
    private function getSubmissionEmailType(array $formData): string
    {
        $type = $this->processingConfig->emailType->value;

        $emailType = match ($type) {
            'use_field' => $formData[$this->processingConfig->emailType->field] ?? null,
            default => $type,
        };

        $typeIsValid = in_array($emailType, ['html', 'text']);

        !$typeIsValid && $this->logFormSubmissionError(
            "Invalid email type: {$emailType}",
            "Default value 'html' used"
        );

        return $typeIsValid ? $emailType : 'html';
    }

    /**
     * Parses POST data for configured fields/data to submit to mailchimp
     * @param array<string, mixed> $formData Form submission data
     */
    private function getSubmissionMergeFields(array $formData): array
    {
        $mergeFields = $this->processingConfig->mergeFields;

        // Convert merge tag config value to form value, [MERGETAG => 'submitted value']
        array_walk($mergeFields, function(&$formField, $mergeTag) use ($formData) {
            $formField = $this->getSubmissionMergeFieldValue($mergeTag, $formField, $formData);
        });

        return array_filter($mergeFields);
    }

    /**
     * Gets the Mailchimp appropriate value for a given submitted form value
     * @param  string $mergeTag   Mailchimp field merge tag
     * @param  string $formField  FormBuilder field name
     * @param  array  $formData   Form submission POST data
     * @return mixed              Value in format expected by Mailchimp
     */
    private function getSubmissionMergeFieldValue(
        string $mergeTag,
        string $formField,
        array $formData,
    ): mixed {
        $field = $this->fbForm->children[$formField];
        $value = $formData[$field->name] ?? null;

        // Unrecognized field or no value
        if (!$field || !$value) {
            return null;
        }

        switch ($field->type) {
            case 'Page':
                $value = wire('pages')->get($value)?->title;
                break;
            case 'Datetime':
                $format = $this->processingConfig->dateFormats[$mergeTag] ?? false;

                $value = $format ? (new DateTimeImmutable($value))->format($format) : null;
                break;
            default:
                $value = $value;
                break;
        }

        return is_string($value) ? trim($value) : $value;
    }

    /**
     * Get submission data for address merge fields
     * @param  array  $formData   Form submission POST data
     */
    private function getSubmissionAddressMergeFields(array $formData): array
    {
        $addressMergeFields = $this->processingConfig->addressMergeFields;

        array_walk($addressMergeFields, function(&$subfields) use ($formData) {
            $subfields = array_map(
                fn ($formField) => $formData[$formField] ?? null,
                $subfields
            );
        });

        return array_filter($addressMergeFields);
    }

    /**
     * Parses submission data for interest categories
     * - Performs basic matching assistance to match close but not exact values from fields
     *   configured in FormBuilder to help with small differences from Mailchimp values
     * @param  array  $formData FormBuilder form submitted data
     * @return array<string, bool>
     */
    private function getSubmissionInterestCategories(array $formData): array
    {
        $interestCategories = array_intersect_key(
            $this->processingConfig->interestCategories,
            $formData
        );

        // Trims, lowercases, removes non-alphanumeric characters
        $stripValues = function(array $values) {
            return array_map(function($value) {
                $value = (string) $value;
                $value = mb_strtolower($value, 'UTF-8');
                $value = trim($value);
                $value = preg_replace('/[^a-z0-9 ]/i', '', $value);

                return $value;
            }, $values);
        };

        // Simplify Mailchimp values for comparison
        $interestCategories = array_map($stripValues, $interestCategories);

        array_walk($interestCategories, function(&$interests, $fieldName) use ($formData, $stripValues) {
            $submittedValues = $stripValues($formData[$fieldName]);

            $interests = array_map(
                fn ($configuredValue) => in_array($configuredValue, $submittedValues),
                $interests
            );
        });

        // Flatten into single array of ids/values
        $result =  array_reduce(
            $interestCategories,
            fn ($mailchimpData, $interests) => $mailchimpData = [...$mailchimpData, ...$interests],
            []
        );

        // Remove false values
        return array_filter($result);
    }

    /**
     * Form processor configuration
     */
    public function getConfigInputfields(InputfieldWrapper $inputfields): InputfieldWrapper
    {
        parent::getConfigInputfields($inputfields);
        $wire = $this->wire();
        $modules = $wire->modules;

        if (!$this->mailchimp_api_ready) {
            $notReady = $modules->get('InputfieldMarkup');
            $notReady->value = <<<EOD
            <p>Add a valid Mailchimp API key on the Mailchimp Processor module configuration page to get started</p>
            EOD;

            return $inputfields->add($notReady);
        }

        try {
            $this->mailchimpData = $this->getMailchimpApiData(useLocal: false);
        } catch (Exception $e) {
            $wire->error("Mailchimp error: {$e->getMessage()}");

            return $inputfields;
        }

        /**
         * Usage instructions
         */
        $usageNotes = $modules->get('InputfieldMarkup');
        $usageNotes->label = 'How to configure this form for use with Mailchimp';
        $usageNotes->collapsed = Inputfield::collapsedYes;
        $usageNotes->themeOffset = 'm';
        $usageNotes->value = <<<EOD
        <h3 style="margin-top: 1.5rem;">Mailchimp Audience</h3>
        <p>The Audience (aka List) is the destination for subscriptions from this form.</p>

        <h3 style="margin-top: 1.5rem;">Subscription Action & Subscriber Status</h3>

        <p>You may choose the action to be taken when a form is submitted to mailchimp.</p>

        <ul>
          <li>
            <strong>Add new subscribers:</strong> Only add new subscribers that do not exist in Mailchimp.
          </li>
          <li>
            <strong>Add new subscribers, update existing:</strong> Add new subscribers and update contacts that already exist with the information entered when a matching email address is found.
          </li>
          <li>
            <strong>Unsubscribe: </strong> Unsubscribes a contact if found in Mailchimp
          </li>
        </ul>

        <p><strong>Subscriber Status</strong></p>

        <ul>
          <li>
            <strong>Subscribed:</strong> Contacts will automatically be created in Mailchimp.
          </li>
          <li>
            <strong>Pending (double opt-in):</strong> Mailchimp will automatically send a confirmation email where the individual must confirm their subscription before being added as a contact in Mailchimp. Form submissions with "Pending" will not be visible in Mailchimp until their subscription is confirmed.
          </li>
        </ul>

        <h3 style="margin-top: 1.5rem;">Email Type</h3>

        <p>Choose what format the email the subscriber will be sent in, or choose a field to let them choose when submitting the form.</p>

        <h3 style="margin-top: 1.5rem;">VIP Subscriptions & Subscriber IP</h3>

        <p>You may optionally choose to mark subscribers from a form as "VIP". Mailchimp accounts only allow for 5,000 VIP contacts in Mailchimp. Use discretion with this setting as how form data will be handled after the 5,000 VIP limit has been reached is not documented.</p>

        <p>You can collect the IP address of the individual submitting the form and send it to Mailchimp with their subscription data.</p>

        <h3 style="margin-top: 1.5rem;">Contact Organization (Audience Tags)</h3>
        <p>Audience Tags assist with organizing contacts in Mailchimp. Choose one or more tags to further organize information received by Mailchimp from FormBuilder forms.</p>

        <p>Audience Tags can be selected from those that are created/present in Mailchimp and new tags can be created while editing forms. When a new Audience Tag is created here, they will be created in Mailchimp when the first successful subscription is accepted. Tags can be created for one form and then selected for any other FormBuilder form as well.</p>

        <p>NOTE: When tags are removed or renamed in Mailchimp, forms using those tags must be updated here or forms will continue sending subscriptions using the old tags.</p>

        <h3 style="margin-top: 1.5rem;">Mailchimp Fields</h3>
        <p>Select Mailchimp fields to submit data to then configure the form fields to associate with that Mailchimp field in the following section. An email field is required at minimum by Mailchimp and is added automatically.</p>

        <h3 style="margin-top: 1.5rem;">Form Fields</h3>
        <p>Choose a form field to associate with the Mailchimp field that has been added. Notes may be present below fields which may provide additional information that can assist configuring the fields for this form. These may include formatting, expected/allowed values, and maximum length of the value for that field.</p>

        <p>NOTE: It is not possible to process image/file upload fields</p>

        <h3 style="margin-top: 1.5rem;">Changes In Mailchimp</h3>
        <p>Changes made in Mailchimp may affect the configuration and submission for this form. If in Mailchimp a Field, Audience, or Audience Tag is deleted, they will not be available for configuration here. Forms configured to submit to an Audence that has been removed from Mailchimp will no longer send data. The same applies to Fields and Audience Tags. Ensure that your forms stay up to date with Mailchimp for best results.</p>

        <p>Changes to names of Fields, Audiences, or Audience Tags in Mailchimp will not affect submissions of forms configured to use them.</p>


        <h3 style="margin-top: 1.5rem;">Opt-in Checkbox Field</h3>
        <p>Specify a checkbox to let users opt-in to email communications, optional. <strong>If a checkbox field is configured and that field is not present in the form submission or the value is empty, the submission will not be sent to Mailchimp.</strong></p>

        <h3 style="margin-top: 1.5rem;">Test Your Form Configuration</h3>
        <p>Always test your Mailchimp integrations. Ensure that the fields are submitting the data in the proper formatting expected by Mailchimp and that required fields are configured properly.</p>
        EOD;

        $inputfields->add($usageNotes);
// dd($this->data);
        /**
         * Submission configuration
         */
        $submissionConfigurationFieldset = $modules->get('InputfieldFieldset');
        $submissionConfigurationFieldset->label = __('Mailchimp Integration');
        $submissionConfigurationFieldset->themeOffset = 'm';
        $submissionConfigurationFieldset->collapsed = Inputfield::collapsedNever;

         // Mailchimp Audience (list)
        $audienceSelect = $modules->get('InputfieldSelect');
        $audienceSelect->attr('name', 'mailchimp_audience_id');
        $audienceSelect->label = __('Mailchimp Audience');
        $audienceSelect->description = __('Choose the Audience (list) subscribers will be added to');
        $audienceSelect->attr('value', $this->mailchimp_audience_id);
        $audienceSelect->collapsed = Inputfield::collapsedNever;
        $audienceSelect->themeBorder = 'hide';
        $audienceSelect->required = true;
        $audienceSelect->themeInputWidth = 'l';
        // $audienceSelect->columnWidth = 100 / 3;

        if (!$this->mailchimpData->audiences) {
            $audienceSelect->required = false;

            $audienceSelect->notes = __(
                'At least one Audience must be created in Mailchimp to receive submissions'
            );

            $submissionConfigurationFieldset->add($audienceSelect);

            return $inputfields;
        }

        if ($this->mailchimpData->audiences) {
            foreach ($this->mailchimpData->audiences as $audience) {
                $audienceSelect->addOption($audience->id, $audience->name);
            }
        }

        // No audience selected
        if (!$this->mailchimp_audience_id) {
            $audienceSelect->notes = __(
                'Select a Mailchimp audience, save, then return here to configure'
            );

            $submissionConfigurationFieldset->add($audienceSelect);

            $inputfields->add($submissionConfigurationFieldset);

            return $inputfields;
        }

        $submissionConfigurationFieldset->add($audienceSelect);

        /**
         * Subscription action Add or Add/Update
         */
        $subscriptionActionFieldName = "{$this->mailchimp_audience_id}__subscription_action";

        $subscriptionAction = $modules->get('InputfieldSelect');
        $subscriptionAction->label = __('Subscription Action');
        $subscriptionAction->collapsed = Inputfield::collapsedNever;
        $subscriptionAction->attr('name', $subscriptionActionFieldName);
        $subscriptionAction->attr('value', $this->{$subscriptionActionFieldName});
        $subscriptionAction->themeBorder = 'hide';
        $subscriptionAction->required = true;
        $subscriptionAction->columnWidth = 100 / 3;
        $subscriptionAction->themeInputWidth = 'l';
        $subscriptionAction->addOptions([
            'add' => __('Add new subscribers'),
            'add_update' => __('Add new subscribers, update existing'),
            'unsubscribe' => __('Unsubscribe')
        ]);

        $submissionConfigurationFieldset->add($subscriptionAction);

        /**
         * New Subscriber Status
         */
        $subscriberStatusFieldName = "{$this->mailchimp_audience_id}__subscriber_status";

        $subscriberStatus = $modules->get('InputfieldSelect');
        $subscriberStatus->label = __('New Subscriber Status');
        $subscriberStatus->collapsed = Inputfield::collapsedNever;
        $subscriberStatus->attr('name', $subscriberStatusFieldName);
        $subscriberStatus->attr('value', $this->{$subscriberStatusFieldName});
        $subscriberStatus->themeBorder = 'hide';
        $subscriberStatus->required = true;
        $subscriberStatus->requiredIf = "{$subscriptionActionFieldName}!=unsubscribe";
        $subscriberStatus->showIf = "{$subscriptionActionFieldName}!=unsubscribe";
        $subscriberStatus->columnWidth = 100 / 3;
        $subscriberStatus->themeInputWidth = 'l';
        $subscriberStatus->addOptions([
            'subscribed' => __('Subscribed'),
            'pending' => __('Pending (double opt-in)'),
        ]);
        $subscriberStatus->attr("uk-tooltip", "title: Subscribed: Add immediately<br>Pending: Send confirmation email; pos:left; delay: 100");

        $submissionConfigurationFieldset->add($subscriberStatus);

        /**
         * Existing Subscriber Status
         */
        $subscriberUpdateStatusFieldName = "{$this->mailchimp_audience_id}__subscriber_update_status";

        $subscriberUpdateStatus = $modules->get('InputfieldSelect');
        $subscriberUpdateStatus->label = __('Existing Subscriber Status');
        $subscriberUpdateStatus->collapsed = Inputfield::collapsedNever;
        $subscriberUpdateStatus->attr('name', $subscriberUpdateStatusFieldName);
        $subscriberUpdateStatus->attr('value', $this->{$subscriberUpdateStatusFieldName});
        $subscriberUpdateStatus->notes = __("Recommended setting: 'Subscribed'");
        $subscriberUpdateStatus->themeBorder = 'hide';
        $subscriberUpdateStatus->required = true;
        $subscriberUpdateStatus->requiredIf = "{$subscriptionActionFieldName}=add_update";
        $subscriberUpdateStatus->showIf = "{$subscriptionActionFieldName}=add_update";
        $subscriberUpdateStatus->columnWidth = 100 / 3;
        $subscriberUpdateStatus->themeInputWidth = 'l';
        $subscriberUpdateStatus->addOptions([
            'subscribed' => __('Subscribed'),
            'pending' => __('Pending (double opt-in)'),
        ]);
        $subscriberUpdateStatus->attr("uk-tooltip", "title: Subscribed: Add immediately<br>Pending: Send confirmation email; pos:left; delay: 100");

        $submissionConfigurationFieldset->add($subscriberUpdateStatus);

        /**
         * Email Type
         */
        $emailTypeFieldName = "{$this->mailchimp_audience_id}__email_type";

        $emailType = $modules->get('InputfieldSelect');
        $emailType->label = __('Email Type');
        $emailType->collapsed = Inputfield::collapsedNever;
        $emailType->attr('name', $emailTypeFieldName);
        $emailType->attr('value', $this->{$emailTypeFieldName});
        $emailType->themeBorder = 'hide';
        $emailType->required = true;
        $emailType->columnWidth = 50;
        $emailType->themeInputWidth = 'l';
        $emailType->addOptions([
            'html' => __('HTML'),
            'text' => __('Plain text'),
            'use_field' => __('Use form field'),
        ]);

        $submissionConfigurationFieldset->add($emailType);

        /**
         * Email Type Field
         */
        $emailTypeField = $this->createFormFieldSelect(
            "{$this->mailchimp_audience_id}__email_type_field",
            __('Email Type Field'),
            [
                'notes' => __("Must submit a value of either `html` or `text`"),
                'required' => true,
                'requireIf' => "{$emailTypeFieldName}=use_field",
                'showIf' => "{$emailTypeFieldName}=use_field",
                'themeInputWidth' => 'l',
                'columnWidth' => 50,
            ]
        );

        $submissionConfigurationFieldset->add($emailTypeField);


        /**
         * Mark Subscribers as VIP
         */
        $markVipFieldName = "{$this->mailchimp_audience_id}__mark_vip";

        $markVip = $modules->get('InputfieldCheckbox');
        $markVip->label = __('VIP Subscriptions');
        $markVip->label2 = __('Mark subscribers as VIP');
        $markVip->notes = __(
            '5,000 VIP subscriber limit per account. Overage may cause unexpected behavior. [Read more](https://mailchimp.com/help/designate-and-send-to-vip-contacts/).'
        );
        $markVip->collapsed = Inputfield::collapsedNever;
        $markVip->attr('name', $markVipFieldName);
        $markVip->checked($this->{$markVipFieldName});
        $markVip->themeBorder = 'hide';
        $markVip->columnWidth = 50;
        $markVip->showIf = "mailchimp_audience_id!=''";

        $submissionConfigurationFieldset->add($markVip);

        /**
         * Collect Submitters IP Address
         */
        $collectIpFieldName = "{$this->mailchimp_audience_id}__collect_ip";

        $collectIp = $modules->get('InputfieldCheckbox');
        $collectIp->label = __('Subscriber IP Address');
        $collectIp->label2 = __('Capture IP address');
        $collectIp->collapsed = Inputfield::collapsedNever;
        $collectIp->attr('name', $collectIpFieldName);
        $collectIp->checked($this->{$collectIpFieldName});
        $collectIp->themeBorder = 'hide';
        $collectIp->columnWidth = 50;

        $submissionConfigurationFieldset->add($collectIp);

        $inputfields->add($submissionConfigurationFieldset);

        /**
         * Organization
         */
        $contactOrganization = $modules->get('InputfieldFieldset');
        $contactOrganization->label = __('Contact Organization');
        $contactOrganization->collapsed = Inputfield::collapsedNever;
        $contactOrganization->themeOffset = 'm';

        /**
         * Audience Tags
         */
        $tagSelectFieldValues = $this->getAudienceTagsFieldValue();

        $tagsSelect = $modules->get('InputfieldAsmSelect');
        $tagsSelect->attr('name', "{$this->mailchimp_audience_id}__audience_tags");
        $tagsSelect->label = __('Audience Tags');
        $tagsSelect->description = __(
            'Optional Mailchimp tags assigned to contactst that subscribe using this form'
        );
        $tagsSelect->val($tagSelectFieldValues);
        $tagsSelect->themeBorder = 'hide';
        $tagsSelect->collapsed = Inputfield::collapsedNever;
        $tagsSelect->showIf = "mailchimp_audience_id!=''";
        $tagsSelect->sortable = false;
        $tagsSelect->columnWidth = 40;
        $tagsSelect->themeInputWidth = 'l';

        foreach ($this->createAudienceTagOptions() as $tagName) {
            $tagsSelect->addOption($tagName, $tagName);
        }

        $contactOrganization->add($tagsSelect);


        /**
         * New Tag Creation
         * Not scoped, only submits data when saving config, does not persist
         * See getAudienceTagsFieldValue() for implementation
         */
        $createAudienceTags = $modules->get('InputfieldText');
        $createAudienceTags->attr('name', "new_audience_tags");
        // $createAudienceTags->attr('value', "");
        $createAudienceTags->label = __('Add new Audience Tags');
        $createAudienceTags->description = __('Add one or more new Audience Tags separated by commas');
        $createAudienceTags->placeholder = __('Tag 1, Tag 2, Tag 3');
        $createAudienceTags->collapsed = Inputfield::collapsedNever;
        $createAudienceTags->themeBorder = 'hide';
        $createAudienceTags->columnWidth = 60;
        $createAudienceTags->notes = __(
            'New tags are created here then added in Mailchimp when the first subscription is successfully submitted'
        );

        $contactOrganization->add($createAudienceTags);

        $inputfields->add($contactOrganization);


        /**
         * Mailchimp Submitted Fields
         */
        $includedMailchimpFields = $modules->get('InputfieldFieldset');
        $includedMailchimpFields->label = __('Mailchimp Fields');
        $includedMailchimpFields->collapsed = Inputfield::collapsedNever;
        $includedMailchimpFields->description = __(
            'Select Mailchimp fields to collect data for, then choose which form fields should be associated below. The Mailchimp merge tag appears next to each field name.'
        );
        $includedMailchimpFields->themeOffset = 'm';
        $includedMailchimpFields->notes = __(
            'An email address field is required by Mailchimp and has been selected automatically. Fields that have been configured to be required in Mailchimp are required here'
        );

        foreach ($this->mailchimpData->mergeFields as $mergeField) {
            $includedMailchimpFields->add(
                $this->createIncludeFieldConfiguration($mergeField)
            );
        }

        // Add Interest Groups as a field
        foreach ($this->mailchimpData->interestCategories as $interestCategory) {
            $includedMailchimpFields->add(
                $this->createIncludeInterestCategoryConfiguration($interestCategory)
            );
        }

        $inputfields->add($includedMailchimpFields);

        /**
         * Mailchimp Field Associations
         */
        $fieldAssociationFieldset = $modules->get('InputfieldFieldset');
        $fieldAssociationFieldset->label = __('Form Fields');
        $fieldAssociationFieldset->collapsed = Inputfield::collapsedNever;
        $fieldAssociationFieldset->description = __(
            'Choose a form field to associate with each Mailchimp field. Information provided by Mailchimp may be noted below fields.'
        );
        $fieldAssociationFieldset->themeOffset = 'm';

        $fieldAssociationFieldset->add(
            $this->createEmailMergeFieldConfiguration()
        );

        $mailchimpMergeFields = array_filter(
            $this->mailchimpData->mergeFields,
            fn ($mergeField) => $mergeField->type !== 'address'
        );

        // Create configurations for each Mailchimp field
        foreach ($mailchimpMergeFields as $mergeField) {
            $fieldAssociationFieldset->add(
                $this->createMergeFieldConfiguration($mergeField)
            );
        }

        // Add Interest Groups as a field
        foreach ($this->mailchimpData->interestCategories as $interestCategory) {
            $fieldAssociationFieldset->add(
                $this->createInterestCategoryConfiguration($interestCategory)
            );
        }

        /**
         * Mailchimp Address Associations (Added under field associations)
         */
        $addressAssociationFieldset = $modules->get('InputfieldFieldset');
        $addressAssociationFieldset->label = __('Mailchimp Addresses');
        $addressAssociationFieldset->description = __(
            'To include an address, associate form fields with each address component. Leave blank to exclude, not all fields may be required'
        );
        $addressAssociationFieldset->collapsed = Inputfield::collapsedNever;

        $addressMergeFields = array_filter(
            $this->mailchimpData->mergeFields,
            fn ($mergeField) => $mergeField->type === 'address'
        );

        foreach ($addressMergeFields as $mergeField) {
            $fieldAssociationFieldset->add(
                $this->createAddressMergeFieldsConfiguration($mergeField)
            );
        }

        $inputfields->add($fieldAssociationFieldset);


        /**
         * Opt-In Checkbox
         */

        $optInCheckboxFieldName = "{$this->mailchimp_audience_id}__opt_in_checkbox_field";

        $optInCheckboxSelect = $modules->get('InputfieldSelect');
        $optInCheckboxSelect->attr('name', $optInCheckboxFieldName);
        $optInCheckboxSelect->label = __('Opt-In Checkbox');
        $optInCheckboxSelect->description = __('Optional checkbox that must be checked to subscribe');
        $optInCheckboxSelect->attr('value', $this->{$optInCheckboxFieldName});
        $optInCheckboxSelect->collapsed = Inputfield::collapsedNever;
        // $optInCheckboxSelect->themeBorder = 'hide';
        $optInCheckboxSelect->showIf = "mailchimp_audience_id!=''";
        $optInCheckboxSelect->themeInputWidth = 'l';
        // $optInCheckboxSelect->columnWidth = 100 / 3;

        $checkboxFields = array_filter(
            $this->fbForm->children,
            fn ($field) => $field->type === 'Checkbox',
        );

        if ($checkboxFields) {
            $optInCheckboxSelect->notes = __(
                "Checked value must be one of: `true`, `'true'`, `'1'`, `1`, `'on'`, or `'yes'`"
            );
        }

        if (!$checkboxFields) {
            $optInCheckboxSelect->notes = __('Add one or more checkbox fields to select');
            $optInCheckboxSelect->attr('disabled', true);
        }

        $optInCheckboxSelect = array_reduce(
            $checkboxFields,
            fn ($inputfield, $field) => $inputfield->addOption($field->name, $field->label),
            $optInCheckboxSelect
        );

        $inputfields->add($optInCheckboxSelect);

        return $inputfields;
    }

    /**
     * Creates array of arrays containing Audience Tag options, combines dynamic sources with
     * individual field value
     * @return array<array>
     */
    private function createAudienceTagOptions(): array
    {
        $options = array_unique([
            ...array_map(fn ($tag) => $tag->name, $this->mailchimpData->audienceTags),
            ...$this->wire('modules')->get($this)->data['local_audience_tags'],
            ...$this->getAudienceTagsFieldValue(),
        ]);

        usort($options, 'strnatcasecmp');

        return $options;
    }

    /**
     * Looks for new Audience Tags submitted in config.
     * Adds new tags to inputfield and config field, saves
     * Returns all locally configured tags that may or may not yet exist in Mailchimp
     * Tags are created in Mailchimp on first subscription submission containing them
     */
    private function getAudienceTagsFieldValue(): array
    {
        // Add new tags to this
        // Tags with * will be created in Mailchimp on first successful subcription

        // Config field name
        $audienceTagConfigField = "{$this->mailchimp_audience_id}__audience_tags";

        $configFieldValue = $this->{$audienceTagConfigField} ?? [];

        // Audience Tags created on config page save
        $newTags = $this->data['new_audience_tags'] ?? [];

        // Return current value if no new tags have been created
        if (!$newTags) {
            return $configFieldValue;
        }

        $newTags = explode(',', $newTags);
        $newTags = array_map('trim', $newTags);

        $configuredAudienceTags = array_unique([...$newTags, ...$configFieldValue]);

        // Add and save tags for this field to immediately persist data
        $this->saveConfigValue($audienceTagConfigField, $configuredAudienceTags);

        $localTags = array_unique([...$this->data['local_audience_tags'], ...$newTags]);

        $this->wire('modules')
             ->get('FormBuilderProcessorMailchimpConfig')
             ->saveModuleConfig(local_audience_tags: $localTags);

        return $configuredAudienceTags;
    }

    /**
     * Create sets of fields to configure addresses
     */
    private function createAddressMergeFieldsConfiguration(stdClass $mergeField): ?InputfieldFieldset
    {
        if ($mergeField->type !== 'address') {
            return null;
        }

        $audienceId = $this->mailchimp_audience_id;
        $includeFieldConfig = "{$audienceId}__submit_to_mailchimp__{$mergeField->merge_id}";

        // showIf is not working for this fieldset unless manually prefixed with the module name
        $moduleClassName = $this->wire()->modules->get($this)->className;
        $showFieldIf = "{$moduleClassName}_{$includeFieldConfig}=1";

        $notes = $this->createInputfieldNotesFromMergeFieldOptions($mergeField->options);

        if ($mergeField->required) {
            $notes = implode('. ', [
               '**' .  __('This is set as required in Mailchimp and must be completed to subscribe. Consider making this form field required.') . '**',
                $notes,
            ]);
        }

        $fieldset = $this->wire()->modules->InputfieldFieldset;
        $fieldset->label = "{$mergeField->name}";
        $fieldset->description = __('Address fields are limited to 45 characters. Mailchimp merge tag: ') . $mergeField->tag;
        $fieldset->collapsed = Inputfield::collapsedNever;
        $fieldset->themeBorder = 'hide';
        $fieldset->notes = $notes;
        $fieldset->themeColor = 'none';
        $fieldset->showIf = $showFieldIf;

        // [subfield name, subfield label, required]
        $subfields = [
            ['addr1', __('Street Address'), true],
            ['addr2', __('Address Line 2'), false],
            ['city', __('City'), true],
            ['state', __('State/Prov/Region'), true],
            ['zip', __('Postal/Zip'), true],
            ['country', __('Country'), false],
        ];

        foreach ($subfields as $subfield) {
            [$name, $label, $required] = $subfield;

            $fieldName = "{$audienceId}__mailchimp_address_mergefield__{$mergeField->merge_id}-{$name}";

            $mcRequired = '**' . __('Required by Mailchimp') . '**';

            $configField = $this->createFormFieldSelect($fieldName, $label, [
                'columnWidth' => 100 / 3,
                'notes' => $required ? $mcRequired : null,
                'required' => $required,
                'requireIf' => $showFieldIf,
            ]);

            $fieldset->add($configField);
        }

        return $fieldset;
    }

    /**
     * Creates specific configuration field for email address required by Mailchimp
     */
    private function createEmailMergeFieldConfiguration(): InputfieldSelect
    {
        $fieldName = "{$this->mailchimp_audience_id}__email_address_field";

        return $this->createFormFieldSelect($fieldName, __('Email Address'), [
            'required' => true,
            'notes' => '**' . __('Required by Mailchimp for all subscribers') . '**',
            'themeInputWidth' => 'l',
            'description' => __('Mailchimp merge tag:') . ' EMAIL',
        ]);
    }

    /**
     * Create a checkbox to determine if a Mailchimp field should be included
     */
    private function createIncludeFieldConfiguration(stdClass $mergeField): InputfieldCheckbox
    {
        $fieldName = "{$this->mailchimp_audience_id}__submit_to_mailchimp__{$mergeField->merge_id}";
        $fieldValue = $this->{$fieldName};
        $required = false;

        // Automatically include all required fields
        if ($mergeField->required) {
            $fieldValue = 1;
            $required = true;
        }

        $mergeField->tag === 'BIRTHDAY' && $required = true;
        $checkbox = $this->wire('modules')->get('InputfieldCheckbox');
        $checkbox->label = "{$mergeField->name} - {$mergeField->tag}";
        $checkbox->attr('name', $fieldName);
        $checkbox->checked($fieldValue);
        $checkbox->columnWidth = 25;
        $checkbox->collapsed = Inputfield::collapsedNever;
        $checkbox->themeBorder = 'hide';
        $checkbox->required = $required;

        return $checkbox;
    }

    /**
     * Create a checkbox to determine if a Mailchimp interest category should be included
     */
    private function createIncludeInterestCategoryConfiguration(
        stdClass $interestCategory
    ): InputfieldCheckbox {
        $interestCategory = $interestCategory->category;
        $fieldName = "{$this->mailchimp_audience_id}__submit_to_mailchimp__{$interestCategory->id}";

        $checkbox = $this->wire('modules')->get('InputfieldCheckbox');
        $checkbox->label = "{$interestCategory->title} - Interest list";
        $checkbox->attr('name', $fieldName);
        $checkbox->checked($this->{$fieldName});
        $checkbox->columnWidth = 25;
        $checkbox->collapsed = Inputfield::collapsedNever;
        $checkbox->themeBorder = 'hide';

        return $checkbox;
    }

    /**
     * Create a Mailchimp/form field association config
     */
    private function createMergeFieldConfiguration(stdClass $mergeField): ?InputfieldSelect
    {
        $includedFieldName = "{$this->mailchimp_audience_id}__submit_to_mailchimp__{$mergeField->merge_id}";
        $fieldName = "{$this->mailchimp_audience_id}__mailchimp_mergefield__{$mergeField->merge_id}";

        $notes = $this->createInputfieldNotesFromMergeFieldOptions($mergeField->options);

        $visibility = [];

        if ($mergeField->required) {
            $visibility = [
                'showIf' => "{$includedFieldName}=1",
                'requireIf' => "{$includedFieldName}=1",
            ];

            $notes = implode('. ', [
               '**' .  __('This is set as required in Mailchimp and must be completed to subscribe. Consider making this form field required.') . '**',
                $notes,
            ]);
        }

        return $this->createFormFieldSelect($fieldName, $mergeField->name, [
            'required' => $mergeField->required,
            'description' => __('Mailchimp merge tag:') . " {$mergeField->tag}",
            'notes' => $notes,
            'showIf' => "{$includedFieldName}=1",
            'requireIf' => "{$includedFieldName}=1",
            'required' => true,
            'themeInputWidth' => 'l',
            ...$visibility,
        ]);
    }

    /**
     * Create a Mailchimp/form field association config for the given form field
     */
    private function createInterestCategoryConfiguration(stdClass $interestCategory): ?InputfieldSelect
    {
        $category = $interestCategory->category;
        $interests = $interestCategory->interests->interests;

        // Create notes
        $interests = array_map(fn ($interest) => $interest->name, $interests);

        $notes = implode('. ', [
            "Type: {$category->type}",
            'Values: ' . implode(', ', $interests)
        ]);

        $fieldName = "{$this->mailchimp_audience_id}__interest_category__{$category->id}";
        $includedFieldName = "{$this->mailchimp_audience_id}__submit_to_mailchimp__{$category->id}";

        return $this->createFormFieldSelect($fieldName, $category->title, [
            'description' => __('Interest list'),
            'notes' => $notes,
            'themeInputWidth' => 'l',
            'showIf' => "{$includedFieldName}=1",
            'requireIf' => "{$includedFieldName}=1",
            'required' => true,
        ]);
    }

    // private function

    /**
     * Creates a select inputfield with options for each form field
     */
    private function createFormFieldSelect(
        string $name,
        string $label,
        array $inputfieldConfigs = []
    ): InputfieldSelect {
        $fieldSelect = $this->wire()->modules->InputfieldSelect;
        $fieldSelect->attr('name', $name);
        $fieldSelect->label = $label;
        $fieldSelect->attr('value', $this->$name);
        $fieldSelect->collapsed = Inputfield::collapsedNever;
        $fieldSelect->themeBorder = 'hide';

        foreach ($inputfieldConfigs as $configName => $configValue) {
            $fieldSelect->$configName = $configValue;
        }

        // Remove unsupported fields
        $fields = array_filter(
            $this->fbForm->children,
            fn ($field) => $field->type !== 'FormBuilderFile'
        );

        $options = array_reduce(
            $fields,
            fn ($options, $field) => $options = [$field->name => $field->label, ...$options],
            []
        );

        $options = array_reverse($options);

        $fieldSelect->addOptions($options);

        return $fieldSelect;
    }

    /**
     * Takes contents of 'options' property in a merge field API object and creates notes that are
     * shown below a field association
     */
    private function createInputfieldNotesFromMergeFieldOptions(stdClass $options): ?string
    {
        $options = (array) $options;

        array_walk($options, function(&$value, $name) {
            $name = preg_replace('/[-_]/', ' ', $name);
            $name = ucfirst($name);

            is_array($value) && $value = implode(', ', $value);

            $value = "{$name}: $value";
        });

        return implode('. ', array_values($options));
    }

    /**
     * Data Retrieval
     */

    /**
     * Loads config values for the currently configured Mailchimp audience
     * Configs are keyed by complex namespaced strings, handles parsing and loading into a more
     * easily accessible object for form submission processing
     */
    private function getFormProcessingConfigs(): stdClass
    {
        $audienceId = $this->data['mailchimp_audience_id'];
        $audienceConfigs = $this->getAudienceConfigs();
        $this->mailchimpData = $this->mailchimpData ?? $this->getMailchimpApiData();

        return (object) [
            'audienceId' => $audienceId,
            'audienceName' => $this->getMailchimpAudienceValue($audienceId, 'name'),
            'audienceTags' => $audienceConfigs['audience_tags'],
            'mergeFields' => $this->getMergeFieldTags(),
            'addressMergeFields' => $this->getAddressMergeFieldTags(),
            'action' => $audienceConfigs['subscription_action'],
            'collectIp' => (bool) $audienceConfigs['collect_ip'],
            'emailAddressField' => $audienceConfigs['email_address_field'],
            'optInCheckboxField' => $audienceConfigs['opt_in_checkbox_field'],
            'dateFormats' => $this->getDateConversionFormats(),
            'interestCategories' => $this->getInterestCategories(),
            'emailType' => (object) [
                'value' => $audienceConfigs['email_type'],
                'field' => $audienceConfigs['email_type_field'] ?? null,
            ],
            'status' => (object) [
                'new' => $audienceConfigs['subscriber_status'],
                'update' => $audienceConfigs['subscriber_update_status'],
            ],
        ];
    }

    /**
     * Creates array of Mailchimp date formats and corresponding PHP date formatting values
     */
    private function getDateConversionFormats(): array
    {
        $mailchimpData = $this->mailchimpData ?? $this->getMailchimpApiData();

        return array_reduce($mailchimpData->mergeFields, function($formats, $mergeField) {
            if (!property_exists($mergeField->options, 'date_format')) {
                return $formats;
            }

            $formats[$mergeField->tag] = [
                'MM/DD' => 'm/d',
                'DD/MM' => 'd/m',
                'MM/DD/YYYY' => 'm/d/y',
                'DD/MM/YYYY' => 'd/m/y',
            ][$mergeField->options->date_format];

            return $formats;
        }, []);
    }

    /**
     * Parses audience-specific configs and returns merge field/form field data for fields that are
     * configured to be submitted
     *
     * @param  array $audienceConfigs  Configs filtered for selected audience
     */
    private function getMergeFieldTags(): array
    {
        $submittableIds = array_flip($this->getSubmittableFieldIds());
        $mergeFields = $this->getAudienceConfigs('mailchimp_mergefield__', true);
        $submittedFields = array_intersect_key($mergeFields, $submittableIds);

        // Create an array of arrays with mergeTag and formField, null out non merge-field items
        array_walk($submittedFields, fn (&$value, $mergeFieldId) =>  $value = [
            'mergeTag' => $this->getMergeFieldById($mergeFieldId)->tag,
            'formField' => $value
        ]);

        return array_combine(
            array_column($submittedFields, 'mergeTag'),
            array_column($submittedFields, 'formField')
        );
    }

    /**
     * Parses audience-specific configs and returns merge field/form field data for fields that are
     * configured to be submitted
     *
     * @param  array $audienceConfigs  Configs filtered for selected audience
     */
    private function getAddressMergeFieldTags(): array
    {
        $submittableIds = $this->getSubmittableFieldIds();
        $mergeFields = $this->getAudienceConfigs('mailchimp_address_mergefield__', true);

        // Convert values to arrays of data for each subfield
        array_walk($mergeFields, function(&$value, $key) use ($submittableIds) {
            [$mergeFieldId, $subfield] = explode('-', $key);

            if (!in_array($mergeFieldId, $submittableIds)) {
                return $value = null;
            }

            $value = [
                'formField' => $value,
                'tag' => $this->getMergeFieldById($mergeFieldId)->tag,
                'subfield' => $subfield,
            ];
        });

        // Convert subfield arrays to values keyed by merge tag
        return array_reduce($mergeFields, function($fields, $subfield) {
            if (!$subfield) {
                return $fields;
            }

            ['tag' => $tag, 'formField' => $formField, 'subfield' => $subfield] = $subfield;

            $fields[$tag] ??= [];
            $fields[$tag][$subfield] = $formField;

            return $fields;
        }, []);
    }

    private function getInterestCategories(): array
    {
        $submittableIds = array_flip($this->getSubmittableFieldIds());
        $interestCategories = $this->getAudienceConfigs('interest_category__', true);
        $submittedInterestCategories = array_intersect_key($interestCategories, $submittableIds);

        // Form field names as key, category ID as value
        $submittedInterestCategories = array_flip($submittedInterestCategories);

        // Replace category IDs with interestCategory object
        array_walk($submittedInterestCategories, function(&$categoryId) {
            $interestCategory = $this->getinterestCategoryById($categoryId);

            $categoryId = array_reduce(
                $interestCategory->interests->interests,
                fn ($sets, $interest) => $sets = [...$sets, $interest->id => $interest->name],
                []
            );
        });

        return $submittedInterestCategories;
    }

    private function getinterestCategoryById(string $id): ?stdClass
    {
        $mailchimpData = $this->mailchimpData ?? $this->getMailchimpApiData();
        $interestCategories = $mailchimpData->interestCategories;

        return array_reduce($interestCategories, function($match, $interestCategory) use ($id) {
            return $match = $interestCategory->category->id === $id ? $interestCategory : $match;
        });
    }

    /**
     * Retrieves a Mailchimp merge field from persisted Mailchimp API data
     * @param  int    $mergeId ID of merge field
     */
    private function getMergeFieldById(string|int $mergeId): ?stdClass
    {
        $mailchimpData = $this->mailchimpData ?? $this->getMailchimpApiData();
        $id = (int) $mergeId;

        return array_reduce(
            $mailchimpData->mergeFields,
            fn ($match, $field) => $match = $field->merge_id === $id ? $field : $match
        );
    }

    /**
     * Retrieves IDs of fields configured for submission from persisted Mailchimp API data
     */
    private function getSubmittableFieldIds(): array
    {
        $configs = $this->getAudienceConfigs('submit_to_mailchimp__', true);

        return array_keys($configs);
    }

    /**
     * Retrieves a set of action configs in the currently selected Mailchimp Audience
     * Returns the config data with prefix removed from each key
     * Optional prefix can be passed to get specific groups of configs
     *
     * @param  string  $configPrefix     Prefix string to locate configs and remove from key
     * @param  bool    $removeEmpty Remove configs that have empty values
     */
    private function getAudienceConfigs(string $configPrefix = '', bool $removeEmpty = false): array
    {
        $configPrefix = "{$this->mailchimp_audience_id}__{$configPrefix}";

        $audienceConfigs = array_filter(
            $this->data,
            fn ($configKey) => str_starts_with($configKey, $configPrefix),
            ARRAY_FILTER_USE_KEY
        );

        // Remove prefixes from config keys
        $configKeys = array_map(
            fn ($configKey) => str_replace($configPrefix, '', $configKey),
            array_keys($audienceConfigs)
        );

        $configs = array_combine(
            $configKeys,
            array_values($audienceConfigs)
        );

        return $removeEmpty ? array_filter($configs) : $configs;
    }

    /**
     * Gets a value from the audience object of a given ID
     * @param  string $audienceId Mailchimp Audience ID
     * @param  string $key        Object Key
     */
    private function getMailchimpAudienceValue(string $audienceId, string $key): mixed
    {
        $audiences = ($this->mailchimpData ?? $this->getMailchimpApiData())->audiences;

        return array_reduce($audiences, function($match, $audience) use ($audienceId, $key) {
            if ($match !== null) {
                return $match;
            }

            return $match = $audience->id === $audienceId ? $audience->$key : $match;
        }, null);
    }

    /**
     * Retrieves Mailchimp data from API, persists in processor config
     * @param bool $useLocal Load from local configuration, false to pull fresh data from API
     * @throws Exception
     */
    private function getMailchimpApiData(bool $useLocal = true): stdClass
    {
        $localData = $this->data['mailchimp_data'] ?? [];

        if ($useLocal && $localData) {
            return json_decode($localData);
        }

        // $mailchimpData = $this->mailchimpData ?? $this->getMailchimpApiData();
        // $lastRetrieved = new DateTimeImmutable($mailchimpData->lastRetrieved->date);
        // $now = new DateTimeImmutable();

        // Use stored data as cache in 3 minute intervals, helps speed up development
        // if ($now->diff($lastRetrieved)->i < 15) {
        //     return $mailchimpData;
        // }

        $audiences = [];
        $mergeFields = [];
        $audienceTags = [];
        $interestCategories = [];

        try {
            $mailchimpClient = MailchimpClient::init($this->mailchimp_api_key);

            $audiences = $mailchimpClient->getAudiences()['lists'];

            if ($this->mailchimp_audience_id) {
                $mergeFields = $mailchimpClient->getMergeFields($this->mailchimp_audience_id)['merge_fields'];
                $audienceTags = $mailchimpClient->getTags($this->mailchimp_audience_id);
                $interestCategories = $mailchimpClient->getInterestCategories($this->mailchimp_audience_id);
            }

            $lastResponse = $mailchimpClient->mailchimp->getLastResponse();

            if ($lastResponse['headers']['http_code'] !== 200) {
                $errorBody = json_decode($lastResponse['body']);

                $this->logMailchimpApiError(
                    $errorBody->title,
                    "Status: {$errorBody->status}",
                    "Detail: {$errorBody->detail}",
                    "Instance: {$errorBody->instance}",
                );

                throw new Exception("Mailchimp error: {$errorBody->detail}");
            }
        } catch (Exception $e) {
            $message = $e->getMessage();

            $this->logMailchimpApiError($message);

            throw new Exception("Mailchimp error: {$message}");
        }

        // Sort Mailchimp data for better presentation in config UI
        usort($audiences, fn ($a, $b) => strcmp($a['name'], $b['name']));

        usort($mergeFields, fn ($a, $b) => $a['merge_id'] <=> $b['merge_id']);

        usort($audienceTags, fn ($a, $b) => strcmp($a['name'], $b['name']));

        usort($interestCategories, fn ($a, $b) => strcmp($a['category']['title'], $b['category']['title']));

        $mailchimpData = (object) [
            'audiences' => $audiences,
            'mergeFields' => $mergeFields,
            'audienceTags' => $audienceTags,
            'interestCategories' => $interestCategories,
            'lastRetrieved' => new DateTimeImmutable(),
        ];

        $mailchimpData = json_encode($mailchimpData);

        $this->saveConfigValue('mailchimp_data', $mailchimpData);

        // Decode to keep nested associative arrays as object, consistent with stored data
        $mailchimpData = json_decode($mailchimpData);

        $this->executeDataMaintenance($mailchimpData);

        return $mailchimpData;
    }

    /**
     * Logging
     */

    /**
     * Log errors received from Mailchimp when sending data
     */
    private function logMailchimpSubmissionFailure(...$messages): void
    {
        $message = implode(', ', $messages);

        wire('log')->save(self::LOG_NAME, "Mailchimp Submission Failed: {$message}");
    }

    /**
     * Log non-critical issues that do not prevent Mailchimp subscription submissions
     */
    private function logFormSubmissionError(...$messages): void
    {
        $message = implode(', ', $messages);

        wire('log')->save(self::LOG_NAME, "Form Submission Error: {$message}");
    }

    /**
     * Log errors causing a form submission to not be sent to Mailchimp
     */
    private function logRejectedFormSubmission(...$messages): void
    {
        $message = implode(', ', $messages);

        wire('log')->save(self::LOG_NAME, "Rejected Form Submission: {$message}");
    }

    /**
     * Log errors that occurred during API data retrieval
     */
    private function logMailchimpApiError(...$messages): void
    {
        $message = implode(', ', $messages);

        wire('log')->save(self::LOG_NAME, "Mailchimp API Error: {$message}");
    }

    /**
     * Dumps detailed data formatted in JSON to log for most events during module activity
     * @param  string $event Name of event logged
     * @param  string $body  Data for JSON format
     */
    private function debugEvent(string $event, array|object|string $body): void
    {
        if (!$this->wire()->config->debug) {
            return;
        }

        // Ensure that JSON isn't double encoded
        if (is_string($body)) {
            $decodeBody = json_decode($body);

            if (json_last_error() === JSON_ERROR_NONE) {
                $body = $decodeBody;
            }
        }

        $logEntry = json_encode([
            'event' => $event,
            'data' => $body,
        ]);

        wire('log')->save(self::LOG_NAME, $logEntry);
    }

    /**
     * Performs data mantenance to sync local data components with given Mailchimp API data. Tasks:
     * - Reconciles local Audience Tag store with Audience Tags that exist in Mailchimp
     */
    public function executeDataMaintenance(?stdClass $mailchimpData = null): void
    {
        // Ensure a fresh copy of mailchimp data
        $mailchimpData ??= $this->getMailchimpApiData(useLocal: false);

        $modules = $this->wire('modules');

        $formBuilder = $modules->get('FormBuilder');
        $mailchimpProcessor = $modules->get($this);
        $mailchimpProcessorConfig = $modules->get('FormBuilderProcessorMailchimpConfig');

        $dataMaintenance = DataMaintenance::init(
            $mailchimpData,
            $formBuilder,
            $mailchimpProcessor,
            $mailchimpProcessorConfig
        );

        $dataMaintenance->executeAll();
    }
}