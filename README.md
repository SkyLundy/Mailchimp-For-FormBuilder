# FormBuilder Mailchimp Processor

A highly configurable add-on for the ProcessWire FormBuilder module that adds Mailchimp list subscriptions to new and existing forms.

This is alpha software. Please test thoroughly and file issues if you encounter bugs.

## Features

- Add Mailchimp processing to new or existing forms.
- Specify which form fields will be submitted to Mailchimp
- Optionally include and configure an 'opt-in' checkbox for your forms
- Can be added as an additional processing method alongside existing FormBuilder form actions
- Submit subscriber information to any audience/list in Mailchimp
- Use audience tags configured in Mailchimp to organize contacts as they're added to Mailchimp audiences

Fields are automatically converted to Mailchimp-friendly values where necessary, datetime field values are automatically converted to the format configured in Mailchimp.

All fieldtypes with the exception of image/file upload fields are supported.

## Requirements

- [ProcessWire](https://processwire.com/) >= 3.0
- [FormBuilder](https://processwire.com/store/form-builder/) >= 0.5.5 (untested with other versions but should work)
- PHP >= 8.1
- PHP CURL library

## Installation

Download the .zip from the Github repository, unzip into /site/modules, install module Admin

## Usage

- Install module
- Add API key on the module configuration page
- Use the 'Actions' tab when configuring a form to add Mailchimp processing
- Select a Mailchimp Audience, save, then return to the action configuration to continue setting up your Mailchimp integration.

Each field in Mailchimp audience will be displayed along with a select element to associate a form field. Fields that are required in Mailchimp are required when configuring your integration. Associate all fields, or only the fields you need.

Some field association select inputs may display notes that can help you configure your form to work with Mailchimp. These may include accepted values for Mailchimp radio/select/checkbox fields, formatting, or character limits. Ensure that your fields submit data that Mailchimp will accept.

Field associations and integration settings are saved individually for each Mailchimp audience so it is possible to switch between audiences and maintain unique configurations.

Mailchimp Processor supports all fields except image/file fields.
