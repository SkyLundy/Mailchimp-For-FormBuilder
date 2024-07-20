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
     * Process submitted form
     */
    public function processReady(): void
    {
        if (!$this->mailchimp_audience_id || !$this->mailchimp_api_ready) {
            return;
        }

        $postData = $this->input->post->getArray();

        if (!$this->isMailchimpSubmittable($postData)) {
            return;
        }

        $mailchimpData = $this->parseFormSubmission($postData);

        if (!$mailchimpData) {
            return;
        }

        dd($mailchimpData);

        $mailchimpClient = $this->___subscribe($mailchimpData);

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
    private function isMailchimpSubmittable(array $formData): bool
    {
        $mergeTagConfigPrefix = $this->fieldConfig()->mergeTag['prefix'];

        // Pull all merge tag fields, ensure that there are at least one or more matching fields
        $mergeTagConfigs = array_filter(
            $this->data,
            fn ($value, $name) => str_starts_with($name, $mergeTagConfigPrefix) && !empty($value),
            ARRAY_FILTER_USE_BOTH
        );

        if (!$mergeTagConfigs) {
            return false;
        }

        $optInCheckbox = $this->fieldConfig()->optInCheckbox['value'];

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
     * @param array<string, mixed> $formData Form submission data
     */
    private function parseFormSubmission(array $formData): array
    {
        $mergeFields = array_filter([
            ...$this->getSubmissionMergeFieldData($formData),
            ...$this->getSubmissionAddressData($formData),
        ]);

        $emailConfigName = $this->fieldConfig()->emailAddress['value'];

        return array_filter([
            'email_address' => $formData[$emailConfigName],
            'merge_fields' => $mergeFields,
            'interests' => $this->getSubmissionInterestCategoriesData($formData),
            'tags' => $this->fieldConfig()->audienceTags['value'],
            'ip_signup' => $this->getSubscriberIp(),
            ...$this->getSubscriberStatus(),
        ]);
    }

    /**
     * Create submission parameters for subscriber status
     */
    private function getSubscriberStatus(): array
    {
        $subscriptionAction = $this->fieldConfig()->subscriptionAction['value'];

        // Unsubscribe
        if ($subscriptionAction = 'unsubscribe') {
            return [
                'status' => 'unsubscribed',
            ];
        }

        // Only add new
        if ($subscriptionAction === 'add') {
            return [
                'status' => $this->fieldConfig()->subscriberStatus['value'],
            ];
        }

        // Add new, update existing
        return [
            'status_if_new' => $this->fieldConfig()->subscriberStatus['value'],
            'status' => $this->fieldConfig()->subscriberUpdateStatus['value'],
        ];
    }

    /**
     * Gets the IP address of the user submitting the form if configured to collect
     */
    private function getSubscriberIp(): ?string
    {
        return $this->fieldConfig()->collectIp['value'] ? wire('session')->getIP() : null;
    }

    /**
     * Parses POST data for configured fields/data to submit to mailchimp
     * @param array<string, mixed> $formData Form submission data
     */
    private function getSubmissionMergeFieldData(array $formData): array
    {
        $mergeFields = $this->getConfiguredMergeFields();

        // Convert merge tag config value to form value, [MERGETAG => 'submitted value']
        array_walk($mergeFields, function(&$formField, $mergeTag) use ($formData) {
            $formField = $this->getSubmissionMergeFieldValue($mergeTag, $formField, $formData);
        });

        return array_filter($mergeFields);
    }

    /**
     * Gets the Mailchimp appropriate value for a given submitted form value
     * @param  string $mergeTag       Mailchimp field merge tag
     * @param  string $formFieldName  FormBuilder field name
     * @param  array  $formData       Form submission POST data
     * @return mixed                  Value in format expected by Mailchimp
     */
    private function getSubmissionMergeFieldValue(
        string $mergeTag,
        string $formFieldName,
        array $formData
    ): mixed {
        $field = $this->fbForm->children[$formFieldName];
        $value = $formData[$field->name] ?? null;

        // Unrecognized field or no value
        if (!array_key_exists($field->name, $formData) || !$value) {
            return null;
        }

        $value = match ($field->type) {
            'Page' => wire('pages')->get($value)?->title,
            'Datetime' => $this->convertDateForMailchimp($mergeTag, $value),
            default => $value,
        };

        return trim($value);
    }

    /**
     * Get merge fields for addresses
     */
    private function getSubmissionAddressData(array $formData): array
    {
        $addressFields = $this->getConfiguredAddressFields();

        // From [
        //          ['mailchimpSubfield' => 'addr1'. 'formField' => 'form_field']
        //      ]
        // To [
        //        'addr1' => 'Form submission value'
        //    ]
        $getAddressSubfieldValues = function(array $fields) use ($formData): array {
            $addressVals = array_reduce($fields, function($subfieldData, $fields) use ($formData) {
                ['mailchimpSubfield' => $subfield, 'formField' => $formField] = $fields;

                $formDataContainsField = array_key_exists($formField, $formData);

                $subfieldData[$subfield] = $formDataContainsField ? $formData[$formField] : null;

                return $subfieldData;
            }, []);

            return array_filter($addressVals);
        };

        // Execute function to get subfield values
        array_walk($addressFields, fn (&$fields) => $fields = $getAddressSubfieldValues($fields));

        return array_filter($addressFields);
    }

    /**
     * Parses submission data for interest categories
     * @param  array  $formData FormBuilder form submitted data
     * @return array<string>
     */
    private function getSubmissionInterestCategoriesData(array $formData): array
    {
        ['prefix' => $configPrefix] = $this->fieldConfig()->interestCategory;

          // Pull merge tag/form field configs
        $interestConfigs = array_filter(
            $this->data,
            fn ($value, $name) => str_starts_with($name, $configPrefix) && !empty($value),
            ARRAY_FILTER_USE_BOTH
        );

        if (!$interestConfigs) {
            return [];
        }

        $interestConfigKeys = array_keys($interestConfigs);

        // Convert array of form configs from
        // [
        //     'd221b5a07a_interest_category__637e528358' => 'form_field_name',
        // ]
        //
        // To array of Mailchimp submittable data
        // [
        //     '637e528358' => ['Interest category value']
        // ]
        $mailchimpData = array_reduce(
            $interestConfigKeys,
            function($mcData, $interestConfigKey) use ($interestConfigs, $formData, $configPrefix) {
                // Remove config prefix to get interest category ID
                $interestCatId = ltrim($interestConfigKey, $configPrefix);

                $formFieldName = $interestConfigs[$interestConfigKey];

                if (array_key_exists($formFieldName, $formData)) {
                    $mcData[$interestCatId] = (array) $formData[$formFieldName];
                }

                return $mcData;
            },
            []
        );

        return array_filter($mailchimpData);
    }

    /**
     * Convert a date as submitted by the form to a format expected by Mailchimp
     * @param  string $mergeTag Mailchimp field merge tag
     * @param  string $date     Date submitted via form
     * @throws RuntimeException
     */
    private function convertDateForMailchimp(string $mergeTag, string $date): ?string
    {
        if (!$date) {
            return null;
        }

        $dateFormatConfigs = json_decode($this->fieldConfig()->dateFormats['value'], true);

        $mailchimpDateFormat = $dateFormatConfigs[$mergeTag] ?? null;

        // Unlikely event that somehow a date field format configuration wasn't stored.
        // Runtime exception because it's probably an issue with the module that needs to be fixed
        if (!$mailchimpDateFormat) {
            throw new RuntimeException(
                "The expected Mailchimp date format could not be identified for {$mergeTag}"
            );
        }

        $dateTime = new DateTimeImmutable($date);

        $dateFormat = [
            'MM/DD' => 'm/d',
            'DD/MM' => 'd/m',
            'MM/DD/YYYY' => 'm/d/y',
            'DD/MM/YYYY' => 'd/m/y',
        ][$mailchimpDateFormat];

        return $dateTime->format($dateFormat);
    }


    /**
     * Generates config names, prefixes, and values
     */
    private function fieldConfig(
        string|int|null $configId1 = null,
        string|int|null $configId2 = null,
    ): stdClass {
        $audienceId = $this->mailchimp_audience_id;

        return (object) [
            'interestCategory' => [
                'name' => "{$audienceId}_interest_category__{$configId1}",
                'prefix' => "{$audienceId}_interest_category__",
                'value' => $this->{"{$audienceId}_interest_category__{$configId1}"},
            ],
            'mergeTag' => [
                'name' => "{$audienceId}_merge_tag__{$configId1}",
                'prefix' => "{$audienceId}_merge_tag__",
                'value' => $this->{"{$audienceId}_merge_tag__{$configId1}"},
            ],
            'addressMergeTag' => [
                'name' => "{$this->mailchimp_audience_id}_address_merge_tag-{$configId1}-{$configId2}",
                'prefix' => "{$this->mailchimp_audience_id}_address_merge_tag-",
                'value' => $this->{"{$this->mailchimp_audience_id}_address_merge_tag-{$configId1}-{$configId2}"},
            ],
            'mergeTagIncluded' => [
                'name' => "{$audienceId}_merge_tag_included__{$configId1}",
                'prefix' => "{$audienceId}_merge_tag_included__",
                'value' => $this->{"{$audienceId}_merge_tag_included__{$configId1}"},
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
                'name' => "{$audienceId}__form_opt_in_checkbox",
                'prefix' => null,
                'value' => $this->{"{$audienceId}__form_opt_in_checkbox"},
            ],
            'emailAddress' => [
                'name' => "{$audienceId}__email_address",
                'prefix' => null,
                'value' => $this->{"{$audienceId}__email_address"},
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
            // 'subscriberLang' => "{$this->mailchimp_audience_id}__subscriber_language",
            // 'subscriberLangPreset' => "{$this->mailchimp_audience_id}__subscriber_language_preset",
            // 'subscriberLangFormField' => "{$this->mailchimp_audience_id}__subscriber_language_field",
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

        if (!$this->mailchimp_api_ready) {
            $notReady = $modules->get('InputfieldMarkup');
            $notReady->value = <<<EOD
            <p>Add a valid Mailchimp API key on the FormBuilderProcessorMailchimp module configuration page to get started</p>
            EOD;

            return $inputfields->add($notReady);
        }

        // Get Mailchimp data, handle errors
        try {
            $mailchimpClient = MailchimpClient::init($this->mailchimp_api_key);

            $mcAudiences = $mailchimpClient->getAudiences()['lists'];
            $mcMergeFields = $mailchimpClient->getMergeFields($this->mailchimp_audience_id)['merge_fields'];
            $mcAudienceTags = $mailchimpClient->getTags($this->mailchimp_audience_id);
            $mcInterestCategories = $mailchimpClient->getInterestCategories($this->mailchimp_audience_id);

            $lastResponse = $mailchimpClient->mailchimp->getLastResponse();

            if ($lastResponse['headers']['http_code'] !== 200) {
                $errorBody = json_decode($lastResponse['body']);

                $wire->error("Mailchimp error: {$errorBody->title}");

                wire('log')->save(self::LOG_NAME, $lastResponse['body']);

                return $inputfields;
            }
        } catch (Exception $e) {
            $message = $e->getMessage();

            $wire->error("Mailchimp error: {$message}");

            wire('log')->save(self::LOG_NAME, "Mailchimp exception: {$message}");

            return $inputfields;
        }

        $this->purgeAudienceConfigs($mcAudiences, $mcMergeFields, $mcAudienceTags, $mcInterestCategories);

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
        <p>Choose a form field to associate with a Mailchimp field. Selections may be left blank if submission to Mailchimp is not desired. Fields that are required in Mailchimp are reflected in the fields below. Notes may be present below fields which may provide additional information that can assist you when configuring the fields for this form. These can include formatting, expected/allowed values, and maximum length (size) of the value for that field.</p>

        <p>Addresses are configured as groups of fields. Some fields may be required by Mailchimp to successfully submit an address. If an address is required in Mailchimp, then the fields for that address will be set as required in the field configurations below. Note that fields can be required to have information as part of a complete address as expected by Mailchimp while the fields themselves in this configuration are not marked as required.</p>

        <p>Aside from required fields, it is only necessary to create Mailchimp/form field associations for fields that should be submitted to Mailchimp. By default Mailchimp only requires an email address.</p>

        <p>It is not possible to process image/file upload fields</p>

        <p><strong>NOTE: If merge tags are changed in Mailchimp their corresponding field associations must be updated here.</strong></p>

        <p><strong>Mailchimp Groups/Interests</strong></p>
        <p>Some fields are set as "groups" in Mailchimp. Like tags, these values help add additional organization to Mailchimp contacts. Group fields may be dropdowns, checkboxes, radio buttons, etc. These appear as fields in Mailchimp but behave and collect information with more specificity. When matching form fields to groups, ensure that options/values in your form fields match those noted as configured in Mailchimp. Consider creating/using fields in your form that match the type of field in Mailchimp. The type is noted below each field where it is configured.</p>

        <p><strong>Test Your Form Configuration</strong></p>
        <p>Always test your Mailchimp integrations. Ensure that the fields are submitting the data in the proper formatting expected by Mailchimp and that required fields are configured properly.</p>
        EOD;

        $inputfields->add($usageNotes);



        /**
         * Submission configuration
         */
        $submissionConfigurationFieldset = $modules->get('InputfieldFieldset');
        $submissionConfigurationFieldset->label = __('Mailchimp integration');
        $submissionConfigurationFieldset->description = __(
            'Confguration details for subscription submissions'
        );
        $submissionConfigurationFieldset->themeOffset = 'm';
        $submissionConfigurationFieldset->collapsed = Inputfield::collapsedNever;



         // Mailchimp Audience (list)
        $audienceSelect = $modules->get('InputfieldSelect');
        $audienceSelect->attr('name', 'mailchimp_audience_id');
        $audienceSelect->label = __('Mailchimp audience');
        $audienceSelect->description = __('Choose the Audience (list) subscribers will be added to');
        $audienceSelect->attr('value', $this->mailchimp_audience_id);
        $audienceSelect->collapsed = Inputfield::collapsedNever;
        $audienceSelect->themeBorder = 'hide';
        $audienceSelect->required = true;
        $audienceSelect->themeInputWidth = 'l';
        $audienceSelect->columnWidth = 100 / 3;

        if ($mcAudiences) {
            foreach ($mcAudiences as $audience) {
                ['id' => $id, 'name' => $name] = $audience;

                $audienceSelect->addOption($id, $name);
            }
        }

        if (!$mcAudiences) {
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
        $tagsSelect->label = __('Audience tags');
        $tagsSelect->description = __(
            'Optional Mailchimp tags assigned to submissions from this form'
        );
        $tagsSelect->attr('value', $audienceTagValue);
        $tagsSelect->themeBorder = 'hide';
        $tagsSelect->collapsed = Inputfield::collapsedNever;
        $tagsSelect->showIf = "mailchimp_audience_id!=''";
        $tagsSelect->sortable = false;
        $tagsSelect->columnWidth = 100 / 3;

        foreach ($mcAudienceTags as $audienceTag) {
            $tagsSelect->addOption($audienceTag['name'], $audienceTag['name']);
        }

        $submissionConfigurationFieldset->add($tagsSelect);



        /**
         * Opt-In Checkbox
         */
        $optInCheckboxConfig = $this->fieldConfig()->optInCheckbox['name'];

        $optInCheckboxSelect = $modules->get('InputfieldSelect');
        $optInCheckboxSelect->attr('name', $optInCheckboxConfig);
        $optInCheckboxSelect->label = __('Opt-in checkbox field');
        $optInCheckboxSelect->description = __('Optional checkbox that must be checked to submit data to Mailchimp');
        $optInCheckboxSelect->val($this->$optInCheckboxConfig);
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
                "The checked value must be one of: true, 'true', '1', 1, 'on', or 'yes'"
            );
        }

        if (!$checkboxFields) {
            $optInCheckboxSelect->notes = __(
                'Add one or more checkbox fields to define an opt-in checkbox'
            );
        }

        $optInCheckboxSelect = array_reduce(
            $checkboxFields,
            fn ($inputfield, $checkbox) => $inputfield->addOption($checkbox->name, $checkbox->label),
            $optInCheckboxSelect
        );

        $submissionConfigurationFieldset->add($optInCheckboxSelect);


        /**
         * Mark Subscribers as VIP
         */
        ['name' => $markVipConfig, 'value' => $markVipValue] = $this->fieldConfig()->markVip;

        $markVip = $modules->get('InputfieldCheckbox');
        $markVip->label = __('VIP subscriptions');
        $markVip->label2 = __('Mark subscribers as VIP');
        $markVip->notes = __(
            '5000 VIP subscriber limit per account. Overage may cause unexpected behavior. [Read more here](https://mailchimp.com/help/designate-and-send-to-vip-contacts/).'
        );
        $markVip->collapsed = Inputfield::collapsedNever;
        $markVip->attr('name', $markVipConfig);
        $markVip->checked($markVipValue);
        $markVip->themeBorder = 'hide';
        $markVip->columnWidth = 50;

        $submissionConfigurationFieldset->add($markVip);



        /**
         * Collect Submitters IP Address
         */
        ['name' => $collectIpConfig, 'value' => $collectIpValue] = $this->fieldConfig()->collectIp;

        $collectIp = $modules->get('InputfieldCheckbox');
        $collectIp->label = __('Subscriber IP address');
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
        $subscriptionAction->label = __('Subscription action');
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
        $subscriberStatus->label = __('New subscriber status');
        $subscriberStatus->collapsed = Inputfield::collapsedNever;
        $subscriberStatus->attr('name', $subscriberStatusConfig);
        $subscriberStatus->attr('value', $subscriberStatusValue);
        $subscriberStatus->themeBorder = 'hide';
        $subscriberStatus->required = true;
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
        $subscriberUpdateStatus->label = __('Existing subscriber status');
        $subscriberUpdateStatus->collapsed = Inputfield::collapsedNever;
        $subscriberUpdateStatus->attr('name', $subscriberUpdateStatusConfig);
        $subscriberUpdateStatus->attr('value', $subscriberUpdateStatusValue);
        $subscriberUpdateStatus->notes = "Recommended setting: 'Subscribed'";
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

        $inputfields->add($submissionConfigurationFieldset);



        /**
         * Mailchimp Submitted Fields
         */
        $includedMailchimpFields = $modules->get('InputfieldFieldset');
        $includedMailchimpFields->label = __('Mailchimp fields');
        $includedMailchimpFields->collapsed = Inputfield::collapsedNever;
        $includedMailchimpFields->description = __(
            'Select the fields that form data should be sent to and then choose which form fields should be associated below'
        );
        $includedMailchimpFields->notes = __('An email address field is required by Mailchimp and has been added automatically');
        $includedMailchimpFields->themeOffset = 'm';

        foreach ($mcMergeFields as $mergeField) {
            $includedMailchimpFields->add(
                $this->createIncludeFieldConfiguration($mergeField)
            );
        }

        // Add Interest Groups as a field
        foreach ($mcInterestCategories as $interestCategory) {
            $includedMailchimpFields->add(
                $this->createIncludeInterestCategoryConfiguration($interestCategory)
            );
        }


        $inputfields->add($includedMailchimpFields);




        /**
         * Mailchimp Field Associations
         */
        $fieldAssociationFieldset = $modules->get('InputfieldFieldset');
        $fieldAssociationFieldset->label = __('Form field associations');
        $fieldAssociationFieldset->collapsed = Inputfield::collapsedNever;
        $fieldAssociationFieldset->description = __(
            'Choose a form field to associate with each Mailchimp field. Information provided by Mailchimp may be noted below fields.'
        );
        $fieldAssociationFieldset->themeOffset = 'm';

        $fieldAssociationFieldset->add(
            $this->createEmailMergeFieldConfiguration()
        );

        $mailchimpMergeFields = array_filter(
            $mcMergeFields,
            fn ($mergeField) => $mergeField['type'] !== 'address'
        );

        // Create configurations for each Mailchimp field
        foreach ($mailchimpMergeFields as $mergeField) {
            $fieldAssociationFieldset->add(
                $this->createMergeFieldConfiguration($mergeField)
            );
        }

        // Add Interest Groups as a field
        foreach ($mcInterestCategories as $interestCategory) {
            $fieldAssociationFieldset->add(
                $this->createInterestCategoryConfiguration($interestCategory)
            );
        }



        /**
         * Mailchimp Address Associations (Added under field associations)
         */
        $addressAssociationFieldset = $modules->get('InputfieldFieldset');
        $addressAssociationFieldset->label = __('Mailchimp addresses');
        $addressAssociationFieldset->description = __(
            'To include an address, associate form fields with each address component. Leave blank to exclude, not all fields may be required'
        );
        $addressAssociationFieldset->collapsed = Inputfield::collapsedNever;

        $addressMergeFields = array_filter(
            $mcMergeFields,
            fn ($mergeField) => $mergeField['type'] === 'address'
        );

        foreach ($addressMergeFields as $mergeField) {
            $fieldAssociationFieldset->add(
                $this->createAddressMergeFieldsConfiguration($mergeField)
            );
        }

        $inputfields->add($fieldAssociationFieldset);



        /**
         * Date field formats
         * Internal field, not user configurable. Stores formats for merge fields from API for later
         * form processing, hidden from user
         */
        [
            'name' => $dateFormatsConfig,
            'value' => $dateFormatsValue,
        ] = $this->fieldConfig()->dateFormats;

        $dateFormatsField = $modules->get('InputfieldTextarea');
        $dateFormatsField->attr('name', $dateFormatsConfig);
        $dateFormatsField->attr('value', $dateFormatsValue);
        $dateFormatsField->label = 'Mailchimp merge field date formats';
        $dateFormatsField->description = 'For internal use only';
        $dateFormatsField->collapsed = Inputfield::collapsedYesLocked;
        $dateFormatsField->attr('style', $dateFormatsField->attr('style') . ' display: none !important;');
        $dateFormatsField->wrapAttr('style', $dateFormatsField->attr('style') . ' display: none !important;');

        $dateFormatsField->attr(
            'value',
            $this->createMergeFieldDateFormatData($mailchimpMergeFields)
        );

        $inputfields->add($dateFormatsField);

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
    private function createAddressMergeFieldsConfiguration(array $mergeField): ?InputfieldFieldset
    {
        [
            'tag' => $tag,
            'name' => $name,
            'type' => $type,
            'required' => $required,
            'options' => $options,
        ] = $mergeField;

        if ($type !== 'address') {
            return null;
        }

        $includeFieldConfig = $this->fieldConfig($tag)->mergeTagIncluded['name'];

        $fieldset = $this->wire()->modules->InputfieldFieldset;
        $fieldset->label = "{$name} - {$tag}";
        $fieldset->description = __('Address fields are limited to 45 characters.');
        $fieldset->collapsed = Inputfield::collapsedNever;
        $fieldset->themeBorder = 'hide';
        $fieldset->notes = $this->createInputfieldNotesFromMergeFieldOptions($options);
        $fieldset->themeColor = 'none';

        // showIf is not working for this fieldset unless manually prefixed with the module name
        $fieldset->showIf = "FormBuilderProcessorMailchimp_{$includeFieldConfig}=1";

        $fieldNames = [
            'addr1' => null,
            'addr2' => null,
            'city' => null,
            'state' => null,
            'zip' => null,
            'country' => null,
        ];

        // Create config field names from $fieldNames
        array_walk(
            $fieldNames,
            fn (&$v, $k) => $v = $this->fieldConfig($tag, $k)->addressMergeTag['name']
        );

        $columnWidth = 100 / 3;

        // Street Address
        $addr1Config = $this->createFormFieldSelect($fieldNames['addr1'], __('Street Address'), [
            'columnWidth' => $columnWidth,
            'notes' => __('Required by Mailchimp'),
            'required' => $required,
        ]);

        $fieldset->add($addr1Config);

        // Address Line 2
        $addr2Config = $this->createFormFieldSelect($fieldNames['addr2'], __('Address Line 2'), [
            'columnWidth' => $columnWidth,
        ]);

        $fieldset->add($addr2Config);

        // City
        $cityConfig = $this->createFormFieldSelect($fieldNames['city'], __('City'), [
            'columnWidth' => $columnWidth,
            'notes' => __('Required by Mailchimp'),
            'required' => $required,
        ]);

        $fieldset->add($cityConfig);

        // State
        $stateConfig = $this->createFormFieldSelect($fieldNames['state'], __('State/Prov/Region'), [
            'columnWidth' => $columnWidth,
            'notes' => __('Required by Mailchimp'),
            'required' => $required,
        ]);

        $fieldset->add($stateConfig);

        // Postal/Zip
        $zipConfig = $this->createFormFieldSelect($fieldNames['zip'], __('Postal/Zip'), [
            'columnWidth' => $columnWidth,
            'notes' => __('Required by Mailchimp'),
            'required' => $required,
        ]);

        $fieldset->add($zipConfig);

        // Country
        $countryConfig = $this->createFormFieldSelect($fieldNames['country'], __('Country'), [
            'columnWidth' => $columnWidth,
            'required' => $required,
        ]);

        $fieldset->add($countryConfig);

        return $fieldset;
    }

    /**
     * Creates specific configuration field for email address required by Mailchimp
     */
    private function createEmailMergeFieldConfiguration(): InputfieldSelect
    {
        $fieldName = $this->fieldConfig()->emailAddress['name'];
        $fieldLabel = __('Email Address');

        return $this->createFormFieldSelect($fieldName, $fieldLabel, [
            'required' => true,
            'notes' => __('Required by Mailchimp'),
            'themeInputWidth' => 'l',
            'description' => __('Mailchimp merge tag:') . ' EMAIL',
        ]);
    }

    /**
     * Create a checkbox to determine if a Mailchimp field should be included
     */
    private function createIncludeFieldConfiguration(array $mergeField): InputfieldCheckbox
    {
        ['tag' => $tag, 'name' => $name, 'required' => $required] = $mergeField;

        [
            'name' => $configName,
            'value' => $configValue
        ] = $this->fieldConfig($tag)->mergeTagIncluded;

        // Automatically include all required fields
        if ($required) {
            $configValue = 1;
        }

        $checkbox = $this->wire('modules')->get('InputfieldCheckbox');
        $checkbox->label = "{$name} - {$tag}";
        $checkbox->attr('name', $configName);
        $checkbox->checked($configValue);
        $checkbox->columnWidth = 25;
        $checkbox->collapsed = Inputfield::collapsedNever;
        $checkbox->themeBorder = 'hide';

        $required &&  $checkbox->attr('disabled', 'true');

        return $checkbox;
    }

    /**
     * Create a checkbox to determine if a Mailchimp interest category should be included
     */
    private function createIncludeInterestCategoryConfiguration(
        array $interestCategory
    ): InputfieldCheckbox {
        ['id' => $categoryId, 'title' => $categoryTitle] = $interestCategory['category'];

        [
            'name' => $configName,
            'value' => $configValue
        ] = $this->fieldConfig($categoryId)->mergeTagIncluded;

        $checkbox = $this->wire('modules')->get('InputfieldCheckbox');
        $checkbox->label = "{$categoryTitle} - Interest list";
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
    private function createMergeFieldConfiguration(array $mergeField): ?InputfieldSelect
    {
        [
            'tag' => $tag,
            'name' => $name,
            'required' => $required,
            'options' => $options,
        ] = $mergeField;

        $includedFieldName = $this->fieldConfig($tag)->mergeTagIncluded['name'];
        $fieldName = $this->fieldConfig($tag)->mergeTag['name'];

        $visibility = [];

        if ($required) {
            $visibility = [
                'showIf' => "{$includedFieldName}=1",
                'requireIf' => "{$includedFieldName}=1",
            ];
        }

        return $this->createFormFieldSelect($fieldName, $name, [
            'required' => $required,
            'description' => __('Mailchimp merge tag:') . " {$tag}",
            'notes' => $this->createInputfieldNotesFromMergeFieldOptions($options),
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
    private function createInterestCategoryConfiguration(array $interestCategory): ?InputfieldSelect
    {
        ['category' => $mcCategory, 'interests' => $mcInterests] = $interestCategory;

        ['id' => $categoryId, 'type' => $categoryType, 'title' => $categoryTitle] = $mcCategory;

        // Create notes
        $interests = array_map(fn ($interest) => $interest['name'], $mcInterests['interests']);

        $notes = implode('. ', [
            "Type: {$categoryType}",
            'Values: ' . implode(', ', $interests)
        ]);

        $fieldName = $this->fieldConfig($categoryId)->interestCategory['name'];
        $includedFieldName = $this->fieldConfig($categoryId)->mergeTagIncluded['name'];

        return $this->createFormFieldSelect($fieldName, $categoryTitle, [
            'description' => __('Interest list'),
            'notes' => $notes,
            'themeInputWidth' => 'l',
            'showIf' => "{$includedFieldName}=1",
            'requireIf' => "{$includedFieldName}=1",
            'required' => true,
        ]);
    }

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
        $fieldSelect->val($this->$name);
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
    private function createInputfieldNotesFromMergeFieldOptions(array $options): ?string
    {
        array_walk($options, function(&$value, $name) {
            $name = preg_replace('/[-_]/', ' ', $name);
            $name = ucfirst($name);

            is_array($value) && $value = implode(', ', $value);

            $value = "{$name}: $value";
        });

        return implode('. ', array_values($options));
    }

    /**
     * Removes empty values that have been namespaced to the current Mailchimp Audience ID
     * Keeps the config from getting gunked up with unused or legacy config keys/values
     */
    private function purgeAudienceConfigs(
        array $audiences,
        array $mergeFields,
        array $audienceTags,
        array $interestCategories,
    ): void {
        $config = $this->data;
// dd($this->getConfiguredMergeFields());
//         dd($audiences, $mergeFields, $audienceTags, $interestCategories);
    }

    /**
     * Configured data retrieval
     */

    /**
     * Pull all merge tags that have been configured with associated field names
     * @return array<string, string>
     */
    private function getConfiguredMergeFields(): array
    {
        $configPrefix = $this->fieldConfig()->mergeTag['prefix'];

        // Pull merge tag/form field configs
        $mergeTags =  array_filter(
            $this->data,
            fn ($fieldName, $key) => str_starts_with($key, $configPrefix) && !empty($fieldName),
            ARRAY_FILTER_USE_BOTH
        );

        // ['d221b5a07a_merge_tag__TAGNAME']
        $keys = array_keys($mergeTags);

        // ['form_field']
        $values = array_values($mergeTags);

        // ['TAGNAME']
        $keys = array_map(fn ($key) => ltrim($key, $configPrefix), $keys);

        return array_combine($keys, $values);
    }

    /**
     * Pull all address merge tags configured with an associated field name
     * @return array<array>
     */
    private function getConfiguredAddressFields(): array
    {
        $configPrefix = $this->fieldConfig()->addressMergeTag['prefix'];

        // Get all merge tag configs from stored form config data that have values
        $mergeTagConfigs = array_filter(
            $this->data,
            fn ($value, $name) => str_starts_with($name, $configPrefix) && !empty($value),
            ARRAY_FILTER_USE_BOTH
        );

        // Flip so that configured field names are keys and configuration keys are values
        $mergeTagConfigs = array_flip($mergeTagConfigs);

        // From 'd221b5a07a_address_merge_tag-ADDRESS-addr1'
        // To [ADDRESS', 'addr1', 'field_name']
        array_walk($mergeTagConfigs, function(&$configKey, $fieldName) {
            [, $mergeTag, $addressSubfield] = explode('-', $configKey);

            $configKey = [$mergeTag, $addressSubfield, $fieldName];
        });

        $configArrays = array_values($mergeTagConfigs);

        // [
        //     'ADDRESS' => [
        //         [
        //             'mailchimpSubfield' => 'addr1',
        //             'formField' => 'field_name'
        //         ]
        //     ],
        // ]
        return array_reduce($configArrays, function($configs, $configArray) {
            [$mergeTag, $addressSubfield, $fieldName] = $configArray;

            $configs[$mergeTag] ??= [];

            $configs[$mergeTag][] = [
                'mailchimpSubfield' => $addressSubfield,
                'formField' => $fieldName,
            ];

            return $configs;
        }, []);
    }



    /**
     * Creates select inputfield to preset a language for subscriber submissions
     */
    // private function createSubmissionLanguagePreSelect(): InputfieldSelect
    // {
    //     $configName = $this->subscriberLanguagePresetConfigName();
    //     $languageConfig = $this->subscriberLanguageConfigName();

    //     $select = $this->modules->InputfieldSelect;
    //     $select->label = __('Subscriber language');
    //     $select->attr('name', $configName);
    //     $select->attr('value', $this->$configName);
    //     $select->required = true;
    //     $select->showIf = "{$languageConfig}=pre_select";
    //     $select->requiredIf = "{$languageConfig}=pre_select";
    //     $select->collapsed = Inputfield::collapsedNever;
    //     $select->themeBorder = 'hide';

    //     $select->addOptions([
    //         'ar' => __('Arabic'),
    //         'af' => __('Afrikaans'),
    //         'be' => __('Belarusian'),
    //         'bg' => __('Bulgarian'),
    //         'ca' => __('Catalan'),
    //         'zh' => __('Chinese'),
    //         'hr' => __('Croatian'),
    //         'cs' => __('Czech'),
    //         'da' => __('Danish'),
    //         'nl' => __('Dutch'),
    //         'en' => __('English'),
    //         'et' => __('Estonian'),
    //         'fa' => __('Farsi'),
    //         'fi' => __('Finnish'),
    //         'fr' => __('French (France)'),
    //         'fr_CA' => __('French (Canada)'),
    //         'de' => __('German'),
    //         'el' => __('Greek'),
    //         'he' => __('Hebrew'),
    //         'hi' => __('Hindi'),
    //         'hu' => __('Hungarian'),
    //         'is' => __('Icelandic'),
    //         'id' => __('Indonesian'),
    //         'ga' => __('Irish'),
    //         'it' => __('Italian'),
    //         'ja' => __('Japanese'),
    //         'km' => __('Khmer'),
    //         'ko' => __('Korean'),
    //         'lv' => __('Latvian'),
    //         'lt' => __('Lithuanian'),
    //         'mt' => __('Maltese'),
    //         'ms' => __('Malay'),
    //         'mk' => __('Macedonian'),
    //         'no' => __('Norwegian'),
    //         'pl' => __('Polish'),
    //         'pt' => __('Portuguese (Brazil)'),
    //         'pt_PT' => __('Portuguese (Portugal)'),
    //         'ro' => __('Romanian'),
    //         'ru' => __('Russian'),
    //         'sr' => __('Serbian'),
    //         'sk' => __('Slovak'),
    //         'sl' => __('Slovenian'),
    //         'es' => __('Spanish (Mexico)'),
    //         'es_ES' => __('Spanish (Spain)'),
    //         'sw' => __('Swahili'),
    //         'sv' => __('Swedish'),
    //         'ta' => __('Tamil'),
    //         'th' => __('Thai'),
    //         'tr' => __('Turkish'),
    //         'uk' => __('Ukrainian'),
    //         'vi' => __('Vietnamese'),
    //     ]);

    //     return $select;
    // }
}
