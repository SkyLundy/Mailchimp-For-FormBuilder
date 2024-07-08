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

        // Process POST submission data and filter to only fields/values submitted to Mailchimp
        $formData = array_filter(
            $postData,
            fn ($key) =>  $this->shouldSubmitFormBuilderFieldToMailchimp($key),
            ARRAY_FILTER_USE_KEY
        );

        // Convert submitted field names to those configured for Mailchimp
        $fieldNames = array_map(
            fn ($fieldName) => $this->getMailchimpFieldName($fieldName),
            array_keys($formData)
        );

        // Convert values to Mailchimp ready values
        array_walk(
            $formData,
            fn (&$value, $name) => $value = $this->createMailchimpRequestValue($name, $value)
        );

        $mailchimpData = array_combine(
            $fieldNames,
            array_values($formData)
        );

        $formData = array_filter($mailchimpData);

        dd('fired', $this->input->post->getArray(), $this->fbForm->children, $mailchimpData, 'end');

        $result = $this->___subscribe($mailchimpData);

        dd($result);
    }

    /**
     * Checks if there is a checkbox designated as an opt-in and whether this submission qualifies
     * Accepted checkbox truthy values: true, '1', 1, 'on', 'yes'
     */
    private function isSubmittableToMailchimp(array $postData): bool
    {
        $optInCheckbox = $this->form_opt_in_checkbox;

        if (!$optInCheckbox) {
            return true;
        }

        if ($optInCheckbox && !array_key_exists($optInCheckbox, $postData)) {
            return false;
        }

        return filter_var($postData[$optInCheckbox], FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Determines if a submitted FormBuilder field/value should be submitted to Mailchimp
     */
    private function shouldSubmitFormBuilderFieldToMailchimp(string $fieldName): bool
    {
        // Exclude FormBuilder specific POST values
        $invalidField =  str_ends_with($fieldName, '_submit') &&
                         str_starts_with($fieldName, 'TOKEN') &&
                         $fieldName !== '_submitKey' &&
                         $fieldName !== '_InputfieldForm' &&
                         $fieldName !== 'MAX_FILE_SIZE';

        if ($invalidField || $fieldName === $this->form_opt_in_checkbox) {
            return false;
        }

        [
            'includeFieldCheckbox' => $includeFieldCheckbox
        ] = $this->getMailchimpConfigFieldNames($fieldName);

        return !!$this->$includeFieldCheckbox;
    }

    /**
     * Submit subscription to Mailchimp
     */
    public function ___subscribe(
        array $subscriberData,
        ?string $apiKey = null,
        ?string $audienceId = null
    ): mixed {
        !$audienceId && $audienceId = $this->form_audience_id ?: $this->global_audience_id;

        return $this->mailchimpClient()->post("lists/{$audienceId}/members", $subscriberData);
    }

    /**
     * Create the Mailchimp API client
     */
    public function mailchimpClient(?string $apiKey = null): MailChimp
    {
        if ($this->mailchimpClient) {
            return $this->mailchimpClient;
        }

        !$apiKey && $apiKey = $this->form_api_key ?: $this->global_api_key;

        return $this->mailchimpClient = new MailChimp($apiKey);
    }

    /**
     * Gets the Mailchimp appropriate value for a given submitted form value
     */
    private function createMailchimpRequestValue(
        string $fieldName,
        mixed $value,
        ?FormBuilderForm $form = null
    ): mixed {
        $form ??= $this->fbForm;
        $fields = $form->children;

        // Unrecognized field or no value
        if (is_null($fields[$fieldName]) || !$value) {
            return null;
        }

        if ($fields[$fieldName]->type === 'Page') {
            return wire('pages')->get($value)?->title;
        }

        if (is_array($value)) {
            return implode(', ', $value);
        }

        return $value;
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

$result = $this->getMailchimpSegments($this->mailchimp_audience_id);

// $result = $this->mailchimpClient()->get('lists/d221b5a07a/interest-categories');
// $result = $this->mailchimpClient()->get('lists/d221b5a07a/merge-fields');
// $result = $this->mailchimpClient()->get('lists/d221b5a07a/signup-forms');
// var_dump(json_encode($this->mailchimpClient()->get('lists')));
//
var_dump(json_encode($result));
die;
        $audienceSelect = $modules->get('InputfieldSelect');
        $audienceSelect->attr('name', 'mailchimp_audience_id');
        $audienceSelect->label = __('Mailchimp Audience');
        $audienceSelect->description = __('Choose the Audience (list) submissions will be sent to');
        $audienceSelect->val($this->mailchimp_audience_id);
        $audienceSelect->collapsed = Inputfield::collapsedNever;
        $audienceSelect->themeBorder = 'hide';
        $audienceSelect->themeInputWidth = 'l';

        $mailchimpAudiences = $this->mailchimpClient()->get('lists')['lists'] ?: [];

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

        // Check if audience has changed
        $previouslySelectedAudience = $this->page->meta()->get('previously_selected_audience');

        $audienceChanged = $previouslySelectedAudience !== $this->mailchimp_audience_id;

        if ($audienceChanged) {
          $this->page->meta()->set('previously_selected_audience', $this->mailchimp_audience_id);
        }

        // Opt-In checkbox field
        $optInCheckboxSelect = $modules->get('InputfieldSelect');
        $optInCheckboxSelect->attr('name', 'form_opt_in_checkbox');
        $optInCheckboxSelect->label = __('Opt-in checkbox field');
        $optInCheckboxSelect->description = __('If no opt-in checkbox is specified, all submissions will be sent to Mailchimp');
        $optInCheckboxSelect->val($this->form_opt_in_checkbox);
        $optInCheckboxSelect->collapsed = Inputfield::collapsedNever;
        $optInCheckboxSelect->themeBorder = 'hide';
        $optInCheckboxSelect->showIf = "mailchimp_audience_id!=''";
        $optInCheckboxSelect->themeInputWidth = 'l';

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

        // Field associations fieldset
        $fieldAssociationFieldset = $modules->get('InputfieldFieldset');
        $fieldAssociationFieldset->attr('name', 'field_associations');
        $fieldAssociationFieldset->label = __('Mailchimp/form field associations');
        $fieldAssociationFieldset->description = __(
            'Choose a form field to associate with each Leave association blank to exclude it from Mailchimp submissions'
        );
        $fieldAssociationFieldset->collapsed = Inputfield::collapsedNever;
        $fieldAssociationFieldset->notes = __('File upload fields are excluded from Mailchimp processing');

        $mailchimpMergeFields = $this->getMailchimpMergeFields($this->mailchimp_audience_id)['merge_fields'];

        // Create configurations for each Mailchimp field
        foreach ($mailchimpMergeFields as $mergeField) {
            $mergeFieldConfiguration = $this->createMergeFieldConfiguration($mergeField);

            if (!$mergeFieldConfiguration) {
                continue;
            }

            $fieldAssociationFieldset->add($mergeFieldConfiguration);
        }

        // Add filler InputfieldFieldsets if needed to create evenly spaced columns
        $remainingCols = count($mailchimpMergeFields) % 4;

        while ($remainingCols >= 0) {
            $filler = $this->modules->InputfieldFieldset;
            $filler->attr('style', $filler->attr('style') . ' visibility: hidden !important;');
            $filler->wrapAttr('style', $filler->attr('style') . ' visibility: hidden !important;');
            $filler->columnWidth = 25;
            $filler->themeBorder = 'hide';
            $filler->themeColor = 'none';

            $fieldAssociationFieldset->add($filler);

            $remainingCols--;
        }

        $inputfields->add($fieldAssociationFieldset);

        return $inputfields;
    }

    /**
     * Create a form/Mailchimp field association config for the given form field
     */
    private function createMergeFieldConfiguration(array $mergeField): ?InputfieldSelect
    {
        [
            'tag' => $mcTag,
            'name' => $mcName,
            'type' => $mcType,
            'required' => $mcRequired,
            'options' => $mcOptions,
        ] = $mergeField;

        if (!$this->mailchimp_audience_id || $mcType === 'address') {
            return null;
        }


        $modules = $this->wire()->modules;

        $configFieldName = "{$this->mailchimp_audience_id}_{$mcTag}";

        // Field association select
        $mergeFieldConfig = $modules->get('InputfieldSelect');
        $mergeFieldConfig->attr('name', $configFieldName);
        $mergeFieldConfig->label = $mcName;
        $mergeFieldConfig->val($this->$configFieldName);
        $mergeFieldConfig->collapsed = Inputfield::collapsedNever;
        $mergeFieldConfig->themeBorder = 'hide';
        $mergeFieldConfig->columnWidth = 25;
        $mergeFieldConfig->required = $mcRequired;

        // Include any special notes depending on field

        $notes = [];

        foreach ($mcOptions as $name => $value) {
            $name = preg_replace('/[-_]/', ' ', $name);
            $name = ucfirst($name);

            is_array($value) && $value = implode(', ', $value);

            $notes[] = "{$name}: $value";
        }

        $mergeFieldConfig->notes = implode('. ', $notes);

        $formFields = $this->fbForm->children;

        foreach ($formFields as $formField) {
            $mergeFieldConfig->addOption($formField->name, $formField->label);
        }

        return $mergeFieldConfig;







        // [
        //     'includeFieldCheckbox' => $includeFieldCheckbox,
        //     'mailchimpFieldNameText' => $mailchimpFieldNameText,
        // ] = $this->getMailchimpConfigFieldNames($fieldName);

        // // Include this field in Mailchimp subscription submissions
        // $field = $modules->get('InputfieldCheckbox');
        // $field->attr('name', $includeFieldCheckbox);
        // $field->label = 'Submit?';
        // $field->checked = $this->$includeFieldCheckbox;
        // $field->themeBorder = 'hide';
        // $field->collapsed = Inputfield::collapsedNever;

        // $fieldset->add($field);

        // // Name of Mailchimp field to associate with FormBuilder field
        // $field = $modules->get('InputfieldText');
        // $field->attr('name', $mailchimpFieldNameText);
        // $field->label = 'Mailchimp field';
        // $field->attr('value', $this->$mailchimpFieldNameText);
        // $field->showIf = "FormBuilderProcessorMailchimp_{$includeFieldCheckbox}=1";
        // $field->placeholder = $fieldName;
        // $field->themeBorder = 'hide';
        // $field->collapsed = Inputfield::collapsedNever;

        // $fieldset->add($field);

        // return $fieldset;
    }

    /**
     * Creates thefield names for a given Mailchimp config
     */
    private function getMailchimpFieldConfigName(string $fieldName): string
    {
        return "{$this->mailchimp_audience_id}_field_{$fieldName}";
    }

    /**
     * Determines if a field is valid for submission to Mailchimp
     */
    private function isValidMailchimpField(FormBuilderField $field): bool
    {
        return match ($field->type) {
            'FormBuilderFile' => false,
            default => true,
        };
    }

    /**
     * Module config fields
     */
    public function getModuleConfigInputfields(InputfieldWrapper $inputfields): InputfieldWrapper
    {
        $modules = $this->modules;

        $fieldset = $modules->InputfieldFieldset;
        $fieldset->label = __('Global Mailchip Configuration');
        $fieldset->collapsed = Inputfield::collapsedNever;
        $fieldset->add([
            'global_api_key' => [
                'type' => 'InputfieldText',
                'collapsed' => Inputfield::collapsedNever,
                'label' => __('Mailchimp API Key'),
                'description' => __('An API key entered here will be used globally for all FormBuilder forms but may be overridden when configuring each form.'),
                'notes' => __(' If this field is left blank, an API key will be required when configuring each form'),
                'value' => $this->global_api_key ?? null,
                'columnWidth' => 50,
            ]
        ]);

        $inputfields->add($fieldset);

        return $inputfields;
    }

    /**
     * Memoizing API Data methods
     */

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
     * Gets segments for a given audience ID
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

        $segments = $this->mailchimpClient()->get("/lists/{$audienceId}/segments");

        $this->mailchimpSegments = ['audienceId' => $audienceId, 'segments' => $segments];

        return $segments;
    }
}
