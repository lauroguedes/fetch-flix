<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResolveRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'url' => ['required', 'string', 'url', 'max:2048'],

            // Not a preference. The client cannot merge separate DASH audio and
            // video, so a muxed single file is the only usable answer. Pinning
            // the accepted value means a future "adaptive" mode is an additive
            // change rather than a silent shift in what this field means.
            'prefer' => ['required', Rule::in(['progressive'])],

            'max_height' => ['sometimes', 'integer', Rule::in([360, 480, 720, 1080])],
            'max_duration_ms' => ['sometimes', 'nullable', 'integer', 'min:1000', 'max:14400000'],
            'audio_only' => ['sometimes', 'boolean'],

            'client' => ['required', 'array'],
            'client.platform' => ['required', 'string', 'max:32'],
            'client.app_version' => ['required', 'string', 'max:32'],
            'client.api_version' => ['required', 'string', 'max:16'],
        ];
    }

    public function maxHeight(): int
    {
        return (int) $this->input('max_height', 720);
    }

    public function audioOnly(): bool
    {
        return $this->boolean('audio_only');
    }

    public function maxDurationMs(): ?int
    {
        $value = $this->input('max_duration_ms');

        return is_numeric($value) ? (int) $value : null;
    }

    public function mediaUrl(): string
    {
        return (string) $this->input('url');
    }
}
