<?php

namespace ProcessWire;

wire('classLoader')->addNamespace('FormBuilderProcessorMailchimp\App', __DIR__ . '/app');

use FormBuilderProcessorMailchimp\App\MailChimpClient;

class FormBuilderProcessorMailchimpConfig extends ModuleConfig
{
    /**
     * {@inheritdoc}
     */
    public function getDefaults(): array
    {
        return [
            'mailchimp_api_key' => null,
            'mailchimp_api_ready' => false,
        ];
    }

    /**
     * Internal module use only
     *
     * @param  array ...$newConfigData Named arguments
     */
    private function saveModuleConfig(...$newConfigData): void
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
    private function getModuleConfig(): object
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

        $apiKey = $this->modules->get('InputfieldText');
        $apiKey->name = 'mailchimp_api_key';
        $apiKey->label = __('Mailchimp API Key');
        $apiKey->collapsed = Inputfield::collapsedNever;
        $apiKey->required = true;

        $inputfields->add($apiKey);

        if ($config->mailchimp_api_key) {
            $mailChimpClient = MailChimpClient::init($config->mailchimp_api_key);

            $mailChimpClient->getAudiences();

            $httpCode = $mailChimpClient->mailChimp->getLastResponse()['headers']['http_code'];

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

    /**
     * Mailchimp API
     */

    /**
     * Create the Mailchimp API client
     */
    public function mailChimpClient(?string $apiKey = null): MailChimp
    {
        if ($this->mailChimpClient) {
            return $this->mailChimpClient;
        }

        return $this->mailChimpClient = new MailChimp($apiKey);
    }
}
