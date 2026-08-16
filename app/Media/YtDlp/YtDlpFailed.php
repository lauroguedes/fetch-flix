<?php

namespace App\Media\YtDlp;

use RuntimeException;

/**
 * Raw extractor failure, before classification.
 *
 * Carries stderr so the classifier can map it to a client-facing code, and so
 * an unrecognised failure can be logged with enough detail to write a new
 * pattern for it.
 */
final class YtDlpFailed extends RuntimeException
{
    public function __construct(
        public readonly string $stderr,
        public readonly ?int $exitCode = null,
        public readonly bool $timedOut = false,
        string $message = 'yt-dlp extraction failed.',
    ) {
        parent::__construct($message);
    }

    public static function timedOut(int $timeoutMs): self
    {
        return new self(
            stderr: '',
            timedOut: true,
            message: "yt-dlp exceeded its {$timeoutMs}ms deadline.",
        );
    }
}
