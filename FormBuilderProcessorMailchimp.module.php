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
    private const LOG_NAME = 'fb-mailchimp';

    /**
     * Process submitted form
     */
    public function processReady()
    {
        if (!$this->mailchimp_audience_id || !$this->mailchimp_api_ready) {
            return;
        }

        $postData = $this->input->post->getArray();

        if (!$this->isMailchimpSubmittable($postData)) {
            return;
        }

        $mailchimpData = $this->parseFormSubmission($postData);
dd($mailchimpData);
        // dd('fired', $this->input->post->getArray(), $this->fbForm->children, $mailchimpData, 'end');

        $result = $this->___subscribe($mailchimpData);

        bd($result);

        // $this->logResult($result);
    }

    /**
     * Logs an error if encountered
     */
    private function logResult(array $result): void
    {
        $result = json_encode($result ?: ['No response data']);
        dd($result);
    }

    /**
     * Submit subscription to Mailchimp
     */
    public function ___subscribe(array $subscriberData, ?string $audienceId = null): MailchimpClient
    {
        $audienceId ??= $this->mailchimp_audience_id;
        $mailchimpClient = MailchimpClient::init($this->mailchimp_api_key);
        $subscriptionAction = $this->fieldConfig()->subscriptionAction['value'];

        match ($subscriptionAction) {
            'add_update' => $mailchimpClient->subscribeOrUpdate($subscriberData, $audienceId),
            default => $mailchimpClient->subscribe($subscriberData, $audienceId),
        };

        return $mailchimpClient;
    }

    /**
     * Checks suitibility for submission
     * - If there is a checkbox designated as an opt-in and whether this submission qualifies
     * - There are configured form/Mailchimp field pairs
     */
    private function isMailchimpSubmittable(array $postData): bool
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
        if (!array_key_exists($optInCheckbox, $postData) && $optInCheckbox) {
            return false;
        }

        return filter_var($postData[$optInCheckbox], FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Parses POST data and prepares Mailchimp payload according to configuration
     */
    private function parseFormSubmission(array $postData): array
    {
        $mergeFields = array_filter([
            ...$this->getSubmissionMergeFieldData($postData),
            ...$this->getSubmissionAddressData($postData),
        ]);

        $emailConfigName = $this->fieldConfig()->emailAddress['name'];

        return array_filter([
            'email_address' => $postData[$emailConfigName],
            'merge_fields' => $mergeFields,
            'interests' => $this->getSubmissionInterestCategoriesData($postData),
            'tags' => $this->fieldConfig()->audienceTags['value'],
            'ip_signup' => $this->getSubscriberIp(),
            'status' => 'subscribed',
        ]);
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
     */
    private function getSubmissionMergeFieldData(array $postData): array
    {
        ['prefix' => $configPrefix] = $this->fieldConfig()->mergeField;

        // Pull merge tag/form field configs
        $mergeTagConfigs = array_filter(
            $this->data,
            fn ($value, $name) => str_starts_with($name, $configPrefix) && !empty($value),
            ARRAY_FILTER_USE_BOTH
        );

        if (!$mergeTagConfigs) {
            return [];
        }

        $mergeTagConfigKeys = array_keys($mergeTagConfigs);

        // Convert array of form configs from
        // [
        //     'XXXXXXXXXX_address_merge_tag__TAGNAME' => 'form_field_name',
        // ]
        //
        // To array of Mailchimp submittable data
        // [
        //     'TAGNAME' => 'submitted form value'
        // ]
        $mailchimpData = array_reduce(
            $mergeTagConfigKeys,
            function($data, $mergeTagConfigKey) use ($mergeTagConfigs, $postData, $configPrefix) {
                // Remove prefix to get Mailchimp merge tag name
                $mergeTag = ltrim($mergeTagConfigKey, $configPrefix);

                $formFieldName = $mergeTagConfigs[$mergeTagConfigKey];

                $data[$mergeTag] = $this->getSubmissionMergeFieldValue(
                    $mergeTag,
                    $formFieldName,
                    $postData
                );

                return $data;
            },
            []
        );

        return array_filter($mailchimpData);
    }

    /**
     * Gets the Mailchimp appropriate value for a given submitted form value
     * @param  string $mergeTag       Mailchimp field merge tag
     * @param  string $formFieldName  FormBuilder field name
     * @param  array  $data           Form submission POST data
     * @return mixed                  Value in format expected by Mailchimp
     */
    private function getSubmissionMergeFieldValue(
        string $mergeTag,
        string $formFieldName,
        array $data
    ): mixed {
        $field = $this->fbForm->children[$formFieldName];
        $value = $data[$field->name] ?? null;

        // Unrecognized field or no value
        if (!array_key_exists($field->name, $data) || !$value) {
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
    private function getSubmissionAddressData(array $postData): array
    {
        ['prefix' => $configPrefix] = $this->fieldConfig()->mergeTag;

        // Get all merge tag configs from stored form config data
        $mergeTagConfigs = array_filter(
            $this->data,
            fn ($value, $name) => str_starts_with($name, $configPrefix) && !empty($value),
            ARRAY_FILTER_USE_BOTH
        );

        if (!$mergeTagConfigs) {
            return [];
        }

        $configKeys = array_keys($mergeTagConfigs);

        // Split config name into property name, merge tag name, and Mailchimp field name
        return array_reduce(
            $configKeys,
            function($mcData, $tagConfigKey) use ($mergeTagConfigs, $postData) {
                // Split config key XXXXXXXXXX_address_merge_tag-ADDRESS-addr1
                [, $mergeTag, $mailchimpField] = explode('-', $tagConfigKey);

                $mcData[$mergeTag] ??= [];

                // Get ProcessWire field name using the full tag config key
                $pwFieldName = $mergeTagConfigs[$tagConfigKey];

                if (array_key_exists($pwFieldName, $postData)) {
                    $mcData[$mergeTag][$mailchimpField] = trim($postData[$pwFieldName]);
                }

                return $mcData;
            },
            []
        );
    }

    /**
     * Parses submission data for interest categories
     * @param  array  $postData FormBuilder form submitted data
     * @return array<string>
     */
    private function getSubmissionInterestCategoriesData(array $postData): array
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
            function($data, $interestConfigKey) use ($interestConfigs, $postData, $configPrefix) {
                // Remove config prefix to get interest category ID
                $interestCatId = ltrim($interestConfigKey, $configPrefix);

                $formFieldName = $interestConfigs[$interestConfigKey];

                if (array_key_exists($formFieldName, $postData)) {
                    $data[$interestCatId] = (array) $postData[$formFieldName];
                }

                return $data;
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
    private function fieldConfig(string|int|null $configIdentifier = null): stdClass
    {
        $audienceId = $this->mailchimp_audience_id;

        return (object) [
            'interestCategory' => [
                'name' => "{$audienceId}_interest_category__{$configIdentifier}",
                'prefix' => "{$audienceId}_interest_category__",
                'value' => $this->{"{$audienceId}_interest_category__{$configIdentifier}"},
            ],
            'mergeTag' => [
                'name' => "{$audienceId}_merge_tag__{$configIdentifier}",
                'prefix' => "{$audienceId}_merge_tag__",
                'value' => $this->{"{$audienceId}_merge_tag__{$configIdentifier}"},
            ],
            'fieldIncluded' => [
                'name' => "{$audienceId}_field_included__{$configIdentifier}",
                'prefix' => "{$audienceId}_field_included__",
                'value' => $this->{"{$audienceId}_field_included__{$configIdentifier}"},
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

            $lastResponse = $mailchimpClient->mailChimp->getLastResponse();

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
            'Confguration details for where subscriptions will be submitted to and processing options'
        );
        $submissionConfigurationFieldset->themeOffset = 'm';
        $submissionConfigurationFieldset->collapsed = Inputfield::collapsedNever;



         // Mailchimp Audience (list)
        $audienceSelect = $modules->get('InputfieldSelect');
        $audienceSelect->attr('name', 'mailchimp_audience_id');
        $audienceSelect->label = __('Mailchimp audience');
        $audienceSelect->description = __('Choose the Audience (list) subscribers will be added to');
        $audienceSelect->val($this->mailchimp_audience_id);
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
        $optInCheckboxSelect->description = __('Leave blank to send all submissions to Mailchimp');
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



        // Mark subscribers as VIP
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



        // Collect Submitters IP Address
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



        // Subscription action Add or Add/Update
        [
            'name' => $subscriptionActionConfig,
            'value' => $subscriptionActionValue,
        ] = $this->fieldConfig()->subscriptionAction;

        $subscriptionAction = $modules->get('InputfieldSelect');
        $subscriptionAction->label = __('Subscription action');
        $subscriptionAction->collapsed = Inputfield::collapsedNever;
        $subscriptionAction->attr('name', $subscriptionActionConfig);
        $subscriptionAction->attr('value', $subscriptionActionValue ?: 'add');
        $subscriptionAction->themeBorder = 'hide';
        $subscriptionAction->required = true;
        $subscriptionAction->columnWidth = 100 / 3;
        $subscriptionAction->themeInputWidth = 'l';
        $subscriptionAction->addOptions([
            'add' => __('Add new subscribers only'),
            'add_update' => __('Add new subscribers, update if already subscribed'),
        ]);

        $submissionConfigurationFieldset->add($subscriptionAction);



        // Subscriber status
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
            'unsubscribed' => __('Unsubscribed'),
        ]);

        $submissionConfigurationFieldset->add($subscriberStatus);



        // Subscriber status - Existing contacts
        [
            'name' => $subscriberUpdateStatusConfig,
            'name' => $subscriberUpdateStatusValue,
        ] = $this->fieldConfig()->subscriberUpdateStatus;

        $subscriberUpdateStatus = $modules->get('InputfieldSelect');
        $subscriberUpdateStatus->label = __('Existing subscriber status');
        $subscriberUpdateStatus->collapsed = Inputfield::collapsedNever;
        $subscriberUpdateStatus->attr('name', $subscriberUpdateStatusConfig);
        $subscriberUpdateStatus->attr('value', $subscriberUpdateStatusValue);
        $subscriberUpdateStatus->themeBorder = 'hide';
        $subscriberUpdateStatus->required = true;
        $subscriberUpdateStatus->requiredIf = "{$subscriptionActionConfig}=add_update";
        $subscriberUpdateStatus->showIf = "{$subscriptionActionConfig}=add_update";
        $subscriberUpdateStatus->columnWidth = 100 / 3;
        $subscriberUpdateStatus->themeInputWidth = 'l';
        $subscriberUpdateStatus->addOptions([
            'subscribed' => __('Subscribed'),
            'pending' => __('Pending (double opt-in)'),
            'unsubscribed' => __('Unsubscribed'),
        ]);

        $submissionConfigurationFieldset->add($subscriberUpdateStatus);

        $inputfields->add($submissionConfigurationFieldset);



        /**
         * Mailchimp Submitted Fields
         */
        $includedMailchimpFields = $modules->get('InputfieldFieldset');
        $includedMailchimpFields->label = __('Mailchimp fields to submit');
        $includedMailchimpFields->collapsed = Inputfield::collapsedNever;
        $includedMailchimpFields->description = __(
            'Select the fields that form data should be sent to and then choose which form field value should be submitted below'
        );
        $includedMailchimpFields->themeOffset = 'm';


        $inputfields->add($includedMailchimpFields);


        /**
         * Mailchimp Field Associations
         */
        $fieldAssociationFieldset = $modules->get('InputfieldFieldset');
        $fieldAssociationFieldset->label = __('Mailchimp/form field associations');
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

        // For adding column fillers, account for email config field already added
        $fieldsAdded = 0;

        // Create configurations for each Mailchimp field
        foreach ($mailchimpMergeFields as $mergeField) {
            $fieldAssociationFieldset->add(
                $this->createMergeFieldConfiguration($mergeField)
            );

            $fieldsAdded++;
        }

        // Add Interest Groups as a field
        foreach ($mcInterestCategories as $interestCategory) {
            $fieldAssociationFieldset->add(
                $this->createInterestCategoryConfiguration($interestCategory)
            );

            $fieldsAdded++;
        }

        // Add spacing columns to fill remainder of row
        $fieldAssociationFieldset = $this->addSpacingColumns(
            $fieldAssociationFieldset,
            $fieldsAdded % 3,
            100 / 3
        );




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




        // Subscriber Language
        // $subscriberLanguageConfigName = $this->subscriberLanguageConfigName();

        // $subscriberLangaugeConfig = $modules->get('InputfieldSelect');
        // $subscriberLangaugeConfig->label = __('Include subscriber language');
        // $subscriberLangaugeConfig->attr('name', $subscriberLanguageConfigName);
        // $subscriberLangaugeConfig->attr('value', $this->$subscriberLanguageConfigName);
        // $subscriberLangaugeConfig->collapsed = Inputfield::collapsedNever;
        // $subscriberLangaugeConfig->themeBorder = 'hide';
        // $subscriberLangaugeConfig->required = true;
        // $subscriberLangaugeConfig->columnWidth = 100 / 3;
        // $subscriberLangaugeConfig->addOptions([
        //     'omit' => __('Do not include in submission'),
        //     'pre_select' => __('Pre-select a language'),
        //     'form_field' => __('Choose a field'),
        // ]);

        // $additionalOptionsFieldset->add($subscriberLangaugeConfig);

        // // Subscriber Language - Pre-select
        // $additionalOptionsFieldset->add(
        //     $this->createSubmissionLanguagePreSelect()
        // );

        // $langFieldConfigName = $this->subscriberLanguageFormFieldConfigName();
        // $langFieldConfigLabel = __('Subscriber language field field');

        // $additionalOptionsFieldset->add(
        //     $this->createFormFieldSelect($langFieldConfigName, $langFieldConfigLabel, [
        //         'showIf' => "{$subscriberLanguageConfigName}=form_field",
        //         'requiredIf' => "{$subscriberLanguageConfigName}=form_field",
        //         'required' => true,
        //         'notes' => __(
        //             'Ensure this field provides a language code Mailchimp recognizes. Blank and incorrect language codes will be ignored. [Read more here](https://mailchimp.com/help/view-and-edit-contact-languages/)'
        //         )
        //     ])
        // );

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

        $fieldset = $this->wire()->modules->InputfieldFieldset;
        $fieldset->label = "{$name} - {$tag}";
        $fieldset->description = __('Address fields are limited to 45 characters.');
        $fieldset->collapsed = Inputfield::collapsedNever;
        $fieldset->themeBorder = 'hide';
        $fieldset->notes = $this->createInputfieldNotesFromMergeFieldOptions($options);
        $fieldset->themeColor = 'none';

        $configNameBase = "{$this->mailchimp_audience_id}_address_merge_tag-{$tag}";

        $fieldNames = (object) [
            'addr1' => "{$configNameBase}-addr1",
            'addr2' => "{$configNameBase}-addr2",
            'city' => "{$configNameBase}-city",
            'state' => "{$configNameBase}-state",
            'zip' => "{$configNameBase}-zip",
            'country' => "{$configNameBase}-country",
        ];

        $columnWidth = 100 / 3;

        // Street Address
        $addr1Config = $this->createFormFieldSelect($fieldNames->addr1, __('Street Address'), [
            'columnWidth' => $columnWidth,
            'notes' => __('Required by Mailchimp'),
            'required' => $required,
        ]);

        $fieldset->add($addr1Config);

        // Address Line 2
        $addr2Config = $this->createFormFieldSelect($fieldNames->addr2, __('Address Line 2'), [
            'columnWidth' => $columnWidth,
        ]);

        $fieldset->add($addr2Config);

        // City
        $cityConfig = $this->createFormFieldSelect($fieldNames->city, __('City'), [
            'columnWidth' => $columnWidth,
            'notes' => __('Required by Mailchimp'),
            'required' => $required,
        ]);

        $fieldset->add($cityConfig);

        // State
        $stateConfig = $this->createFormFieldSelect($fieldNames->state, __('State/Prov/Region'), [
            'columnWidth' => $columnWidth,
            'notes' => __('Required by Mailchimp'),
            'required' => $required,
        ]);

        $fieldset->add($stateConfig);

        // Postal/Zip
        $zipConfig = $this->createFormFieldSelect($fieldNames->zip, __('Postal/Zip'), [
            'columnWidth' => $columnWidth,
            'notes' => __('Required by Mailchimp'),
            'required' => $required,
        ]);

        $fieldset->add($zipConfig);

        // Country
        $countryConfig = $this->createFormFieldSelect($fieldNames->country, __('Country'), [
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
     * Create a checkbox to include the email
     * @return [type] [description]
     */
    private function createIncludeEmailFieldConfiguration(): InputfieldCheckbox
    {
        $checkbox = $this->wire('modules')->get('InputfieldCheckbox');
        // $checkbox->attr('name', "{$this->mailchimp_audience_id}_include_email_field");
        $checkbox->attr('value', '1');
        $checkbox->attr('disabled', 'true');
        $checkbox->required = true;
        $checkbox->columnWidth = 100 / 3;
        $checkbox->label = __('Email (required by Mailchimp');

        return $checkbox;
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
        ] = $this->fieldConfig($tag)->mergeTag;

        // Automatically include all required fields
        if ($required) {
            $configValue = 1;
        }

        $checkbox = $this->wire('modules')->get('InputfieldCheckbox');
        $checkbox->attr('name', $configName);
        $checkbox->attr('value', $configValue);
        $checkbox->attr('disabled', $required ? 'true' : 'false');
        $checkbox->columnWidth = 100 / 3;
        $checkbox->label = $name;

        return $checkbox;
    }

    /**
     * Create a checkbox to determine if a Mailchimp interest category should be included
     */
    private function createIncludeInterestCategoryConfiguration(
        array $interestCategory
    ): InputfieldCheckbox {
        ['category' => $mcCategory, 'interests' => $mcInterests] = $interestCategory;

        ['id' => $categoryId, 'type' => $categoryType, 'title' => $categoryTitle] = $mcCategory;

        [
            'name' => $configName,
            'value' => $configValue
        ] = $this->fieldConfig($categoryId)->fieldIncluded;

        $checkbox = $this->wire('modules')->get('InputfieldCheckbox');
        $checkbox->attr('name', $configName);
        $checkbox->attr('value', $configValue);
        $checkbox->columnWidth = 100 / 3;
        $checkbox->label = $categoryTitle;

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

        $includeFieldConfig = $this->fieldConfig($tag)->mergeTag['name'];

        $fieldName = $this->fieldConfig($tag)->mergeTag['name'];

        return $this->createFormFieldSelect($fieldName, $name, [
            'required' => $required,
            'description' => __('Mailchimp merge tag:') . " {$tag}",
            'notes' => $this->createInputfieldNotesFromMergeFieldOptions($options),
            'showIf' => "{$includeFieldConfig}=1",
            'requireIf' => "{$includeFieldConfig}=1",
            'themeInputWidth' => 'l',
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

        return $this->createFormFieldSelect($fieldName, $categoryTitle, [
            'description' => __('Interest list'),
            'notes' => $notes,
            'themeInputWidth' => 'l',
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
     * Add spacing columns to fill remaining space left after fields in fieldset
     */
    private function addSpacingColumns(
        InputfieldFieldset $fieldset,
        int $count,
        int|float $width
    ): InputfieldFieldset {
        while ($count >= 0) {
            $filler = $this->modules->InputfieldFieldset;
            $filler->attr('style', $filler->attr('style') . ' visibility: hidden !important;');
            $filler->wrapAttr('style', $filler->attr('style') . ' visibility: hidden !important;');
            $filler->columnWidth = $width;
            $filler->themeBorder = 'hide';

            $fieldset->add($filler);

            $count--;
        }

        return $fieldset;
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
