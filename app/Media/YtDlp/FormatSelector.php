<?php

namespace App\Media\YtDlp;

use App\Api\Exceptions\ApiException;
use App\Media\Data\MediaRequest;
use App\Media\Data\MediaStream;

/**
 * Picks the one stream the client can actually use.
 *
 * `prefer: "progressive"` is a hard requirement, not a preference: the client
 * cannot merge separate DASH audio and video, so a muxed single file is the
 * only usable answer.
 *
 * The subtle part is what "muxed single file" means against real yt-dlp output.
 * YouTube advertises an HLS ladder (itags 91-96) whose entries report
 * `ext: mp4` and carry both codecs, so a codec-only filter happily returns a
 * 1080p "mp4" whose URL is a .m3u8 playlist. Handing that to a client that
 * expects a downloadable file fails at transfer time with a confusing error.
 * Only `protocol: https` entries are real files, so that filter is load-bearing
 * and must not be relaxed.
 *
 * With itag 22 removed by YouTube, itag 18 (640x360) is routinely the only true
 * progressive format, which is why degradation is the normal path rather than
 * an edge case.
 */
final class FormatSelector
{
    public function select(YtDlpResult $result, MediaRequest $request): MediaStream
    {
        $formats = $result->formats();

        if ($formats === []) {
            throw ApiException::mediaMissing('This media exposes no downloadable formats.');
        }

        $chosen = $request->audioOnly
            ? $this->selectAudio($formats)
            : $this->selectProgressive($formats, $request);

        return $this->toStream($chosen['format'], $request, $chosen['degraded']);
    }

    /**
     * @param  array<int, array<string, mixed>>  $formats
     * @return array{format: array<string, mixed>, degraded: bool}
     */
    private function selectProgressive(array $formats, MediaRequest $request): array
    {
        $candidates = array_values(array_filter(
            $formats,
            fn (array $f) => $this->isDownloadableFile($f) && $this->hasVideo($f) && $this->hasAudio($f)
        ));

        if ($candidates === []) {
            throw ApiException::mediaMissing(
                'No progressive stream is available for this media.'
            );
        }

        $withinCeiling = array_values(array_filter(
            $candidates,
            fn (array $f) => is_int($f['height'] ?? null) && $f['height'] <= $request->maxHeight
        ));

        if ($withinCeiling !== []) {
            // Prefer mp4 for the widest client compatibility, but never at the
            // cost of failing outright.
            $mp4 = array_values(array_filter($withinCeiling, fn (array $f) => ($f['ext'] ?? null) === 'mp4'));
            $pool = $mp4 !== [] ? $mp4 : $withinCeiling;

            $best = $this->highestQuality($pool);
            $height = is_int($best['height'] ?? null) ? $best['height'] : 0;

            $this->assertMeetsFloor($height, $request);

            return ['format' => $best, 'degraded' => $height < $request->maxHeight];
        }

        // Everything available exceeds the requested ceiling. Returning the
        // smallest one respects the intent of the ceiling better than erroring
        // would, and the client sizes its frame from the truthful width/height
        // we return regardless.
        $smallest = $this->lowestQuality($candidates);
        $height = is_int($smallest['height'] ?? null) ? $smallest['height'] : 0;

        $this->assertMeetsFloor($height, $request);

        return ['format' => $smallest, 'degraded' => true];
    }

    /**
     * @param  array<int, array<string, mixed>>  $formats
     * @return array{format: array<string, mixed>, degraded: bool}
     */
    private function selectAudio(array $formats): array
    {
        $candidates = array_values(array_filter(
            $formats,
            fn (array $f) => $this->isDownloadableFile($f) && $this->hasAudio($f) && ! $this->hasVideo($f)
        ));

        if ($candidates === []) {
            throw ApiException::mediaMissing('No audio-only stream is available for this media.');
        }

        $m4a = array_values(array_filter($candidates, fn (array $f) => ($f['ext'] ?? null) === 'm4a'));
        $pool = $m4a !== [] ? $m4a : $candidates;

        usort($pool, fn (array $a, array $b) => $this->bitrate($b) <=> $this->bitrate($a));

        return ['format' => $pool[0], 'degraded' => false];
    }

    private function assertMeetsFloor(int $height, MediaRequest $request): void
    {
        if ($request->minProgressiveHeight > 0 && $height < $request->minProgressiveHeight) {
            throw ApiException::mediaMissing(
                'No progressive stream meets the minimum acceptable quality.'
            );
        }
    }

    /**
     * A real file we can hand to a downloader.
     *
     * Excludes m3u8_native (HLS playlists) and mhtml (storyboards), both of
     * which appear in YouTube's format list and neither of which is a single
     * downloadable media file.
     *
     * @param  array<string, mixed>  $format
     */
    private function isDownloadableFile(array $format): bool
    {
        return ($format['protocol'] ?? null) === 'https'
            && is_string($format['url'] ?? null)
            && $format['url'] !== '';
    }

    /**
     * @param  array<string, mixed>  $format
     */
    private function hasVideo(array $format): bool
    {
        $vcodec = $format['vcodec'] ?? 'none';

        return is_string($vcodec) && $vcodec !== 'none' && $vcodec !== '';
    }

    /**
     * @param  array<string, mixed>  $format
     */
    private function hasAudio(array $format): bool
    {
        $acodec = $format['acodec'] ?? 'none';

        return is_string($acodec) && $acodec !== 'none' && $acodec !== '';
    }

    /**
     * @param  array<int, array<string, mixed>>  $pool
     * @return array<string, mixed>
     */
    private function highestQuality(array $pool): array
    {
        usort($pool, function (array $a, array $b) {
            return [$this->height($b), $this->bitrate($b)] <=> [$this->height($a), $this->bitrate($a)];
        });

        return $pool[0];
    }

    /**
     * @param  array<int, array<string, mixed>>  $pool
     * @return array<string, mixed>
     */
    private function lowestQuality(array $pool): array
    {
        usort($pool, function (array $a, array $b) {
            return [$this->height($a), $this->bitrate($a)] <=> [$this->height($b), $this->bitrate($b)];
        });

        return $pool[0];
    }

    /**
     * @param  array<string, mixed>  $format
     */
    private function height(array $format): int
    {
        return is_int($format['height'] ?? null) ? $format['height'] : 0;
    }

    /**
     * @param  array<string, mixed>  $format
     */
    private function bitrate(array $format): float
    {
        foreach (['tbr', 'abr', 'vbr'] as $key) {
            if (is_numeric($format[$key] ?? null)) {
                return (float) $format[$key];
            }
        }

        return 0.0;
    }

    /**
     * @param  array<string, mixed>  $format
     */
    private function toStream(array $format, MediaRequest $request, bool $degraded): MediaStream
    {
        $url = (string) $format['url'];
        $bytes = $this->bytes($format);

        if ($request->maxBytes !== null && $bytes !== null && $bytes > $request->maxBytes) {
            throw ApiException::tooLarge();
        }

        $expiresAt = StreamExpiry::withSafetyMargin(StreamExpiry::from($url));

        $hasVideo = $this->hasVideo($format);

        return new MediaStream(
            url: $url,
            mimeType: $this->mimeType($format, $url, $hasVideo),
            container: is_string($format['ext'] ?? null) ? $format['ext'] : 'mp4',
            bytes: $bytes,
            width: is_int($format['width'] ?? null) ? $format['width'] : null,
            height: is_int($format['height'] ?? null) ? $format['height'] : null,
            hasAudio: $this->hasAudio($format),
            hasVideo: $hasVideo,
            headers: $this->headers($format),
            expiresAt: $expiresAt,
            degraded: $degraded,
            requestedHeight: $degraded ? $request->maxHeight : null,
            formatId: is_scalar($format['format_id'] ?? null) ? (string) $format['format_id'] : null,
        );
    }

    /**
     * yt-dlp reports an exact filesize only for some formats; the muxed ones
     * usually carry an approximation instead. The client uses this to refuse
     * oversized downloads early, so an approximate value beats none.
     */
    /**
     * @param  array<string, mixed>  $format
     */
    private function bytes(array $format): ?int
    {
        foreach (['filesize', 'filesize_approx'] as $key) {
            if (is_int($format[$key] ?? null)) {
                return $format[$key];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $format
     */
    private function mimeType(array $format, string $url, bool $hasVideo): string
    {
        // googlevideo puts the authoritative type in the URL itself.
        $query = parse_url($url, PHP_URL_QUERY);

        if (is_string($query)) {
            parse_str($query, $params);

            if (is_string($params['mime'] ?? null) && str_contains($params['mime'], '/')) {
                return $params['mime'];
            }
        }

        $ext = is_string($format['ext'] ?? null) ? $format['ext'] : 'mp4';

        return match ($ext) {
            'm4a' => 'audio/mp4',
            'webm' => $hasVideo ? 'video/webm' : 'audio/webm',
            'mp3' => 'audio/mpeg',
            'opus' => 'audio/opus',
            default => $hasVideo ? 'video/mp4' : 'audio/mp4',
        };
    }

    /**
     * Passed to the client verbatim, minus the one header that actively breaks
     * it: URLSession sets its own Accept-Encoding, and a conflicting value
     * corrupts the response body.
     *
     * @param  array<string, mixed>  $format
     * @return array<string, string>
     */
    private function headers(array $format): array
    {
        $headers = $format['http_headers'] ?? [];

        if (! is_array($headers)) {
            return [];
        }

        /** @var array<int, string> $stripped */
        $stripped = config('media.resolve.stripped_headers', []);

        $result = [];

        foreach ($headers as $name => $value) {
            if (! is_string($name) || ! is_scalar($value)) {
                continue;
            }

            if (in_array(strtolower($name), $stripped, true)) {
                continue;
            }

            $result[$name] = (string) $value;
        }

        return $result;
    }
}
