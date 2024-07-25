<?php

declare(strict_types=1);

namespace FormBuilderProcessorMailchimp\App;

use ProcessWire\{
    FormBuilder,
    FormBuilderProcessorMailchimp,
    FormBuilderProcessorMailchimpConfig,
};
use stdClass;

class DataMaintenance
{
    private function __construct(
        private stdClass $mailchimpData,
        private FormBuilder $formBuilder,
        private FormBuilderProcessorMailchimp $mailchimpProcessor,
        private FormBuilderProcessorMailchimpConfig $mailchimpProcessorConfig,
    ) {}

    /**
     * Initialize a new DataMainenance instance
     */
    public static function init(
        stdClass $mailchimpData,
        FormBuilder $formBuilder,
        FormBuilderProcessorMailchimp $mailchimpProcessor,
        FormBuilderProcessorMailchimpConfig $mailchimpProcessorConfig,
    ): self {
        return new self(
            $mailchimpData,
            $formBuilder,
            $mailchimpProcessor,
            $mailchimpProcessorConfig
        );
    }

    public function executeAll(): void
    {
        $this->updateAudienceTags();
    }

    /**
     * Purges local Audience Tags stored in FormBuilderProcessorMailchimpCongig
     *
     * Local tags are those that are created by users when creating/modifying Mailchimp integrations
     * but which have notbeen sent to and created in Mailchimp yet. Audience Tags are not created in
     * Mailchimp until the first successful subscription using that tag. Audience Tags can't be
     * created via the Mailchimp API.
     *
     * - Purges local Audience Tags that exist in Mailchimp
     * - Purges local Audience Tags no longer in use by any FormBuilder form MC processor
     *
     * @return void
     */
    public function updateAudienceTags(): void
    {
        $fbForms = $this->formBuilder->forms();

        // All MC processor configs
        $formConfigs = array_map(
            fn ($name) => $fbForms->get($name)->getInputfield()->get('FormBuilderProcessorMailchimp'),
            $fbForms->getFormNames()
        );

        // Tags in use by each form using FB MC processor
        $tagsInUse = array_reduce($formConfigs, function($combinedConfigs, $formConfig) {
            if (!$formConfig) {
                return $combinedConfigs;
            }

            $tagConfigs = array_filter(
                $formConfig,
                fn ($key) => str_ends_with($key, '__audience_tags'),
                ARRAY_FILTER_USE_KEY
            );

            return $combinedConfigs = array_unique([...$combinedConfigs, ...end($tagConfigs)]);
        }, []);

        // All MC tag names
        $mailchimpTags = array_map(fn ($tag) => $tag->name, $this->mailchimpData->audienceTags);

        // Local tags not in Mailchimp
        $localTags = array_diff($tagsInUse, $mailchimpTags);

        $this->mailchimpProcessorConfig->saveModuleConfig(local_audience_tags: $localTags);
    }
}