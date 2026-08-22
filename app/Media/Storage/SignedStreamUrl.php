<?php

namespace App\Media\Storage;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * Mints the URL that goes into a resolve response.
 *
 * Always our own signed route, never the bucket's pre-signed URL, for three
 * reasons that all matter: local development and production behave identically,
 * switching storage provider does not change the contract, and the expiry
 * quoted to the client stays ours to choose and ours to reissue. The bucket's
 * pre-signed URL is an implementation detail of one hop, minted at delivery
 * time and never cached.
 */
final class SignedStreamUrl
{
    /**
     * @return array{url: string, expires_at: Carbon}
     */
    public function mint(string $assetKey): array
    {
        $expiresAt = Carbon::now()->addSeconds((int) config('media.storage.signed_ttl', 21600));

        return [
            'url' => URL::temporarySignedRoute('api.v1.stream', $expiresAt, ['asset' => $assetKey]),
            'expires_at' => $expiresAt,
        ];
    }
}
