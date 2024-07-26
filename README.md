# FormBuilder Mailchimp Processor

A highly configurable add-on for the ProcessWire FormBuilder module that adds Mailchimp subscriptions to new and existing forms.

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

Download the .zip from the Github repository, unzip into /site/modules, install via module Admin

## Usage

Visit the 'Actions' tab when editing a FormBuilder form, then check 'Mailchimp for FormBuilder'.

Choose a Mailchimp Audience, save, then return to the Mailchimp for FormBuilder configuration area. Select the actions and configuration options that should apply to subscriptions from this form.

Choose Audience Tags, or create new ones, to organize subscriptions and contacts that are submitted from this form.

A list of checkboxes with the fields available in Mailchimp allows you to choose which fields you would like your form to send data to.

Choose the form fields that should send data to the Mailchimp fields you've selected.

Optionally choose an opt-in checkbox field required for subscribing.

The configuration screen for this action has some robust information to make configuration easier for non-developers.

## Todo

- Implement Mailchimp GDPR features. Mailchimp doesn't make it easy to do this via the API but it's possible.