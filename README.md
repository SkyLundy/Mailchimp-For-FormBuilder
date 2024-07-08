# FormBuilder Mailchimp Processor

A highly configurable add-on for the ProcessWire FormBuilder module that adds Mailchimp list subscriptions to new and existing forms.

## Features

FormBuilderProcessorMailchimp provides the ability to submit subscribers to Mailchimp Audiences (mailing lists). Forms can send to specific lists, or to a global list configured within the module.

- Add or remove Mailchimp processing to new or existing forms.
- Specify which form fields will be submitted to Mailchimp
- Optionally include and configure an 'opt-in' checkbox for your forms
- Native FormBuilder integration can be added per-form form via the "Actions" config
- Can be added as an additional processing method alongside existing FormBuilder form actions
- Uses the Mailchimp API and API key to submit list subscriptions
- Submit to Mailchimp using a global API key, and/or configure an individual API key for each form
- Submit to Audiences (lists) using a global configuration, and/or configure a specific Audience for each form

Fields are automatically converted to Mailchimp-friendly values where necessary.

- Checkboxes fields are converted to an array of selected values
- Select Multiple fields are converted to an array of selected values
- Page Select fields use the name of the selected page as the value

## Requirements

- [ProcessWire](https://processwire.com/) >= 3.0
- [FormBuilder](https://processwire.com/store/form-builder/) >= 0.5.5 (untested with other versions but should work)
- PHP >= 8.1
- PHP CURL library

## Installation

Download the .zip from the Github repository, unzip into /site/modules, install module via Admin

## Usage

- Install module
- Configure optional global API key and global Audience ID
- Use the 'Actions' tab when configuring a FormBuilder form to add Mailchimp processing
- Specify an optional opt-in field
- Specify which fields should be submitted to Mailchimp and configure field names to their FormBuilder field counterparts

If a global API key is not provided, an API key will be required for each form individually. If a global API key is provided, an alternate API key can be provided per-form to override the global value where desired.

If a global Audience ID is not provided, an Audience ID will be required for each form individually. If a global Audience ID is provided, an alternate Audience ID can be provided per-form to override the global value where desired.