<?php

namespace App\Media\YtDlp;

use App\Api\ErrorCode;
use App\Api\Exceptions\ApiException;

/**
 * Maps raw extractor stderr onto the client-facing error taxonomy.
 *
 * yt-dlp emits no structured errors, so this is pattern matching against an
 * ordered table in config/media.php. The default case carries the weight:
 * unrecognised stderr becomes a retryable ResolverUnavailable and is reported
 * as a breakage, so next week's YouTube change surfaces as one alert instead of
 * a wave of silent 500s.
 */
final class YtDlpErrorClassifier
{
    public function classify(YtDlpFailed $failure): ApiException
    {
        if ($failure->timedOut) {
            // The work is not wasted: the caller re-dispatches this extraction
            // to a queue so the client's retry lands on a warm cache.
            return ApiException::resolverUnavailable(
                retryAfter: 2,
                message: 'Resolution exceeded its deadline.',
                context: ['reason' => 'timeout'],
                previous: $failure,
            );
        }

        $stderr = $failure->stderr;

        foreach ($this->patterns() as $rule) {
            if (preg_match($rule['pattern'], $stderr) !== 1) {
                continue;
            }

            if ($rule['bot_check'] ?? false) {
                ExtractorReport::botCheck($stderr);
            }

            if ($rule['extractor_broken'] ?? false) {
                ExtractorReport::broken($stderr, matched: true);
            }

            return $this->toException($rule['code'], $failure);
        }

        ExtractorReport::broken($stderr, matched: false);

        return ApiException::resolverUnavailable(
            context: ['reason' => 'unclassified', 'stderr' => ExtractorReport::redact($stderr)],
            previous: $failure,
        );
    }

    private function toException(ErrorCode $code, YtDlpFailed $failure): ApiException
    {
        $context = [
            'stderr' => ExtractorReport::redact($failure->stderr),
            'exit_code' => $failure->exitCode,
        ];

        return match ($code) {
            ErrorCode::PrivateOrRestricted => ApiException::privateOrRestricted(context: $context),
            ErrorCode::RegionBlocked => ApiException::regionBlocked(context: $context),
            ErrorCode::Unresolvable => ApiException::unresolvable(context: $context),
            ErrorCode::MediaMissing => ApiException::mediaMissing(context: $context),
            ErrorCode::Offline => ApiException::offline(context: $context),
            ErrorCode::DownloadFailed => ApiException::downloadFailed(context: $context),
            default => ApiException::resolverUnavailable(context: $context, previous: $failure),
        };
    }

    /**
     * @return array<int, array{pattern: string, code: ErrorCode, bot_check?: bool, extractor_broken?: bool}>
     */
    private function patterns(): array
    {
        /** @var array<int, array{pattern: string, code: ErrorCode, bot_check?: bool, extractor_broken?: bool}> $patterns */
        $patterns = config('media.error_patterns', []);

        return $patterns;
    }
}
