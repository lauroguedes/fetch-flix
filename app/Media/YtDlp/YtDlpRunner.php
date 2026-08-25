<?php

namespace App\Media\YtDlp;

/**
 * Seam between the application and the extractor binary.
 *
 * Extraction runs in-process today; an HTTP-backed sidecar implementation can
 * replace it without touching callers. Also the seam every test substitutes,
 * which keeps the suite off the network.
 */
interface YtDlpRunner
{
    /**
     * @throws YtDlpFailed when extraction fails or exceeds its deadline
     */
    public function extract(string $url): YtDlpResult;
}
