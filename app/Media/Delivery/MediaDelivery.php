<?php

namespace App\Media\Delivery;

use App\Media\Data\MediaRequest;
use App\Media\Data\ResolvedMedia;

/**
 * How a resolved stream reaches the client.
 *
 * A seam rather than a conditional, because the two answers differ in what they
 * do before extraction as well as after it, and because keeping the original
 * pass-through behaviour executable is what makes it a usable escape hatch
 * rather than a paragraph in a commit message.
 */
interface MediaDelivery
{
    /**
     * Answer without extracting anything, if that is possible.
     *
     * Null means "go and extract". Returning a value here is what turns a
     * repeat resolve from an 8s process spawn into a database read.
     */
    public function cached(string $provider, string $providerId, MediaRequest $request): ?ResolvedMedia;

    /**
     * Turn a freshly extracted result into what the client actually receives.
     */
    public function prepare(ResolvedMedia $resolved, MediaRequest $request): ResolvedMedia;
}
