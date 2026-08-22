<?php

namespace Tests\Support;

use App\Api\Exceptions\ApiException;
use App\Media\Storage\DownloadedFile;
use App\Media\Storage\MediaDownloader;
use App\Media\YtDlp\YtDlpFailed;
use Throwable;

/**
 * Substitutes the transfer so the suite never downloads anything.
 *
 * Writes real bytes to the real destination, because the code under test opens
 * that file and streams it to the disk. Faking the transfer but not the file
 * would leave the interesting half untested.
 */
final class FakeMediaDownloader implements MediaDownloader
{
    public int $calls = 0;

    /** @var array<int, array{url: string, format: string, destination: string, max_bytes: int}> */
    public array $received = [];

    private function __construct(
        private readonly string $contents = 'fake-media-bytes',
        private readonly ?Throwable $failure = null,
    ) {}

    public static function writing(string $contents = 'fake-media-bytes'): self
    {
        return new self(contents: $contents);
    }

    public static function failingWith(string $stderr): self
    {
        return new self(failure: new YtDlpFailed(stderr: $stderr, exitCode: 1));
    }

    public static function refusing(ApiException $e): self
    {
        return new self(failure: $e);
    }

    public function download(string $sourceUrl, string $formatId, string $destination, int $maxBytes): DownloadedFile
    {
        $this->calls++;
        $this->received[] = [
            'url' => $sourceUrl,
            'format' => $formatId,
            'destination' => $destination,
            'max_bytes' => $maxBytes,
        ];

        if ($this->failure !== null) {
            throw $this->failure;
        }

        if (! is_dir(dirname($destination))) {
            mkdir(dirname($destination), 0755, true);
        }

        file_put_contents($destination, $this->contents);

        return new DownloadedFile(
            path: $destination,
            bytes: strlen($this->contents),
            checksum: hash('sha256', $this->contents),
        );
    }
}
