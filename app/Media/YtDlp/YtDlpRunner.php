<?php

namespace App\Media\YtDlp;

/**
 * Seam between the application and the extractor binary.
 *
 * Extraction runs in-process today. When datacenter bot checks force resolver
 * traffic onto differently-homed egress, an HTTP-backed implementation pointed
 * at a sidecar replaces this one: a config value and a new class, not a
 * rewrite. It is also the seam every test substitutes, which is what keeps the
 * suite off the network.
 */
interface YtDlpRunner
{
    /**
     * @throws YtDlpFailed when extraction fails or exceeds its deadline
     */
    public function extract(string $url): YtDlpResult;
}
