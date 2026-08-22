<?php

namespace App\Media\Storage;

use App\Api\Exceptions\ApiException;
use App\Media\YtDlp\YtDlpFailed;

/**
 * Seam for transferring one media file to local disk.
 *
 * Kept separate from YtDlpRunner even though both end up running the same
 * binary. Extraction is a metadata call on the client's critical path with an
 * 8s deadline; this is a file transfer on a queue with a deadline measured in
 * minutes. They fail differently, are tuned differently, and are faked
 * separately in tests.
 */
interface MediaDownloader
{
    /**
     * @param  string  $sourceUrl  The page URL, not the format URL. Format URLs
     *                             expire and are bound to the IP that extracted
     *                             them, so by the time a queued download runs
     *                             the one from the resolve may already be dead.
     *                             Re-extracting costs a second or two and is
     *                             what makes the download reliable.
     * @param  int  $maxBytes  Hard ceiling. The transfer is aborted rather than
     *                         allowed to fill the disk, because a declared size
     *                         can be absent or wrong.
     *
     * @throws ApiException tooLarge when the ceiling is hit
     * @throws YtDlpFailed on any other transfer failure
     */
    public function download(string $sourceUrl, string $formatId, string $destination, int $maxBytes): DownloadedFile;
}
