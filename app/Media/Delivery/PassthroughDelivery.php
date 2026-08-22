<?php

namespace App\Media\Delivery;

use App\Media\Data\MediaRequest;
use App\Media\Data\ResolvedMedia;

/**
 * Hands back the provider's own URL, as originally designed.
 *
 * Kept working, and kept tested, because it is the escape hatch if storage
 * breaks. Its known limitation is the reason storage delivery exists: provider
 * URLs are bound to the IP that extracted them, so a client on a different
 * network than the resolver gets a 403 no header or retry can fix.
 */
final class PassthroughDelivery implements MediaDelivery
{
    public function cached(string $provider, string $providerId, MediaRequest $request): ?ResolvedMedia
    {
        // Nothing is kept, so there is never anything to answer from.
        return null;
    }

    public function prepare(ResolvedMedia $resolved, MediaRequest $request): ResolvedMedia
    {
        return $resolved;
    }
}
