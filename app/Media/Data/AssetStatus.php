<?php

namespace App\Media\Data;

/**
 * Lifecycle of one stored media asset.
 *
 * The states exist so the delivery endpoint can tell "not here yet, wait" from
 * "not here and never will be". Collapsing them would turn a download still in
 * flight into a permanent-looking failure a few seconds before it succeeds.
 */
enum AssetStatus: string
{
    /** Registered by a resolve; no worker has picked it up yet. */
    case Pending = 'pending';

    /** Claimed by a worker. Claiming is atomic, so exactly one worker holds it. */
    case Downloading = 'downloading';

    /** Bytes are on the disk named by the row. The only servable state. */
    case Stored = 'stored';

    /** Download failed terminally. Retryable by re-claiming, up to max_attempts. */
    case Failed = 'failed';

    /**
     * Whether a caller waiting on this asset can expect it to become servable.
     */
    public function isInFlight(): bool
    {
        return $this === self::Pending || $this === self::Downloading;
    }
}
