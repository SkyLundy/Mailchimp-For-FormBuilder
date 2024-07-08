<?php

namespace ProcessWire;

wire('classLoader')->addNamespace('FormBuilderProcessorMailchimp\App', __DIR__ . 'app');

use FormBuilderProcessorMailchimp\App\MailChimp;

class FormBuilderProcessorMailchimp extends FormBuilderProcessorAction implements Module, ConfigurableModule
{
    /**
     * Process submitted form
     */
    public function processReady()
    {
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

        if ($invalidField) {
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

        return $this->___initMailchimp($apiKey)->post("lists/{$audienceId}/members", $subscriberData);
    }

    /**
     * Create the Mailchimp API client
     */
    public function ___initMailchimp(?string $apiKey = null): MailChimp
    {
        !$apiKey && $apiKey = $this->form_api_key ?: $this->global_api_key;

        return new MailChimp($apiKey);
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

        // Opt-In checkbox field
        $optInCheckboxSelect = $modules->get('InputfieldSelect');
        $optInCheckboxSelect->attr('name', 'form_opt_in_checkbox');
        $optInCheckboxSelect->label = __('Opt-in checkbox field');
        $optInCheckboxSelect->description = __('If no opt-in checkbox is specified, all submissions be sent to Mailchimp');
        $optInCheckboxSelect->val($this->form_opt_in_checkbox);
        $optInCheckboxSelect->collapsed = Inputfield::collapsedNever;

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

        foreach ($checkboxFields as $checkboxField) {
            $optInCheckboxSelect->addOption($checkboxField->name, $checkboxField->label);
        }

        $inputfields->add($optInCheckboxSelect);

        // Field associations fieldset
        $fieldAssociationFieldset = $modules->get('InputfieldFieldset');
        $fieldAssociationFieldset->attr('name', 'field_associations');
        $fieldAssociationFieldset->label = __('Form/Mailchimp field associations');
        $fieldAssociationFieldset->description = __('A field associated with email_address is required. Leave the field blank if the form field matches the Mailchimp field name.');
        $fieldAssociationFieldset->collapsed = Inputfield::collapsedNever;
        $fieldAssociationFieldset->notes = __('File upload fields are excluded from Mailchimp processing');

        foreach ($this->fbForm->children as $field) {
            $fieldAssociationConfig = $this->createFormFieldConfiguration($field);

            if (!$fieldAssociationConfig) {
                continue;
            }

            $fieldAssociationFieldset->add($fieldAssociationConfig);
        }

        // Add filler InputfieldFieldsets if needed to create evenly spaced columns
        $remainingCols = count($this->fbForm->children) % 4;

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

        // Specify a Mailchimp API key for this form
        $field = $modules->get('InputfieldText');
        $field->attr('name', 'form_api_key');
        $field->label = __('API Key');
        $field->required = !$this->global_api_key || (!$this->global_api_key && !$this->form_api_key);
        $field->val($this->form_api_key);
        $field->columnWidth = 50;
        $field->collapsed = Inputfield::collapsedBlank;

        if ($this->global_api_key) {
            $apiKeyEndChars = substr($this->global_api_key, -12);

            $field->placeholder = "XXXXXXXXXXXXXXXXXXXXXXXXX{$apiKeyEndChars}";

            $field->description = __(
                "Leave blank to use the globally configured API key, or specify one for this form"
            );
        }

        $inputfields->add($field);

        // Specify a Mailchimp Audience ID for this form
        $field = $modules->get('InputfieldText');
        $field->attr('name', 'form_audience_id');
        $field->label = __('Audience ID');
        $field->required = !$this->global_api_key || (!$this->global_api_key && !$this->form_api_key);
        $field->val($this->form_audience_id);
        $field->columnWidth = 50;
        $field->collapsed = Inputfield::collapsedBlank;

        if ($this->global_audience_id) {
            $field->placeholder = $this->global_audience_id;
            $field->description = __(
                "Leave blank to use the globally configured Audience ID, or specify one for this form"
            );
        }

        $inputfields->add($field);

        return $inputfields;
    }

    /**
     * Create a form/Mailchimp field association config for the given form field
     */
    private function createFormFieldConfiguration(FormBuilderField $field): ?InputfieldFieldset
    {
        if (!$this->isValidMailchimpField($field)) {
            return null;
        }

        $modules = $this->wire()->modules;
        $fieldName = $field->name;
        $fieldLabel = $field->label;

        $fieldset = $modules->get('InputfieldFieldset');
        $fieldset->attr('name', "field_configs_{$fieldName}");
        $fieldset->label = $fieldLabel;
        $fieldset->themeBorder = 'hide';
        $fieldset->collapsed = Inputfield::collapsedNever;
        $fieldset->columnWidth = 25;

        [
            'includeFieldCheckbox' => $includeFieldCheckbox,
            'mailchimpFieldNameText' => $mailchimpFieldNameText,
        ] = $this->getMailchimpConfigFieldNames($fieldName);

        // Include this field in Mailchimp subscription submissions
        $field = $modules->get('InputfieldCheckbox');
        $field->attr('name', $includeFieldCheckbox);
        $field->label = 'Submit?';
        $field->checked = $this->$includeFieldCheckbox;
        $field->themeBorder = 'hide';
        $field->collapsed = Inputfield::collapsedNever;

        $fieldset->add($field);

        // Name of Mailchimp field to associate with FormBuilder field
        $field = $modules->get('InputfieldText');
        $field->attr('name', $mailchimpFieldNameText);
        $field->label = 'Mailchimp field';
        $field->attr('value', $this->$mailchimpFieldNameText);
        $field->showIf = "FormBuilderProcessorMailchimp_{$includeFieldCheckbox}=1";
        $field->placeholder = $fieldName;
        $field->themeBorder = 'hide';
        $field->collapsed = Inputfield::collapsedNever;

        $fieldset->add($field);

        return $fieldset;
    }

    /**
     * Creates thefield names for a given Mailchimp config
     */
    private function getMailchimpConfigFieldNames(string $fieldName): array
    {
        return [
            'includeFieldCheckbox' => "{$fieldName}_mailchimp_submit_field",
            'mailchimpFieldNameText' => "{$fieldName}_mailchimp_field_name",
        ];
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
            ],
            'global_audience_id' => [
                'type' => 'InputfieldText',
                'collapsed' => Inputfield::collapsedNever,
                'label' => __('Audience ID'),
                'description' => __('A Mailchimp Audience ID may be entered here to be used for all forms. This ID can be overridden when configuring each form'),
                'notes' => __(' If this field is left blank, an Audience ID will be required when configuring each form'),
                'value' => $this->global_audience_id ?? null,
                'columnWidth' => 50,
            ],
        ]);

        $inputfields->add($fieldset);

        return $inputfields;
    }
}
