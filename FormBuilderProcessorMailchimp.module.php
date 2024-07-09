<?php

namespace ProcessWire;

wire('classLoader')->addNamespace('FormBuilderProcessorMailchimp\App', __DIR__ . '/app');

use FormBuilderProcessorMailchimp\App\MailChimp;

class FormBuilderProcessorMailchimp extends FormBuilderProcessorAction implements Module, ConfigurableModule
{
    /**
     * Mailchimp API client
     */
    private ?Mailchimp $mailchimpClient = null;

    /**
     * Mailchimp API memoized data
     */

    private array $mailchimpAudiences = [];

    private array $mailchimpMergeFields = [
        'audienceId' => null,
        'mergeFields' => [],
    ];

    private array $mailchimpSegments = [
        'audienceId' => null,
        'segments' => [],
    ];

    /**
     * interestCategories sub-array structure:
     * [
     *     'audienceId' => <string>,
     *     'interestCategories' => [
     *         [
     *             'category' => <array> API response,
     *             'interests' => <array> API response
     *         ]
     *     ],
     * ]
     */
    private array $mailchimpInterestCategories = [
        'audienceId' => null,
        'interestCategories' => [],
    ];

    /**
     * Process submitted form
     */
    public function processReady()
    {
        if (!$this->mailchimp_audience_id) {
            return;
        }

        $postData = $this->input->post->getArray();

        if (!$this->isSubmittableToMailchimp($postData)) {
            return;
        }

        $mailchimpData = $this->getMailchimpRequestData($postData);

        dd($mailchimpData);

        dd('fired', $this->input->post->getArray(), $this->fbForm->children, $mailchimpData, 'end');

        $result = $this->___subscribe($mailchimpData);

        dd($result);
    }

    /**
     * Submit subscription to Mailchimp
     */
    public function ___subscribe(array $subscriberData, ?string $audienceId = null): mixed
    {
        $audienceId ??= $this->mailchimp_audience_id;

        return $this->mailchimpClient()->post("lists/{$audienceId}/members", $subscriberData);
    }


    /**
     * Checks if there is a checkbox designated as an opt-in and whether this submission qualifies
     * Accepted checkbox truthy values: true, '1', 1, 'on', 'yes'
     */
    private function isSubmittableToMailchimp(array $postData): bool
    {
        $optInCheckbox = $this->{"{$this->mailchimp_audience_id}__form_opt_in_checkbox"};

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
    private function getMailchimpRequestData(array $postData): array
    {
        $audienceId = $this->mailchimp_audience_id;

        return array_filter([
            'email_address' => $postData[$this->{"{$audienceId}__email_address"}],
            'merge_fields' => $this->getSubmissionMergeFieldData($postData),
            'tags' => $this->{"{$audienceId}__audience_tags"},
        ]);
    }

    /**
     * Parses POST data for configured fields/data to submit to mailchimp
     */
    private function getSubmissionMergeFieldData(array $postData): array
    {
        $audienceId = $this->mailchimp_audience_id;

        // Pull merge tag/field configs
        $mergeTagConfigs = array_filter(
            $this->data,
            fn ($configName) => str_starts_with($configName, "{$audienceId}_merge_tag__"),
            ARRAY_FILTER_USE_KEY
        );

        $mergeTagConfigs = array_filter($mergeTagConfigs);

        // Remove merge tag config prefix
        $mergeTags = array_map(
            fn ($tag) => str_replace("{$audienceId}_merge_tag__", '', $tag),
            array_keys($mergeTagConfigs)
        );

        // Get and format values using the configured field names
        $fieldValues = array_map(
            fn ($fieldName) => $this->createMailchimpSubmissionValue($fieldName, $postData),
            array_values($mergeTagConfigs)
        );

        $mailchimpData = array_combine($mergeTags, $fieldValues);

        return array_filter($mailchimpData);
    }

    /**
     * Gets the Mailchimp appropriate value for a given submitted form value
     */
    private function createMailchimpSubmissionValue(string $fieldName, array $data): mixed
    {
        $fields = $this->fbForm->children;
        $value = $data[$fieldName] ?? null;

        // Unrecognized field or no value
        if (is_null($fields[$fieldName]) || !$value) {
            return null;
        }

        return match ($fields[$fieldName]->type) {
            'Page' => wire('pages')->get($value)?->title,
            'FormBuilderFile' => $value->httpUrl,
            default => $value,
        };
    }

    /**
     * Gets the field name to be used when submitting to Mailchimp
     */
    private function getMailchimpFieldName($fieldName): string
    {
        [
            'mailchimpFieldNameText' => $mailchimpFieldNameText,
        ] = $this->getMailchimpConfigFieldNames($fieldName);

        return $this->$mailchimpFieldNameText ?: $fieldName;
    }

    /**
     * Form processor configuration
     */
    public function getConfigInputfields(InputfieldWrapper $inputfields): InputfieldWrapper
    {
        parent::getConfigInputfields($inputfields);
        $modules = $this->wire()->modules;

        /**
         * Mailchimp Audience (list)
         */
        $audienceSelect = $modules->get('InputfieldSelect');
        $audienceSelect->attr('name', 'mailchimp_audience_id');
        $audienceSelect->label = __('Mailchimp Audience');
        $audienceSelect->description = __('Choose the Audience (list) submissions will be sent to');
        $audienceSelect->val($this->mailchimp_audience_id);
        $audienceSelect->collapsed = Inputfield::collapsedNever;
        $audienceSelect->themeBorder = 'hide';
        $audienceSelect->themeInputWidth = 'l';
        $audienceSelect->columnWidth = 100 / 3;

        $mailchimpAudiences = $this->getMailchimpAudiences()['lists'];

        $audienceSelect = array_reduce(
            $mailchimpAudiences,
            fn ($inputfield, $audience) => $inputfield->addOption($audience['id'], $audience['name']),
            $audienceSelect
        );

        // No audiences present in Mailchimp
        if (!count($mailchimpAudiences)) {
            $audienceSelect->required = false;

            $audienceSelect->notes = __(
                'At least one Audience must be created in Mailchimp to receive submissions'
            );
        }

        // No audience selected
        if (!$this->mailchimp_audience_id) {
            $audienceSelect->notes = __('Select a Mailchimp audience, save, then return here to configure');

            $inputfields->add($audienceSelect);

            return $inputfields;
        }

        $inputfields->add($audienceSelect);

        /**
         * Audience Tags
         */
        $audienceTagConfigName = "{$this->mailchimp_audience_id}__audience_tags";

        $tagsSelect = $modules->get('InputfieldAsmSelect');
        $tagsSelect->attr('name', $audienceTagConfigName);
        $tagsSelect->label = __('Audience Tags');
        $tagsSelect->description = __('Optional Mailchimp tags assigned to submissions from this form');
        $tagsSelect->attr('value', $this->$audienceTagConfigName);
        $tagsSelect->themeBorder = 'hide';
        $tagsSelect->collapsed = Inputfield::collapsedNever;
        $tagsSelect->showIf = "mailchimp_audience_id!=''";
        $tagsSelect->sortable = false;
        $tagsSelect->columnWidth = 100 / 3;

        $audienceTags = $this->getMailchimpTags($this->mailchimp_audience_id);

        foreach ($audienceTags as $audienceTag) {
            $tagsSelect->addOption($audienceTag['id'], $audienceTag['name']);
        }

        $inputfields->add($tagsSelect);

        /**
         * Opt-In Checkbox
         */
        $optInCheckboxSelectConfigName = "{$this->mailchimp_audience_id}__form_opt_in_checkbox";

        $optInCheckboxSelect = $modules->get('InputfieldSelect');
        $optInCheckboxSelect->attr('name', $optInCheckboxSelectConfigName);
        $optInCheckboxSelect->label = __('Opt-in checkbox field');
        $optInCheckboxSelect->description = __('Leave blank to send all submissions to Mailchimp');
        $optInCheckboxSelect->val($this->$optInCheckboxSelectConfigName);
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
            $optInCheckboxSelect->notes = __("The checked value must be one of: true, 'true', '1', 1, 'on', or 'yes'");
        }

        if (!$checkboxFields) {
            $optInCheckboxSelect->notes = __('Add one or more checkboxes to define an opt-in checkbox');
        }

        $optInCheckboxSelect = array_reduce(
            $checkboxFields,
            fn ($inputfield, $checkbox) => $inputfield->addOption($checkbox->name, $checkbox->label),
            $optInCheckboxSelect
        );

        $inputfields->add($optInCheckboxSelect);

        /**
         * Mailchimp Field Associations
         */
        $fieldAssociationFieldset = $modules->get('InputfieldFieldset');
        $fieldAssociationFieldset->label = __('Mailchimp fields');
        $fieldAssociationFieldset->collapsed = Inputfield::collapsedNever;
        $fieldAssociationFieldset->description = __(
            'Choose a form field to associate with each Mailchimp field, leave blank to exclude. Mailchimp information is displayed below each field.'
        );

        $mailchimpMergeFields = array_filter(
            $this->getMailchimpMergeFields($this->mailchimp_audience_id)['merge_fields'],
            fn ($mergeField) => $mergeField['type'] !== 'address'
        );

        $fieldAssociationFieldset->add(
            $this->createEmailMergeFieldConfiguration()
        );

        // For adding column fillers, account for email config field already added
        $fieldsAdded = 1;

        // Create configurations for each Mailchimp field
        foreach ($mailchimpMergeFields as $mergeField) {
            $mergeFieldConfiguration = $this->createMergeFieldConfiguration($mergeField);

            if (!$mergeFieldConfiguration) {
                continue;
            }

            $fieldAssociationFieldset->add($mergeFieldConfiguration);

            $fieldsAdded++;
        }

        // Add filler InputfieldFieldsets if needed to create evenly spaced columns
        $remainingCols = $fieldsAdded % 3;

        while ($remainingCols >= 0) {
            $fieldAssociationFieldset->add(
                $this->createConfigColumnFiller(100 / 3)
            );

            $remainingCols--;
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
            $this->getMailchimpMergeFields($this->mailchimp_audience_id)['merge_fields'],
            fn ($mergeField) => $mergeField['type'] === 'address'
        );

        foreach ($addressMergeFields as $mergeField) {
            $fieldAssociationFieldset->add(
                $this->createAddressMergeFieldsConfiguration($mergeField)
            );
        }

        $inputfields->add($fieldAssociationFieldset);

        /**
         * Mailchimp Group Associations
         */
        $interestCategoryAssociationFieldset = $modules->get('InputfieldFieldset');
        $interestCategoryAssociationFieldset->label = __('Mailchimp groups/interests');
        $interestCategoryAssociationFieldset->description = __(
            "Fields may be configured as groups which are referred to as 'group names' in Mailchimp. Group fields may be dropdowns, checkboxes, radio buttons, etc. When matching form fields to groups, ensure that values in your form fields match those noted as configured in Mailchimp. Leave blank to exclude."
        );
        $interestCategoryAssociationFieldset->collapsed = Inputfield::collapsedNever;

        $interestCategories = $this->getMailchimpInterestCategories($this->mailchimp_audience_id);

        $fieldsAdded = 0;

        foreach ($interestCategories as $interestCategory) {
            $interestCategoryAssociationFieldset->add(
                $this->createInterestCategoryConfiguration($interestCategory)
            );

            $fieldsAdded++;
        }

        // Add filler InputfieldFieldsets if needed to create evenly spaced columns
        $remainingCols = $fieldsAdded % 4;

        while ($remainingCols >= 0) {
            $interestCategoryAssociationFieldset->add(
                $this->createConfigColumnFiller(25)
            );

            $remainingCols--;
        }

        $inputfields->add($interestCategoryAssociationFieldset);

        return $inputfields;
    }

    /**
     * Create sets of fields to configure addresses
     */
    private function createAddressMergeFieldsConfiguration(array $mergeField): ?InputfieldFieldset
    {
        [
            'tag' => $mcTag,
            'name' => $mcName,
            'type' => $mcType,
            'required' => $mcRequired,
            'options' => $mcOptions,
        ] = $mergeField;

        if ($mcType !== 'address') {
            return null;
        }

        $fieldset = $this->wire()->modules->InputfieldFieldset;
        $fieldset->label = $mcName;
        $fieldset->description = __('Address fields are limited to 45 characters.');
        $fieldset->collapsed = Inputfield::collapsedNever;
        $fieldset->themeBorder = 'hide';
        $fieldset->notes = $this->createInputfieldNotesFromMergeFieldOptions($mcOptions);
        $fieldset->themeColor = 'none';

        $configFieldNameBase = "{$this->mailchimp_audience_id}_address_merge_tag-{$mcTag}";

        $fieldNames = (object) [
            'addr1' => "{$configFieldNameBase}-addr1",
            'addr2' => "{$configFieldNameBase}-addr2",
            'city' => "{$configFieldNameBase}-city",
            'state' => "{$configFieldNameBase}-state",
            'zip' => "{$configFieldNameBase}-zip",
            'country' => "{$configFieldNameBase}-country",
        ];

        $columnWidth = 100 / 3;

        // Street Address
        $addr1Config = $this->createFormFieldSelect($fieldNames->addr1, __('Street Address'), [
            'columnWidth' => $columnWidth,
            'notes' => __('Required by Mailchimp'),
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
        ]);

        $fieldset->add($cityConfig);

        // State
        $stateConfig = $this->createFormFieldSelect($fieldNames->state, __('State/Prov/Region'), [
            'columnWidth' => $columnWidth,
            'notes' => __('Required by Mailchimp'),
        ]);

        $fieldset->add($stateConfig);

        // Postal/Zip
        $zipConfig = $this->createFormFieldSelect($fieldNames->zip, __('Postal/Zip'), [
            'columnWidth' => $columnWidth,
            'notes' => __('Required by Mailchimp'),
        ]);

        $fieldset->add($zipConfig);

        // Country
        $countryConfig = $this->createFormFieldSelect($fieldNames->country, __('Country'), [
            'columnWidth' => $columnWidth,
        ]);

        $fieldset->add($countryConfig);

        return $fieldset;
    }

    /**
     * Creates specific configuration field for email address required by Mailchimp
     */
    private function createEmailMergeFieldConfiguration(): ?InputfieldSelect
    {
        $fieldName = "{$this->mailchimp_audience_id}__email_address";
        $fieldLabel = __('Email Address');

        return $this->createFormFieldSelect($fieldName, $fieldLabel, [
            'columnWidth' => 25,
            'required' => true,
            'notes' => __('Required by Mailchimp'),
        ]);
    }

    /**
     * Create a Mailchimp/form field association config for the given form field
     */
    private function createMergeFieldConfiguration(array $mergeField): ?InputfieldSelect
    {
        [
            'tag' => $mcTag,
            'name' => $mcName,
            'required' => $mcRequired,
            'options' => $mcOptions,
        ] = $mergeField;

        $fieldName = "{$this->mailchimp_audience_id}_merge_tag__{$mcTag}";

        return $this->createFormFieldSelect($fieldName, $mcName, [
            'columnWidth' => 25,
            'required' => $mcRequired,
            'notes' => $this->createInputfieldNotesFromMergeFieldOptions($mcOptions),
        ]);
    }

    /**
     * Create a Mailchimp/form field association config for the given form field
     */
    private function createInterestCategoryConfiguration(array $interestCategory): ?InputfieldSelect
    {
        ['category' => $mcCategory, 'interests' => $mcInterests] = $interestCategory;

        [
            'id' => $mcCategoryId,
            'type' => $mcCategoryType,
            'title' => $mcCategoryTitle
        ] = $mcCategory;

        $fieldName = "{$this->mailchimp_audience_id}_interest_category__{$mcCategoryId}";

        // Create notes
        $interestNames = array_map(fn ($interest) => $interest['name'], $mcInterests['interests']);

        $notes = implode('. ', [
            "Type: {$mcCategoryType}",
            'Values: ' . implode(', ', $interestNames),
        ]);

        return $this->createFormFieldSelect($fieldName, $mcCategoryTitle, [
            'columnWidth' => 100 / 3,
            'notes' => $notes,
        ]);
    }

    /**
     * Creates a select inputfield with options for each form field
     */
    private function createFormFieldSelect(
        string $name,
        string $label,
        array $configs
    ): InputfieldSelect {
        $fieldSelect = $this->wire()->modules->InputfieldSelect;
        $fieldSelect->attr('name', $name);
        $fieldSelect->label = $label;
        $fieldSelect->val($this->$name);
        $fieldSelect->collapsed = Inputfield::collapsedNever;
        $fieldSelect->themeBorder = 'hide';

        foreach ($configs as $configName => $configValue) {
            $fieldSelect->$configName = $configValue;
        }

        $options = array_reduce(
            $this->fbForm->children,
            fn ($options, $field) => $options = [$field->name => $field->label, ...$options],
            []
        );

        asort($options);

        $fieldSelect->addOptions($options);

        return $fieldSelect;
    }

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
     * Creates a nice column filler for config field layouts
     */
    private function createConfigColumnFiller($width): InputfieldFieldset
    {
        $filler = $this->modules->InputfieldFieldset;
        $filler->attr('style', $filler->attr('style') . ' visibility: hidden !important;');
        $filler->wrapAttr('style', $filler->attr('style') . ' visibility: hidden !important;');
        $filler->columnWidth = $width;
        $filler->themeBorder = 'hide';

        return $filler;
    }

    /**
     * Module config fields
     */
    public function getModuleConfigInputfields(InputfieldWrapper $inputfields): InputfieldWrapper
    {
        $modules = $this->modules;

        $apiKey = $modules->get('InputfieldText');
        $apiKey->name = 'mailchimp_api_key';
        $apiKey->label = __('Mailchimp API Key');
        $apiKey->description = __('An API key entered here will be used globally for all FormBuilder forms but may be overridden when configuring each form.');
        $apiKey->notes = __(' If this field is left blank, an API key will be required when configuring each form');
        $apiKey->value = $this->mailchimp_api_key ?? null;
        $apiKey->collapsed = Inputfield::collapsedNever;

        $inputfields->add($apiKey);

        return $inputfields;
    }

    /**
     * Mailchimp API
     */

    /**
     * Create the Mailchimp API client
     */
    public function mailchimpClient(?string $apiKey = null): MailChimp
    {
        if ($this->mailchimpClient) {
            return $this->mailchimpClient;
        }

        return $this->mailchimpClient = new MailChimp($apiKey ?? $this->mailchimp_api_key);
    }

    /**
     * Get all Mailchimp audiences (lists)
     * Returns the API response body
     */
    private function getMailchimpAudiences(): array
    {
        if (count($this->mailchimpAudiences)) {
            return $this->mailchimpAudiences;
        }

        return $this->mailchimpAudiences = $this->mailchimpClient()->get('lists');
    }

    /**
     * Gets the merge fields for a given audience ID
     * Memoizes results by audience ID
     * Returns the API response body
     */
    private function getMailchimpMergeFields(string $audienceId): array
    {
        [
            'audienceId' => $fetchedAudienceId,
            'mergeFields' => $fetchedMergeFields,
        ] = $this->mailchimpMergeFields;

        if (count($fetchedMergeFields) && $audienceId === $fetchedAudienceId) {
            return $fetchedMergeFields;
        }

        $mergeFields = $this->mailchimpClient()->get("lists/{$audienceId}/merge-fields");

        $this->mailchimpMergeFields = ['audienceId' => $audienceId, 'mergeFields' => $mergeFields];

        return $mergeFields;
    }

    /**
     * Gets available tags for a given audience ID
     */
    private function getMailchimpTags(string $audienceId): array
    {
        $segments = $this->getMailchimpSegments($audienceId)['segments'];

        return array_filter($segments, fn ($segment) => $segment['type'] === 'static');
    }

    /**
     * Gets segments for a given audience ID
     * Memoizes data by audience ID
     */
    private function getMailchimpSegments(string $audienceId): array
    {
        [
            'audienceId' => $fetchedAudienceId,
            'segments' => $fetchedSegments,
        ] = $this->mailchimpSegments;

        if (count($fetchedSegments) && $audienceId === $fetchedAudienceId) {
            return $fetchedSegments;
        }

        $segments = $this->mailchimpClient()->get("lists/{$audienceId}/segments");

        $this->mailchimpSegments = ['audienceId' => $audienceId, 'segments' => $segments];

        return $segments;
    }

    /**
     * Gets interest categories for a given audience ID
     * Memoizes data by audience ID
     */
    private function getMailchimpInterestCategories(string $audienceId): array
    {
        [
            'audienceId' => $fetchedAudienceId,
            'interestCategories' => $fetchedInterestCategories,
        ] = $this->mailchimpInterestCategories;

        if (count($fetchedInterestCategories) && $audienceId === $fetchedAudienceId) {
            return $fetchedInterestCategories;
        }

        $interestCategories = [];

        $categories = $this->mailchimpClient()->get("lists/{$audienceId}/interest-categories");

        foreach ($categories['categories'] as $category) {
            // Get the interests link from the response to call API
            $interestsEndpoint = array_reduce($category['_links'], function($match, $link) {
                if ($link['rel'] !== 'interests') {
                    return $match;
                }

                return $match = explode('3.0/', $link['href'])[1];
            });

            $interestCategories[] = [
                'category' => $category,
                'interests' => $this->mailchimpClient()->get($interestsEndpoint),
            ];
        }

        $this->mailchimpInterestCategories = [
            'audienceId' => $audienceId,
            'interestCategories' => $interestCategories
        ];

        return $interestCategories;
    }

}
