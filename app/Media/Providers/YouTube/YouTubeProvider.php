<?php

namespace App\Media\Providers\YouTube;

use App\Api\Exceptions\ApiException;
use App\Media\Contracts\ResolvesMedia;
use App\Media\Data\MediaRequest;
use App\Media\Data\ResolvedMedia;
use App\Media\YtDlp\FormatSelector;
use App\Media\YtDlp\YtDlpErrorClassifier;
use App\Media\YtDlp\YtDlpFailed;
use App\Media\YtDlp\YtDlpRunner;

/**
 * YouTube resolution.
 *
 * Implements ResolvesMedia only for now. DiscoversMedia and ReportsDimensions
 * arrive in phases 3 and 4; keeping them separate means this class never has to
 * stub a capability it cannot yet honour.
 */
final class YouTubeProvider implements ResolvesMedia
{
    public function __construct(
        private readonly YtDlpRunner $runner,
        private readonly FormatSelector $formats,
        private readonly YtDlpErrorClassifier $classifier,
        private readonly YouTubeUrlParser $urls,
    ) {}

    public function slug(): string
    {
        return 'youtube';
    }

    public function claims(string $url): bool
    {
        return $this->urls->claims($url);
    }

    public function normalizeId(string $url): string
    {
        return $this->urls->parse($url);
    }

    public function resolve(MediaRequest $request): ResolvedMedia
    {
        $id = $this->normalizeId($request->url);

        try {
            $result = $this->runner->extract($this->urls->watchUrl($id));
        } catch (YtDlpFailed $failure) {
            throw $this->classifier->classify($failure);
        }

        $durationMs = $result->durationMs();

        // Checked before format selection: a video that is too long is too long
        // regardless of which stream we would have picked, and saying so early
        // keeps the failure honest.
        if ($request->maxDurationMs !== null && $durationMs !== null && $durationMs > $request->maxDurationMs) {
            throw ApiException::tooLong();
        }

        $stream = $this->formats->select($result, $request);

        if ($stream->isExpired()) {
            // The extractor handed back a URL that is already past its expiry.
            // Rare, but it must surface as `expired` so the client re-resolves
            // rather than reporting a mysterious download failure.
            throw ApiException::expired();
        }

        return new ResolvedMedia(
            provider: $this->slug(),
            id: $id,
            title: $result->title(),
            author: $result->author(),
            durationMs: $durationMs,
            thumbnailUrl: $result->thumbnailUrl(),
            media: $stream,
        );
    }
}
