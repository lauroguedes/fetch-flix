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
            // The page URL of the video to fetch. Accepts every shape YouTube
            // hands out: /watch?v=, youtu.be/, /shorts/, /embed/ and /live/.
            // Pass the `url` from a /v1/discover result verbatim.
            'url' => ['required', 'string', 'url', 'max:2048'],

            // Which kind of stream to return. `progressive` (audio and video in
            // one file) is the only mode implemented and the default; asking
            // for anything else is a 422 rather than a silent fallback.
            'prefer' => ['sometimes', Rule::in(['progressive'])],

            // A **ceiling** on video height, not a request for that exact
            // size: the tallest progressive stream at or below it is returned,
            // and if none qualifies the smallest available is returned anyway.
            // Default 720.
            //
            // In practice, today, it changes nothing for YouTube. Every taller
            // rendition is delivered as HLS, which this API does not serve, so
            // itag 18 at 360p is the only progressive format left and every
            // value here resolves to it. Asking for 360 reports
            // `media.degraded: false`; asking for anything higher reports
            // `degraded: true` and echoes `media.requested_height`. The four
            // values remain accepted so the ceiling still means something if a
            // taller progressive format returns or another provider is added.
            //
            // Size your player from `media.width` and `media.height`, which
            // always describe what you actually got.
            'max_height' => ['sometimes', 'integer', Rule::in([360, 480, 720, 1080])],

            // Refuse anything longer than this, in **milliseconds** (60000 is
            // one minute). Returns 422 rather than downloading it. Unset means
            // no limit from you; your tenant may still impose its own.
            'max_duration_ms' => ['sometimes', 'nullable', 'integer', 'min:1000', 'max:14400000'],

            // Return an audio-only file instead of video. Default false.
            'audio_only' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Applied when `max_height` is omitted.
     *
     * Public because the OpenAPI generator publishes it: a default stated in
     * two places is a default that will eventually disagree with itself.
     */
    public const DEFAULT_MAX_HEIGHT = 720;

    public function maxHeight(): int
    {
        return (int) $this->input('max_height', self::DEFAULT_MAX_HEIGHT);
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
