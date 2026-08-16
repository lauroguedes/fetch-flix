<?php

namespace Tests\Support;

use App\Media\YtDlp\YtDlpFailed;
use App\Media\YtDlp\YtDlpResult;
use App\Media\YtDlp\YtDlpRunner;

/**
 * Substitutes the extractor so the suite never touches YouTube.
 *
 * Fixtures under tests/Fixtures/YtDlp were captured from a real
 * `yt-dlp --dump-single-json` run, so the shapes here are the shapes production
 * sees, including the HLS entries that look muxed but are playlists.
 */
final class FakeYtDlpRunner implements YtDlpRunner
{
    public int $calls = 0;

    /** @var array<int, string> */
    public array $urls = [];

    private function __construct(
        private readonly ?string $fixture = null,
        private readonly ?YtDlpFailed $failure = null,
        /** @var array<string, mixed>|null */
        private readonly ?array $info = null,
    ) {}

    public static function withFixture(string $name): self
    {
        return new self(fixture: $name);
    }

    /**
     * @param  array<string, mixed>  $info
     */
    public static function withInfo(array $info): self
    {
        return new self(info: $info);
    }

    public static function failingWith(string $stderr, ?int $exitCode = 1): self
    {
        return new self(failure: new YtDlpFailed(stderr: $stderr, exitCode: $exitCode));
    }

    public static function timingOut(): self
    {
        return new self(failure: YtDlpFailed::timedOut(8000));
    }

    public function extract(string $url): YtDlpResult
    {
        $this->calls++;
        $this->urls[] = $url;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        if ($this->info !== null) {
            return new YtDlpResult($this->info);
        }

        return new YtDlpResult(self::fixture((string) $this->fixture));
    }

    /**
     * @return array<string, mixed>
     */
    public static function fixture(string $name): array
    {
        $path = __DIR__."/../Fixtures/YtDlp/{$name}.json";

        if (! is_file($path)) {
            throw new \RuntimeException("Missing yt-dlp fixture [{$name}].");
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
