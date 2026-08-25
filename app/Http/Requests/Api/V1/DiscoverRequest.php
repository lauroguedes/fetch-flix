<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DiscoverRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Which feed to read. `category` serves an operator-curated feed
            // and needs `category`; `search` runs a free-text query and needs
            // `query`. Required: there is no default feed.
            'kind' => ['required', Rule::in(['category', 'search'])],

            // Required when `kind` is `category`. Must be one of the slugs from
            // GET /v1/categories — an unknown or retired slug is a 422.
            'category' => [
                'required_if:kind,category',
                'nullable',
                'string',
                Rule::exists('video_categories', 'slug')->where('is_active', true),
            ],

            // Required when `kind` is `search`. Surrounding and repeated
            // whitespace is normalised before the query runs; word order is
            // preserved because it changes the ranking you get back.
            'query' => ['required_if:kind,search', 'nullable', 'string', 'min:2', 'max:100'],

            // Drop results longer than this, in **milliseconds** (60000 is one
            // minute). **Defaults to 180000 (3 minutes)** when omitted, so a
            // request that sends nothing here still gets a short-form feed.
            // Send `null` explicitly to lift the limit entirely.
            'max_duration_ms' => ['sometimes', 'nullable', 'integer', 'min:1000', 'max:3600000'],

            // Fetch the next page. Pass back the `next_page_token` from the
            // previous response, unchanged. Omit it for the first page; a null
            // `next_page_token` means there are no more pages.
            'page_token' => ['sometimes', 'nullable', 'string', 'max:512'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            // The framework's default ("The selected category is invalid.")
            // leaves the caller with no way to find a valid one.
            'category.exists' => 'No such category. GET /v1/categories lists the ones this deployment serves.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $query = $this->input('query');

        if (is_string($query)) {
            // Normalised before validation so "  cats   jumping " and "cats
            // jumping" share one cache entry rather than costing 200 units.
            $this->merge([
                'query' => trim((string) preg_replace('/\s+/u', ' ', $query)),
            ]);
        }
    }

    /**
     * Absent means the configured default (3 minutes), not "no limit": this is
     * a short-form feed and an unbounded page is rarely what a caller wants.
     * An explicit null lifts the limit.
     */
    public function maxDurationMs(): ?int
    {
        $value = $this->input('max_duration_ms', config('media.discovery.default_max_duration_ms'));

        return is_numeric($value) ? (int) $value : null;
    }

    public function pageToken(): ?string
    {
        $token = $this->input('page_token');

        return is_string($token) && $token !== '' ? $token : null;
    }
}
