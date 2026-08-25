<?php

namespace App\Media\Storage;

use App\Api\Exceptions\ApiException;
use App\Media\YtDlp\YtDlpFailed;

/**
 * Seam for transferring one media file to local disk.
 *
 * Separate from YtDlpRunner despite the shared binary: extraction is a metadata
 * call on the critical path with an 8s deadline, this is a queued transfer with
 * a deadline in minutes. They fail differently and are faked separately.
 */
interface MediaDownloader
{
    /**
     * @param  string  $sourceUrl  The page URL, not the format URL: format URLs
     *                             expire and are IP-bound, so the resolve's may
     *                             already be dead when the job runs.
     * @param  int  $maxBytes  Hard ceiling; a declared size can be absent or
     *                         wrong, so the transfer is aborted rather than
     *                         allowed to fill the disk.
     *
     * @throws ApiException tooLarge when the ceiling is hit
     * @throws YtDlpFailed on any other transfer failure
     */
    public function download(string $sourceUrl, string $formatId, string $destination, int $maxBytes): DownloadedFile;
}
