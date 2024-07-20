<?php

declare(strict_types=1);

namespace FormBuilderProcessorMailchimp\App;

use FormBuilderProcessorMailchimp\App\MailChimp;

class MailchimpClient
{

    /**
     * Mailchimp API memoized data
     */

    private array $audiences = [];

    private array $mergeFields = [
        'audienceId' => null,
        'mergeFields' => [],
    ];

    private array $segments = [
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
    private array $interestCategories = [
        'audienceId' => null,
        'interestCategories' => [],
    ];

    private function __construct(
        public MailChimp $mailchimp
    ) {}

    /**
     * Initializes object, returns new instance of self
     */
    public static function init(string $apiKey): self
    {
        $mailchimp = new MailChimp($apiKey);

        return new self($mailchimp);
    }

    /**
     * Adds a new subscriber
     */
    public function subscribe(array $subscriberData, string $audienceId): mixed
    {
        return $this->mailchimp->post("lists/{$audienceId}/members", $subscriberData);
    }

    /**
     * Adds a new subscriber or updates and existing subscriber
     */
    public function subscribeOrUpdate(array $subscriberData, $audienceId): mixed
    {
        return $this->mailchimp->put("lists/{$audienceId}/members", $subscriberData);
    }

    /**
     * Get all Mailchimp audiences (lists)
     * Returns the API response body
     */
    public function getAudiences(): array
    {
        if (count($this->audiences)) {
            return $this->audiences;
        }

        return $this->audiences = $this->mailchimp->get('lists');
    }

    /**
     * Gets the merge fields for a given audience ID
     * Memoizes results by audience ID
     * Returns the API response body
     */
    public function getMergeFields(string $audienceId): array
    {
        [
            'audienceId' => $fetchedAudienceId,
            'mergeFields' => $fetchedMergeFields,
        ] = $this->mergeFields;

        if (count($fetchedMergeFields) && $audienceId === $fetchedAudienceId) {
            return $fetchedMergeFields;
        }

        $mergeFields = $this->mailchimp->get("lists/{$audienceId}/merge-fields");

        if (!$mergeFields) {
            return $this->mergeFields;
        }

        $this->mergeFields = ['audienceId' => $audienceId, 'mergeFields' => $mergeFields];

        return $mergeFields;
    }

    /**
     * Gets available tags for a given audience ID
     */
    public function getTags(string $audienceId): array
    {
        $segments = $this->getSegments($audienceId)['segments'];

        return array_filter($segments, fn ($segment) => $segment['type'] === 'static');
    }

    /**
     * Gets segments for a given audience ID
     * Memoizes data by audience ID
     */
    public function getSegments(string $audienceId): array
    {
        [
            'audienceId' => $fetchedAudienceId,
            'segments' => $fetchedSegments,
        ] = $this->segments;

        if (count($fetchedSegments) && $audienceId === $fetchedAudienceId) {
            return $fetchedSegments;
        }

        $segments = $this->mailchimp->get("lists/{$audienceId}/segments");

        if (!$segments) {
            return $this->segments;
        }

        $this->segments = ['audienceId' => $audienceId, 'segments' => $segments];

        return $segments;
    }

    /**
     * Gets interest categories for a given audience ID
     * Memoizes data by audience ID
     */
    public function getInterestCategories(string $audienceId): array
    {
        [
            'audienceId' => $fetchedAudienceId,
            'interestCategories' => $fetchedInterestCategories,
        ] = $this->interestCategories;

        if (count($fetchedInterestCategories) && $audienceId === $fetchedAudienceId) {
            return $fetchedInterestCategories;
        }

        $interestCategories = [];

        $categories = $this->mailchimp->get("lists/{$audienceId}/interest-categories");

        if (!$categories) {
            return $this->interestCategories;
        }

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
                'interests' => $this->mailchimp->get($interestsEndpoint),
            ];
        }

        $this->interestCategories = [
            'audienceId' => $audienceId,
            'interestCategories' => $interestCategories
        ];

        return $interestCategories;
    }
}