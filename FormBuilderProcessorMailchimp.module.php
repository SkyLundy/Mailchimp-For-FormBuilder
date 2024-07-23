<?php

declare(strict_types=1);

namespace ProcessWire;

wire('classLoader')->addNamespace('FormBuilderProcessorMailchimp\App', __DIR__ . '/app');

use DateTimeImmutable;
use Exception;
use FormBuilderProcessorMailchimp\App\MailchimpClient;
use RuntimeException;
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

    /**
     * Audience scoped configs for processing form submissions, initialized in processReady()
     */
    private ?stdClass $formProcessingConfigs = null;

    /**
     * Process submitted form
     */
    public function processReady(): void
    {
        if (!$this->mailchimp_audience_id || !$this->mailchimp_api_ready) {
            return;
        }

        // Load config persisted Mailchimp API data
        $this->mailchimpData = $this->loadMailchimpDataFromConfig();

        // Load parsed processor configs for processing form submission
        $processingConfig = $this->getFormProcessingConfigs();

        // Form submission Data
        $postData = $this->input->post->getArray();

        // Check if submission qualifies for Mailchimp processing
        if (!$this->isMailchimpSubmittable($postData, $processingConfig)) {
            return;
        }

        $mailchimpData = $this->parseFormSubmission($postData, $processingConfig);

        if (!$mailchimpData) {
            return;
        }
dd($mailchimpData);
        $mailchimpClient = $this->___subscribe($mailchimpData);

        dd($mailchimpClient->mailchimp->getLastResponse()['body']);

        // Log JSON response body
        wire('log')->save(
            self::LOG_NAME,
            $mailchimpClient->mailchimp->getLastResponse()['body']
        );
    }

    /**
     * Submit subscription to Mailchimp
     * @param array<string, mixed> $subscriberData Processed POST data for submission
     * @param string               $audienceId     Optional audience ID override for hooking
     */
    public function ___subscribe(array $subscriberData, ?string $audienceId = null): MailchimpClient
    {
        $audienceId ??= $this->mailchimp_audience_id;
        $mailchimpClient = MailchimpClient::init($this->mailchimp_api_key);
        $subscriptionAction = $this->fieldConfig()->subscriptionAction['value'];

        match ($subscriptionAction) {
            'add_update' => $mailchimpClient->subscribeOrUpdate($subscriberData, $audienceId),
            'add' => $mailchimpClient->subscribe($subscriberData, $audienceId),
        };

        return $mailchimpClient;
    }

    /**
     * Checks suitibility for submission
     * - If there is a checkbox designated as an opt-in and whether this submission qualifies
     * - There are configured form/Mailchimp field pairs
     */
    private function isMailchimpSubmittable(array $formData, stdClass $processingConfig): bool
    {
        $optInCheckbox = $processingConfig->optInCheckboxField;
        $emailField = $processingConfig->emailAddressField;

        if (!$optInCheckbox) {
            return true;
        }

        // Opt-in checkbox is configured but value isn't present in the POST data
        if (!array_key_exists($optInCheckbox, $formData) && $optInCheckbox) {
            return false;
        }

        return filter_var($formData[$optInCheckbox], FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Parses POST data and prepares Mailchimp payload according to configuration
     * @param array<string, mixed> $formData          Form submission data
     * @param stdClass             $processingConfig Submissions configuration object
     */
    private function parseFormSubmission(array $formData, stdClass $processingConfig): array
    {
        dump($processingConfig);

        $mergeFields = array_filter([
            ...$this->getSubmissionMergeFields($formData, $processingConfig),
            ...$this->getSubmissionAddressMergeFields($formData, $processingConfig),
        ]);

        $subStatus = $processingConfig->status;

        $subscriberStatus = match ($processingConfig->action) {
            'unsubscribe' => ['status' => 'unsubscribed'],
            'add' => ['status' => $subStatus->new],
            default => ['status_if_new' => $subStatus->new, 'status' => $subStatus->update],
        };

        $out = array_filter([
            'email_address' => $formData[$processingConfig->emailAddressField],
            'merge_fields' => $mergeFields,
            // 'interests' => $this->getSubmissionInterestCategories($formData),
            'tags' => $processingConfig->audienceTags,
            'email_type' => $this->getSubmissionEmailType($formData, $processingConfig),
            'ip_signup' => $processingConfig->collectIp ? wire('session')->getIP() : null,
            ...$subscriberStatus,
        ]);

        dd($out);

        return $out;
    }

    /**
     * Parse configs and optionally submission data for email type, falls back to 'html'
     * @param  array    $formData         Submitted form data
     * @param  stdClass $processingConfig Submission processing config object
     */
    private function getSubmissionEmailType(array $formData, stdClass $processingConfig): string
    {
        $type = $processingConfig->emailType->value;

        $emailType = match ($type) {
            'use_field' => $formData[$processingConfig->emailType->field] ?? null,
            default => $type,
        };

        return in_array($emailType, ['html', 'text']) ? $emailType : 'html';
    }

    /**
     * Parses POST data for configured fields/data to submit to mailchimp
     * @param array<string, mixed> $formData Form submission data
     */
    private function getSubmissionMergeFields(array $formData, stdClass $processingConfig): array
    {
        $mergeFields = $processingConfig->mergeFields;

        // Convert merge tag config value to form value, [MERGETAG => 'submitted value']
        array_walk($mergeFields, function(&$formField, $mergeTag) use ($formData, $processingConfig) {
            $formField = $this->getSubmissionMergeFieldValue(
                $mergeTag,
                $formField,
                $formData,
                $processingConfig
            );
        });

        return array_filter($mergeFields);
    }

    /**
     * Gets the Mailchimp appropriate value for a given submitted form value
     * @param  string $mergeTag       Mailchimp field merge tag
     * @param  string $formField  FormBuilder field name
     * @param  array  $formData       Form submission POST data
     * @return mixed                  Value in format expected by Mailchimp
     */
    private function getSubmissionMergeFieldValue(
        string $mergeTag,
        string $formField,
        array $formData,
        stdClass $processingConfig
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
                $format = $processingConfig->dateFormats[$mergeTag] ?? false;

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
     */
    private function getSubmissionAddressMergeFields(
        array $formData,
        stdClass $processingConfig
    ): array {
        $addressMergeFields = $processingConfig->addressMergeFields;

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
     * @param  array  $formData FormBuilder form submitted data
     * @return array<string>
     */
    private function getSubmissionInterestCategories(array $formData): array
    {
        $interestCategories = $this->getConfiguredInterestCategories();

        if (!$interestCategories) {
            return [];
        }

        $interestCategoryFields = array_keys($interestCategories);

        // Get submitted interests if the key is present and values have been submitted
        $submittedInterests = array_filter($formData, function($value, $key) use ($interestCategoryFields) {
            return ;
        }, ARRAY_FILTER_USE_BOTH);

        $getMailchimpValues = function(string $formField, array $fieldValue) use ($interestCategories) {

        };

        array_walk($submittedInterests, function($interest, $formField) use ($interestCategoryFields) {
            !in_array($key, $interestCategoryFields) || empty($value);
        });


        array_walk($interestCategories, function(&$formField) use ($formData) {
            if (!array_key_exists($formField, $formData)) {
                return null;
            }

            $formField = (array) $formData[$formField];
        });

        return array_filter($interestCategories);
    }

    /**
     * Generates config names, prefixes, and values
     * @param string|int|null $configId1 Parameter for first string interpolation value, optional
     * @param string|int|null $configId2 Parameter for second string interpolation value, optional
     */
    private function fieldConfig(
        string|int|null $configId1 = null,
        string|int|null $configId2 = null,
    ): stdClass {
        $audienceId = $this->mailchimp_audience_id;

        return (object) [
            'interestCategory' => [
                'name' => "{$audienceId}__interest_category__{$configId1}",
                'prefix' => "{$audienceId}__interest_category__",
                'value' => $this->{"{$audienceId}_interest_category__{$configId1}"},
            ],
            'mergeField' => [
                'name' => "{$audienceId}__mailchimp_mergefield__{$configId1}",
                'prefix' => "{$audienceId}__mailchimp_mergefield__",
                'value' => $this->{"{$audienceId}__mailchimp_mergefield__{$configId1}"},
            ],
            'addressMergeField' => [
                'name' => "{$this->mailchimp_audience_id}__mailchimp_address_mergefield__{$configId1}-{$configId2}",
                'prefix' => "{$this->mailchimp_audience_id}__mailchimp_address_mergefield__",
                'value' => $this->{"{$this->mailchimp_audience_id}__mailchimp_address_mergefield__{$configId1}-{$configId2}"},
            ],
            'submitToMailchimp' => [
                'name' => "{$audienceId}__submit_to_mailchimp__{$configId1}",
                'prefix' => "{$audienceId}__submit_to_mailchimp__",
                'value' => $this->{"{$audienceId}__submit_to_mailchimp__{$configId1}"},
            ],
            'dateFormats' => [
                'name' => "{$audienceId}__date_formats",
                'prefix' => null,
                'value' => $this->{"{$audienceId}__date_formats"},
            ],
            'audienceTags' => [
                'name' => "{$audienceId}__audience_tags",
                'prefix' => null,
                'value' => $this->{"{$audienceId}__audience_tags"},
            ],
            'optInCheckbox' => [
                'name' => "{$audienceId}__opt_in_checkbox_field",
                'prefix' => null,
                'value' => $this->{"{$audienceId}__opt_in_checkbox_field"},
            ],
            'emailAddress' => [
                'name' => "{$audienceId}__email_address_field",
                'prefix' => null,
                'value' => $this->{"{$audienceId}__email_address_field"},
            ],
            'collectIp' => [
                'name' => "{$audienceId}__collect_ip",
                'prefix' => null,
                'value' => $this->{"{$audienceId}__collect_ip"},
            ],
            'markVip' => [
                'name' => "{$audienceId}__mark_vip",
                'prefix' => null,
                'value' => $this->{"{$audienceId}__mark_vip"},
            ],
            'subscriptionAction' => [
                'name' => "{$audienceId}__subscription_action",
                'prefix' => null,
                'value' => $this->{"{$audienceId}__subscription_action"},
            ],
            'subscriberStatus' => [
                'name' => "{$audienceId}__subscriber_status",
                'prefix' => null,
                'value' => $this->{"{$audienceId}__subscriber_status"},
            ],
            'subscriberUpdateStatus' => [
                'name' => "{$audienceId}__subscriber_update_status",
                'prefix' => null,
                'value' => $this->{"{$audienceId}__subscriber_update_status"},
            ],
            'emailType' => [
                'name' => "{$audienceId}__email_type",
                'prefix' => null,
                'value' => $this->{"{$audienceId}__email_type"},
            ],
            'emailTypeField' => [
                'name' => "{$audienceId}__email_type_field",
                'prefix' => null,
                'value' => $this->{"{$audienceId}__email_type_field"},
            ],
        ];
    }

    /**
     * Form processor configuration
     */
    public function getConfigInputfields(InputfieldWrapper $inputfields): InputfieldWrapper
    {
        parent::getConfigInputfields($inputfields);
        $wire = $this->wire();
        $modules = $wire->modules;

        // $this->loadMailchimpDataFromConfig();
        // $this->getFormProcessingConfigs();

        // dd($this->formProcessingConfigs);

        if (!$this->mailchimp_api_ready) {
            $notReady = $modules->get('InputfieldMarkup');
            $notReady->value = <<<EOD
            <p>Add a valid Mailchimp API key on the Mailchimp Processor module configuration page to get started</p>
            EOD;

            return $inputfields->add($notReady);
        }

        try {
            $this->mailchimpData = $this->getMailchimpApiData();
        } catch (Exception $e) {
            $wire->error("Mailchimp error: {$e->getMessage()}");

            return $inputfields;
        }

        // Clean configs, update available options, remove options no longer available
        // $this->pruneMailchimpConfigs($mailchimpClient);

        /**
         * Usage instructions
         */
        $usageNotes = $modules->get('InputfieldMarkup');
        $usageNotes->label = 'How to configure this form for use with Mailchimp';
        $usageNotes->collapsed = Inputfield::collapsedYes;
        $usageNotes->themeOffset = 'm';
        $usageNotes->value = <<<EOD
        <p><strong>Mailchimp Audience</strong></p>
        <p>The Audience (aka List) is the destination for the entries sent from this form.</p>

        <p><strong>Audience Tags</strong></p>
        <p>Audience tags are configured in Mailchimp and can assist with segmenting incoming entries. Choose one or more tags to further organize information received by Mailchimp from FormBuilder forms.</p>

        <p><strong>Opt-in Checkbox Field</strong></p>
        <p>Specify a checkbox to let users opt-in to email communications, optional.</p>

        <p><strong>Mailchimp Fields</strong></p>
        <p>Select Mailchimp fields to submit data to then configure the form fields to associate with that Mailchimp field in the following section. An email field is required at minimum by Mailchimp and is added automatically.</p>

        <p><strong>Form Field Associations</strong></p>
        <p>Choose a form field to associate with the Mailchimp field that has been added. Notes may be present below fields which may provide additional information that can assist configuring the fields for this form. These may include formatting, expected/allowed values, and maximum length of the value for that field.</p>

        <p>It is not possible to process image/file upload fields</p>

        <p><strong>Changes In Mailchimp</strong></p>
        <p>Changes made in Mailchimp may affect the configuration and submission for this form. If in Mailchimp a Field, Audience, or Audience Tag is deleted, they will not be available for configuration here. Forms configured to submit to an Audence that has been removed from Mailchimp will no longer send data. The same applies to Fields and Audience Tags. Ensure that your forms stay up to date with Mailchimp for best results.</p>

        <p>Changes to names of Fields, Audiences, or Audience Tags in Mailchimp will not affect submissions of forms configured to use them.</p>

        <p><strong>Test Your Form Configuration</strong></p>
        <p>Always test your Mailchimp integrations. Ensure that the fields are submitting the data in the proper formatting expected by Mailchimp and that required fields are configured properly.</p>
        EOD;

        $inputfields->add($usageNotes);



        /**
         * Submission configuration
         */
        $submissionConfigurationFieldset = $modules->get('InputfieldFieldset');
        $submissionConfigurationFieldset->label = __('Mailchimp Integration');
        $submissionConfigurationFieldset->description = __(
            'Confguration details for subscription submissions'
        );
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
        $audienceSelect->columnWidth = 100 / 3;

        if ($this->mailchimpData->audiences) {
            foreach ($this->mailchimpData->audiences as $audience) {
                $audienceSelect->addOption($audience->id, $audience->name);
            }
        }

        if (!$this->mailchimpData->audiences) {
            $audienceSelect->required = false;

            $audienceSelect->notes = __(
                'At least one Audience must be created in Mailchimp to receive submissions'
            );
        }

        // No audience selected
        if (!$this->mailchimp_audience_id) {
            $audienceSelect->notes = __(
                'Select a Mailchimp audience, save, then return here to configure'
            );

            $submissionConfigurationFieldset->add($audienceSelect);

            return $inputfields;
        }

        $submissionConfigurationFieldset->add($audienceSelect);



        /**
         * Audience Tags
         */
        [
            'name' => $audienceTagConfig,
            'value' => $audienceTagValue
        ] = $this->fieldConfig()->audienceTags;

        $tagsSelect = $modules->get('InputfieldAsmSelect');
        $tagsSelect->attr('name', $audienceTagConfig);
        $tagsSelect->label = __('Audience Tags');
        $tagsSelect->description = __(
            'Optional Mailchimp tags assigned to submissions from this form'
        );
        $tagsSelect->attr('value', $audienceTagValue);
        $tagsSelect->themeBorder = 'hide';
        $tagsSelect->collapsed = Inputfield::collapsedNever;
        $tagsSelect->showIf = "mailchimp_audience_id!=''";
        $tagsSelect->sortable = false;
        $tagsSelect->columnWidth = 100 / 3;

        foreach ($this->mailchimpData->audienceTags as $audienceTag) {
            $tagsSelect->addOption($audienceTag->name, $audienceTag->name);
        }

        $submissionConfigurationFieldset->add($tagsSelect);



        /**
         * Opt-In Checkbox
         */
        [
            'name' => $optInCheckboxConfig,
            'value' => $optInCheckboxValue,
        ] = $this->fieldConfig()->optInCheckbox;

        $optInCheckboxSelect = $modules->get('InputfieldSelect');
        $optInCheckboxSelect->attr('name', $optInCheckboxConfig);
        $optInCheckboxSelect->label = __('Opt-In Checkbox');
        $optInCheckboxSelect->description = __('Optional checkbox required for submission to Mailchimp');
        $optInCheckboxSelect->attr('value', $optInCheckboxValue);
        $optInCheckboxSelect->collapsed = Inputfield::collapsedNever;
        $optInCheckboxSelect->themeBorder = 'hide';
        $optInCheckboxSelect->showIf = "mailchimp_audience_id!=''";
        $optInCheckboxSelect->themeInputWidth = 'l';
        $optInCheckboxSelect->columnWidth = 100 / 3;

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
            $optInCheckboxSelect->notes = __('Add one or more checkbox fields to choose');
        }

        $optInCheckboxSelect = array_reduce(
            $checkboxFields,
            fn ($inputfield, $field) => $inputfield->addOption($field->name, $field->label),
            $optInCheckboxSelect
        );

        $submissionConfigurationFieldset->add($optInCheckboxSelect);


        /**
         * Mark Subscribers as VIP
         */
        ['name' => $markVipConfig, 'value' => $markVipValue] = $this->fieldConfig()->markVip;

        $markVip = $modules->get('InputfieldCheckbox');
        $markVip->label = __('VIP Subscriptions');
        $markVip->label2 = __('Mark subscribers as VIP');
        $markVip->notes = __(
            '5000 VIP subscriber limit per account. Overage may cause unexpected behavior. [Read more here](https://mailchimp.com/help/designate-and-send-to-vip-contacts/).'
        );
        $markVip->collapsed = Inputfield::collapsedNever;
        $markVip->attr('name', $markVipConfig);
        $markVip->checked($markVipValue);
        $markVip->themeBorder = 'hide';
        $markVip->columnWidth = 50;
        $markVip->showIf = "mailchimp_audience_id!=''";

        $submissionConfigurationFieldset->add($markVip);



        /**
         * Collect Submitters IP Address
         */
        ['name' => $collectIpConfig, 'value' => $collectIpValue] = $this->fieldConfig()->collectIp;

        $collectIp = $modules->get('InputfieldCheckbox');
        $collectIp->label = __('Subscriber IP Address');
        $collectIp->label2 = __('Capture IP address');
        $collectIp->collapsed = Inputfield::collapsedNever;
        $collectIp->attr('name', $collectIpConfig);
        $collectIp->checked($collectIpValue);
        $collectIp->themeBorder = 'hide';
        $collectIp->columnWidth = 50;

        $submissionConfigurationFieldset->add($collectIp);



        /**
         * Subscription action Add or Add/Update
         */
        [
            'name' => $subscriptionActionConfig,
            'value' => $subscriptionActionValue,
        ] = $this->fieldConfig()->subscriptionAction;

        $subscriptionAction = $modules->get('InputfieldSelect');
        $subscriptionAction->label = __('Subscription Action');
        $subscriptionAction->collapsed = Inputfield::collapsedNever;
        $subscriptionAction->attr('name', $subscriptionActionConfig);
        $subscriptionAction->attr('value', $subscriptionActionValue);
        $subscriptionAction->themeBorder = 'hide';
        $subscriptionAction->required = true;
        $subscriptionAction->columnWidth = 100 / 3;
        $subscriptionAction->themeInputWidth = 'l';
        $subscriptionAction->addOptions([
            'add' => __('Add new subscribers'),
            'add_update' => __('Add new subscribers, update existing subscribers'),
            'unsubscribe' => __('Unsubscribe')
        ]);

        $submissionConfigurationFieldset->add($subscriptionAction);



        /**
         * New Subscriber Status
         */
        [
            'name' => $subscriberStatusConfig,
            'value' => $subscriberStatusValue,
        ] = $this->fieldConfig()->subscriberStatus;

        $subscriberStatus = $modules->get('InputfieldSelect');
        $subscriberStatus->label = __('New Subscriber Status');
        $subscriberStatus->collapsed = Inputfield::collapsedNever;
        $subscriberStatus->attr('name', $subscriberStatusConfig);
        $subscriberStatus->attr('value', $subscriberStatusValue);
        $subscriberStatus->themeBorder = 'hide';
        $subscriberStatus->required = true;
        $subscriberStatus->requiredIf = "{$subscriptionActionConfig}!=unsubscribe";
        $subscriberStatus->showIf = "{$subscriptionActionConfig}!=unsubscribe";
        $subscriberStatus->columnWidth = 100 / 3;
        $subscriberStatus->themeInputWidth = 'l';
        $subscriberStatus->addOptions([
            'subscribed' => __('Subscribed'),
            'pending' => __('Pending (double opt-in)'),
        ]);

        $submissionConfigurationFieldset->add($subscriberStatus);



        /**
         * Existing Subscriber Status
         */
        [
            'name' => $subscriberUpdateStatusConfig,
            'value' => $subscriberUpdateStatusValue,
        ] = $this->fieldConfig()->subscriberUpdateStatus;

        $subscriberUpdateStatus = $modules->get('InputfieldSelect');
        $subscriberUpdateStatus->label = __('Existing Subscriber Status');
        $subscriberUpdateStatus->collapsed = Inputfield::collapsedNever;
        $subscriberUpdateStatus->attr('name', $subscriberUpdateStatusConfig);
        $subscriberUpdateStatus->attr('value', $subscriberUpdateStatusValue);
        $subscriberUpdateStatus->notes = __("Recommended setting: 'Subscribed'");
        $subscriberUpdateStatus->themeBorder = 'hide';
        $subscriberUpdateStatus->required = true;
        $subscriberUpdateStatus->requiredIf = "{$subscriptionActionConfig}=add_update";
        $subscriberUpdateStatus->showIf = "{$subscriptionActionConfig}=add_update";
        $subscriberUpdateStatus->columnWidth = 100 / 3;
        $subscriberUpdateStatus->themeInputWidth = 'l';
        $subscriberUpdateStatus->addOptions([
            'subscribed' => __('Subscribed'),
            'pending' => __('Pending (double opt-in)'),
        ]);

        $submissionConfigurationFieldset->add($subscriberUpdateStatus);



        /**
         * Email Type
         */
        [
            'name' => $emailTypeConfig,
            'value' => $emailTypeValue,
        ] = $this->fieldConfig()->emailType;

        $emailType = $modules->get('InputfieldSelect');
        $emailType->label = __('Email Type');
        $emailType->collapsed = Inputfield::collapsedNever;
        $emailType->attr('name', $emailTypeConfig);
        $emailType->attr('value', $emailTypeValue);
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
        $emailTypeFieldConfig  = $this->fieldConfig()->emailTypeField['name'];

        $emailTypeField = $this->createFormFieldSelect(
            $emailTypeFieldConfig,
            __('Email Type Field'),
            [
                'notes' => __("Must submit a value of either `html` or `text`"),
                'required' => true,
                'requireIf' => "{$emailTypeConfig}=use_field",
                'showIf' => "{$emailTypeConfig}=use_field",
                'themeInputWidth' => 'l',
                'columnWidth' => 50,
            ]
        );

        $submissionConfigurationFieldset->add($emailTypeField);

        $inputfields->add($submissionConfigurationFieldset);



        /**
         * Mailchimp Submitted Fields
         */
        $includedMailchimpFields = $modules->get('InputfieldFieldset');
        $includedMailchimpFields->label = __('Mailchimp Fields');
        $includedMailchimpFields->collapsed = Inputfield::collapsedNever;
        $includedMailchimpFields->description = __(
            'Select Mailchimp fields to collect data for, then choose which form fields should be associated below. The Mailchimp merge tag appears next to each field name.'
        );
        $includedMailchimpFields->notes = __('An email address field is required by Mailchimp and has been added automatically');
        $includedMailchimpFields->themeOffset = 'm';

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
        $fieldAssociationFieldset->label = __('Form Field Associations');
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

        return $inputfields;
    }

    /**
     * Parses all merge fields for any date format specifications and creates a storable value
     */
    private function createMergeFieldDateFormatData(array $mailchimpMergeFields): string
    {
        $dateFormats = array_reduce($mailchimpMergeFields, function($formats, $mergeField) {
            ['type' => $type, 'tag' => $tag, 'options' => $options] = $mergeField;

            if ($type !== 'birthday' && $type !== 'date') {
                return $formats;
            }

            if (array_key_exists('date_format', $options)) {
                $formats[$tag] = $options['date_format'];
            }

            return $formats;
        }, []);

        return json_encode($dateFormats);
    }

    /**
     * Create sets of fields to configure addresses
     */
    private function createAddressMergeFieldsConfiguration(stdClass $mergeField): ?InputfieldFieldset
    {
        if ($mergeField->type !== 'address') {
            return null;
        }

        $includeFieldConfig = $this->fieldConfig($mergeField->merge_id)->submitToMailchimp['name'];

        // showIf is not working for this fieldset unless manually prefixed with the module name
        $showFieldIf = "FormBuilderProcessorMailchimp_{$includeFieldConfig}=1";

        $fieldset = $this->wire()->modules->InputfieldFieldset;
        $fieldset->label = "{$mergeField->name} - {$mergeField->tag}";
        $fieldset->description = __('Address fields are limited to 45 characters.');
        $fieldset->collapsed = Inputfield::collapsedNever;
        $fieldset->themeBorder = 'hide';
        $fieldset->notes = $this->createInputfieldNotesFromMergeFieldOptions($mergeField->options);
        $fieldset->themeColor = 'none';
        $fieldset->showIf = $showFieldIf;

        $subfields = [
            (object) ['name' => 'addr1', 'label' => __('Street Address'), 'required' => true],
            (object) ['name' => 'addr2', 'label' => __('Address Line 2'), 'required' => false],
            (object) ['name' => 'city', 'label' => __('City'), 'required' => true],
            (object) ['name' => 'state', 'label' => __('State/Prov/Region'), 'required' => true],
            (object) ['name' => 'zip', 'label' => __('Postal/Zip'), 'required' => true],
            (object) ['name' => 'country', 'label' => __('Country'), 'required' => false],
        ];

        foreach ($subfields as $subfield) {
            $configFieldName = $this->fieldConfig($mergeField->merge_id, $subfield->name)->addressMergeField['name'];

            $configField = $this->createFormFieldSelect($configFieldName, $subfield->label, [
                'columnWidth' => 100 / 3,
                // 'notes' => $subfield->required ? __('Required by Mailchimp') : null,
                'required' => $subfield->required,
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
            'notes' => __('Required by Mailchimp'),
            'themeInputWidth' => 'l',
            'description' => __('Mailchimp merge tag:') . ' EMAIL',
        ]);
    }

    /**
     * Create a checkbox to determine if a Mailchimp field should be included
     */
    private function createIncludeFieldConfiguration(stdClass $mergeField): InputfieldCheckbox
    {
        [
            'name' => $configName,
            'value' => $configValue
        ] = $this->fieldConfig($mergeField->merge_id)->submitToMailchimp;

        // Automatically include all required fields
        if ($mergeField->required) {
            $configValue = 1;
        }

        $checkbox = $this->wire('modules')->get('InputfieldCheckbox');
        $checkbox->label = "{$mergeField->name} - {$mergeField->tag}";
        $checkbox->attr('name', $configName);
        $checkbox->checked($configValue);
        $checkbox->columnWidth = 25;
        $checkbox->collapsed = Inputfield::collapsedNever;
        $checkbox->themeBorder = 'hide';

        $mergeField->required &&  $checkbox->attr('disabled', 'true');

        return $checkbox;
    }

    /**
     * Create a checkbox to determine if a Mailchimp interest category should be included
     */
    private function createIncludeInterestCategoryConfiguration(
        stdClass $interestCategory
    ): InputfieldCheckbox {
        $interestCategory = $interestCategory->category;

        [
            'name' => $configName,
            'value' => $configValue
        ] = $this->fieldConfig($interestCategory->id)->submitToMailchimp;

        $checkbox = $this->wire('modules')->get('InputfieldCheckbox');
        $checkbox->label = "{$interestCategory->title} - Interest list";
        $checkbox->attr('name', $configName);
        $checkbox->checked($configValue);
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
        $includedFieldName = $this->fieldConfig($mergeField->merge_id)->submitToMailchimp['name'];
        $fieldName = $this->fieldConfig($mergeField->merge_id)->mergeField['name'];

        $visibility = [];

        if ($mergeField->required) {
            $visibility = [
                'showIf' => "{$includedFieldName}=1",
                'requireIf' => "{$includedFieldName}=1",
            ];
        }

        return $this->createFormFieldSelect($fieldName, $mergeField->name, [
            'required' => $mergeField->required,
            'description' => __('Mailchimp merge tag:') . " {$mergeField->tag}",
            'notes' => $this->createInputfieldNotesFromMergeFieldOptions($mergeField->options),
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

        $fieldName = $this->fieldConfig($category->id)->interestCategory['name'];
        $includedFieldName = $this->fieldConfig($category->id)->submitToMailchimp['name'];

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
     * Configured data retrieval
     */


    /**
     * Pull all interest categories set to be included and which have an associated field name
     * @return array<string, mixed>
     */
    private function getConfiguredInterestCategories(): array
    {
        $configPrefix = $this->fieldConfig()->interestCategory['prefix'];

        // Pull merge tag/form field configs
        $interestConfigs = array_filter(
            $this->data,
            fn ($value, $name) => str_starts_with($name, $configPrefix) && !empty($value),
            ARRAY_FILTER_USE_BOTH
        );

        if (!$interestConfigs) {
            return [];
        }

        // Configured form field as key, config key as value
        $interestConfigs = array_flip($interestConfigs);

        // From 'd221b5a07a_interest_category__637e528358'
        // To '637e528358'
        // Null out values not configured for submission to Mailchimp
        array_walk($interestConfigs, function(&$configKey) use ($configPrefix) {
            // Remove config prefix to get interest category ID
            $interestCategoryId = ltrim($configKey, $configPrefix);

            if (!$this->fieldConfig($interestCategoryId)->submitToMailchimp['value']) {
                return $configKey = null;
            }

            $configKey = $this->getMailchimpInterestCategoryByConfigName($configKey);

            return $configKey;
        });

        return array_filter($interestConfigs);
    }

    /**
     * Gets all interests for a given config ID, keyed with interest id, value is name of interest
     * @param  string $configName Config name
     * @return array<string, string>
     */
    private function getMailchimpInterestCategoryByConfigName(string $configName): array
    {
        $id = ltrim($configName, $this->fieldConfig()->interestCategory['prefix']);
        $interestCategories = $this->getStoredMailchimpData('interestCategories');

        $interestsForId = array_reduce($interestCategories, function($matches, $interestCategory) use ($id) {
            $interests = $interestCategory->interests;

            if ($interests->category_id !== $id) {
                return $matches;
            }

            return $matches = $interests->interests;
        }, []);

        if (!$interestsForId) {
            return [];
        }

        return array_reduce($interestsForId, function($interestSets, $interestForId) {
            $interestSets[$interestForId->id] = $interestForId->name;

            return $interestSets;
        }, []);
    }

    /**
     * Gets Mailchimp API data cached in config field from last form configuration
     * @param  string $property
     * @return [type]           [description]
     */
    private function getStoredMailchimpData(?string $property): mixed
    {
        $mailchimpData = json_decode($this->mailchimp_data ?? '')->{$this->mailchimp_audience_id};

        // Will return null if json_decode failed (no data) or full object if no property passed
        if (!$mailchimpData || !$property) {
            return $mailchimpData;
        }

        $dataByProperty = $mailchimpData->$property;

        // Attempt to decode JSON if value is a string
        if (is_string($dataByProperty)) {
            $jsonDecoded = json_decode($dataByProperty);

            if (!json_last_error()) {
                $dataByProperty = $jsonDecoded;
            }
        }

        return $dataByProperty;
    }

    /**
     * Loads config values for the currently configured Mailchimp audience
     * Configs are keyed by complex namespaced strings, handles parsing and loading into a more
     * easily accessible object for form submission processing
     */
    private function getFormProcessingConfigs(): stdClass
    {
        $audienceId = $this->data['mailchimp_audience_id'];
        $audienceConfigs = $this->getConfigsForAudience($audienceId);

        return (object) [
            'audienceId' => $audienceId,
            'audienceTags' => $audienceConfigs['audience_tags'],
            'mergeFields' => $this->getMergeFieldTags($audienceConfigs),
            'addressMergeFields' => $this->getAddressMergeFieldTags($audienceConfigs),
            'action' => $audienceConfigs['subscription_action'],
            'collectIp' => (bool) $audienceConfigs['collect_ip'],
            'emailAddressField' => $audienceConfigs['email_address_field'],
            'optInCheckboxField' => $audienceConfigs['opt_in_checkbox_field'],
            'dateFormats' => $this->getDateConversionFormats(),
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
        $mailchimpData = $this->mailchimpData ?? $this->loadMailchimpDataFromConfig();

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
    private function getMergeFieldTags(array $audienceConfigs): array
    {
        $submittableIds = $this->getSubmittableMergeFieldIds($audienceConfigs);
        $mergeFields = $this->getConfigsByPrefix('mailchimp_mergefield__', $audienceConfigs, true);

        // Create an array of arrays with mergeTag and formField, null out non merge-field items
        array_walk($mergeFields, function(&$value, $mergeFieldId) use ($submittableIds) {
            if (!in_array($mergeFieldId, $submittableIds)) {
                return $value = null;
            }

            $value = [
                'mergeTag' => $this->getMergeFieldById($mergeFieldId)->tag,
                'formField' => $value
            ];
        });

        $mergeFields = array_filter($mergeFields);

        return array_combine(
            array_column($mergeFields, 'mergeTag'),
            array_column($mergeFields, 'formField')
        );
    }

    /**
     * Parses audience-specific configs and returns merge field/form field data for fields that are
     * configured to be submitted
     *
     * @param  array $audienceConfigs  Configs filtered for selected audience
     */
    private function getAddressMergeFieldTags(array $audienceConfigs): array
    {
        $submittableIds = $this->getSubmittableMergeFieldIds($audienceConfigs);

        $mergeFields = $this->getConfigsByPrefix(
            'mailchimp_address_mergefield__',
            $audienceConfigs,
            true
        );

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
        $mergeFields = array_reduce($mergeFields, function($fields, $subfield) {
            if (is_null($subfield)) {
                return $fields;
            }

            ['tag' => $tag, 'formField' => $formField, 'subfield' => $subfield] = $subfield;

            $fields[$tag] ??= [];
            $fields[$tag][$subfield] = $formField;

            return $fields;
        }, []);

        return $mergeFields;
    }

    /**
     * Retrieves a Mailchimp merge field from persisted Mailchimp API data
     * @param  int    $mergeId ID of merge field
     */
    private function getMergeFieldById(string|int $mergeId): ?stdClass
    {
        $mailchimpData = $this->mailchimpData ?? $this->loadMailchimpDataFromConfig();
        $id = (int) $mergeId;

        return array_reduce(
            $mailchimpData->mergeFields,
            fn ($match, $field) => $match = $field->merge_id === $id ? $field : $match
        );
    }

    /**
     * Retrieves IDs of fields configured for submission from persisted Mailchimp API data
     */
    private function getSubmittableMergeFieldIds(array $audienceConfigs): array
    {
        $configs = $this->getConfigsByPrefix('submit_to_mailchimp__', $audienceConfigs, true);

        return array_keys($configs);
    }

    /**
     * Gets all configs for a given audience, strips Audience ID prefix for easier parsing
     * @param  string $audienceId Audience ID to get configs for
     */
    private function getConfigsForAudience(string $audienceId): array
    {
        return $this->getConfigsByPrefix("{$audienceId}__");
    }

    /**
     * Retrieves a set of action configs identified by a prefix string
     * Returns the config data with prefix removed from each key
     * @param  string              $configPrefix          Prefix string to locate configs and remove from key
     * @param  array<mixed, mixed> $configPrefix          Prefix string to locate configs and remove from key
     * @param  bool                 $removeWhereEmptyValue Remove configs that have empty values
     */
    private function getConfigsByPrefix(
        string $configPrefix,
        array $configs = [],
        bool $removeWhereEmptyValue = false
    ): array {
        $audienceConfigs = array_filter(
            $configs ?: $this->data,
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

        return $removeWhereEmptyValue ? array_filter($configs) : $configs;
    }

    /**
     * Loads persisted Mailchimp API data from processor config for current configured Audience into
     * $this->mailchimpData
     */
    private function loadMailchimpDataFromConfig(): stdClass
    {
        return json_decode($this->data['mailchimp_data']);
    }

    /**
     * Retrieves Mailchimp data from API, persists in processor config
     * @throws Exception
     */
    private function getMailchimpApiData(): stdClass
    {
        $mailchimpData = $this->loadMailchimpDataFromConfig();

        $lastRetrieved = new DateTimeImmutable($mailchimpData->lastRetrieved->date);
        $now = new DateTimeImmutable();

        if ($now->diff($lastRetrieved)->i) {
            return $mailchimpData;
        }

        // Get Mailchimp data, handle errors
        try {
            $mailchimpClient = MailchimpClient::init($this->mailchimp_api_key);

            $audiences = $mailchimpClient->getAudiences()['lists'];
            $mergeFields = $mailchimpClient->getMergeFields($this->mailchimp_audience_id)['merge_fields'];
            $audienceTags = $mailchimpClient->getTags($this->mailchimp_audience_id);
            $interestCategories = $mailchimpClient->getInterestCategories($this->mailchimp_audience_id);

            $lastResponse = $mailchimpClient->mailchimp->getLastResponse();

            if ($lastResponse['headers']['http_code'] !== 200) {
                $errorBody = json_decode($lastResponse['body']);


                wire('log')->save(self::LOG_NAME, $lastResponse['body']);

                throw new Exception("Mailchimp error: {$errorBody->title}");
            }
        } catch (Exception $e) {
            $message = $e->getMessage();

            wire('log')->save(self::LOG_NAME, "Mailchimp exception: {$message}");

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

        $this->saveConfigValue(
            'mailchimp_data',
            json_encode($mailchimpData)
        );

        return $mailchimpData;
    }
}
// 1472