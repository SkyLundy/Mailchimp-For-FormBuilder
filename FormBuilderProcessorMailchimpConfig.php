<?php

namespace ProcessWire;

wire('classLoader')->addNamespace('FormBuilderProcessorMailchimp\App', __DIR__ . '/app');

use Exception;
use FormBuilderProcessorMailchimp\App\MailchimpClient;

class FormBuilderProcessorMailchimpConfig extends ModuleConfig
{
    /**
     * {@inheritdoc}
     *
     * local_audience_tags - Mailchimp creates new Audience Tags on first subscription via the API
     *                       this may cause a divergence in local tags created vs. what is returned
     *                       by the Mailchimp API. This property holds tags configured in FormBuilder
     *                       forms but not yet submitted, stored globally so that they can be
     *                       shared across all forms prior to being submitted to Mailchimp
     */
    public function getDefaults(): array
    {
        return [
            'mailchimp_api_key' => null,
            'mailchimp_api_ready' => false,
            'local_audience_tags' => [],
        ];
    }

    /**
     * Save keys/values to module config
     *
     * @param  array ...$newConfigData Named arguments used as config array keys
     */
    public function saveModuleConfig(...$newConfigData): void
    {
        $this->modules->saveConfig('FormBuilderProcessorMailchimp', [
            ...(array) $this->getModuleConfig(),
            ...$newConfigData
        ]);
    }

    /**
     * Get module config as an object containing all set and default values
     *
     * @return object Config as an object
     */
    public function getModuleConfig(): object
    {
        return (object) [
            ...$this->getDefaults(),
            ...$this->modules->getConfig('FormBuilderProcessorMailchimp')
        ];
    }

    /**
     * Module Configuration
     */
    public function getInputfields(): InputfieldWrapper
    {
        $inputfields = parent::getInputfields();
        $config = $this->getModuleConfig();

        $inputfields->add([
            'mailchimp_api_key' => [
                'type' => 'InputfieldText',
                'label' => __('Mailchimp API Key'),
                'collapsed' => Inputfield::collapsedNever,
                'required' => true,
            ]
        ]);

        if ($config->mailchimp_api_key) {
            $headers = null;

            try {
                $mailchimpClient = MailchimpClient::init($config->mailchimp_api_key);

                $mailchimpClient->getAudiences();

                $headers = $mailchimpClient->mailchimp->getLastResponse()['headers'];
            } catch (Exception $e) {
                $this->wire->error($e->getMessage());

                return $inputfields;
            }

            $httpCode = $headers['http_code'];

            if ($httpCode === 401) {
                $this->wire->error(
                    __('Mailchimp API key invalid')
                );

                $this->saveModuleConfig(mailchimp_api_ready: false);
            }

            if ($httpCode !== 200 && $httpCode !== 401) {
                $this->wire->error(
                    __('An error occured while attempting to validate the API key')
                );

                $this->saveModuleConfig(mailchimp_api_ready: false);
            }

            if ($httpCode === 200) {
                $this->saveModuleConfig(mailchimp_api_ready: true);
            }
        }

        return $inputfields;
    }
}
