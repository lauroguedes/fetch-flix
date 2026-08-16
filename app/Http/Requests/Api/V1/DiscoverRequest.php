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
            'kind' => ['required', Rule::in(['category', 'search'])],

            // `nullable` matters alongside `required_if`: a client that always
            // sends both keys and nulls the irrelevant one would otherwise fail
            // the `string` rule on the null.
            'category' => [
                'required_if:kind,category',
                'nullable',
                'string',
                Rule::exists('video_categories', 'slug')->where('is_active', true),
            ],

            'query' => ['required_if:kind,search', 'nullable', 'string', 'min:2', 'max:100'],

            'max_duration_ms' => ['sometimes', 'nullable', 'integer', 'min:1000', 'max:3600000'],
            'page_token' => ['sometimes', 'nullable', 'string', 'max:512'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $query = $this->input('query');

        if (is_string($query)) {
            // Normalised before validation so that "  cats   jumping " and
            // "cats jumping" are the same request, and therefore the same cache
            // entry rather than two 100-unit calls.
            $this->merge([
                'query' => trim((string) preg_replace('/\s+/u', ' ', $query)),
            ]);
        }
    }

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
