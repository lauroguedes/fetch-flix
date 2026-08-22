<?php

namespace App\Media\Storage;

/**
 * A file that made it onto local disk, described.
 */
final readonly class DownloadedFile
{
    public function __construct(
        public string $path,
        public int $bytes,
        public string $checksum,
    ) {}
}
