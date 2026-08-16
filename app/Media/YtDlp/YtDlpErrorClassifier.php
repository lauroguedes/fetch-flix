<?php

namespace App\Media\YtDlp;

use App\Api\ErrorCode;
use App\Api\Exceptions\ApiException;
use App\Media\Events\BotDetected;
use App\Media\Events\ExtractorBroken;
use Illuminate\Support\Facades\Event;

/**
 * Maps raw extractor stderr onto the client-facing error taxonomy.
 *
 * yt-dlp emits no structured errors, so this is pattern matching against
 * stderr, driven by an ordered table in config/media.php that can be extended
 * without a deploy.
 *
 * The default case carries the design weight: unrecognised stderr becomes a
 * retryable ResolverUnavailable and raises ExtractorBroken. YouTube changes
 * weekly, and the failure mode this prevents is a wave of silent 500s that
 * nobody notices until clients complain.
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
                // Reported distinctly from a generic extractor failure: this is
                // the metric that decides when to escalate to a PO token
                // provider or move resolver egress off the datacenter.
                Event::dispatch(new BotDetected($stderr));
            }

            if ($rule['extractor_broken'] ?? false) {
                Event::dispatch(new ExtractorBroken($stderr, matched: true));
            }

            return $this->toException($rule['code'], $failure);
        }

        Event::dispatch(new ExtractorBroken($stderr, matched: false));

        return ApiException::resolverUnavailable(
            context: ['reason' => 'unclassified', 'stderr' => $stderr],
            previous: $failure,
        );
    }

    private function toException(ErrorCode $code, YtDlpFailed $failure): ApiException
    {
        $context = ['stderr' => $failure->stderr, 'exit_code' => $failure->exitCode];

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
