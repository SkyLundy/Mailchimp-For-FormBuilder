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

    private array $audienceMembers = [];

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
        $lowerEmail = mb_strtolower($subscriberData['email_address'], 'UTF-8');
        $emailHash = hash('MD5', $lowerEmail);

        return $this->mailchimp->put("lists/{$audienceId}/members/{$emailHash}", $subscriberData);
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

        $audiences = $this->mailchimp->get('lists');

        if (!$audiences) {
            return [];
        }

        return $this->audiences = $audiences;
    }

    /**
     * Gets details for a specific audience (list)
     * @param  string $audienceId Mailchimp Audience ID
     */
    public function getAudience(string $audienceId): array
    {
        $audience = $this->mailchimp->get("lists/{$audienceId}");

        if (!$audience) {
            return [];
        }

        return $audience;
    }

    /**
     * Get all members of a given audience
     * @param  string $audienceId Mailchimp Audience ID
     */
    public function getAudienceMembers(string $audienceId): array
    {
        if ($this->audienceMembers) {
            return $this->audienceMembers;
        }

        $audienceMembers = $this->mailchimp->get("lists/{$audienceId}/members");

        if (!$audienceMembers) {
            return [];
        }

        return $this->audienceMembers = $audienceMembers;
    }

    /**
     * Get mock subscribe action response data
     * - Creates a fake user
     * - Creates a new subscriber using fake user data in Mailchimp
     * - Deletes the new fake user
     * - Returns response body
     * This is useful to get data points, such as GDPR/marketing permissions that are not available
     * via any other method
     * @param  string $audienceId Audience to retrieve mock data for
     */
    public function mockSubscribe(string $audienceId): array|bool
    {
        $mergeFields = $this->getMergeFields($audienceId);
        $subscriberData =  FakeSubscriber::generate($mergeFields);

        $response = $this->subscribe($subscriberData, $audienceId);
    }

    /**
     * Delete a specific audience member
     * @param  string $audienceId     ID of audience
     * @param  string $subscriberHash An MD5 hash of the email address in lowercase
     */
    public function deleteAudienceMember(string $audienceId, string $subscriberHash): bool|array
    {
        return $this->mailchimp->delete(
            "/lists/{$audienceId}/members/{$subscriberHash}/actions/delete-permanent"
        );
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