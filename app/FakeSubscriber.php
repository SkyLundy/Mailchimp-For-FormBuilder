<?php

declare(strict_types=1);

namespace FormBuilderProcessorMailchimp\App;

class FakeSubscriber
{
    /**
     * Creates a fake subscriber with data necessary to complete a subscription
     * @param  array  $mergeFields MailchimpClient getMergeFields return value
     * @return array
     */
    public static function generate(array $mergeFields): array
    {
        if (!$mergeFields) {
            return [];
        }

        $mergeFields = $mergeFields['mergeFields'] ?? null;

        if (!$mergeFields) {
            return [];
        }

        dd($mergeFields);

    }

    public const RANDOM_WORDS = [
        'rocket',
        'scheme',
        'enfix',
        'bind',
        'strap',
        'consciousness',
        'mole',
        'refuse',
        'weakness',
        'reference',
        'introduce',
        'unit',
        'variation',
        'save',
        'count',
        'expression',
        'update',
        'accountant',
        'press',
        'boat',
        'squash',
        'swim',
        'bounce',
        'critical',
        'lead',
        'lift',
        'distort',
        'soar',
        'bow',
        'agile',
        'minimum',
        'launch',
        'chaos',
        'drawing',
        'nationalist',
        'arrest',
        'cunning',
        'understanding',
        'ethnic',
        'determine',
        'needle',
        'belt',
        'software',
        'joy',
        'collect',
        'loan',
        'correspondence',
        'disorder',
        'section',
        'suite',
    ];
}