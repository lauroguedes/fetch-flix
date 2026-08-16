<?php

use App\Media\Data\DimensionSource;
use App\Media\Data\VideoCard;
use App\Media\Discovery\DimensionResolver;
use App\Models\VideoDimension;
use Illuminate\Support\Facades\Http;

function cards(array $specs): array
{
    return array_map(
        fn (array $s) => new VideoCard(
            id: $s['id'],
            title: 'T',
            channel: 'C',
            durationMs: $s['duration_ms'] ?? 30000,
            thumbnailUrl: null,
            width: 0,
            height: 0,
        ),
        $specs
    );
}

function resolver(): DimensionResolver
{
    return app(DimensionResolver::class);
}

it('takes the portrait answer the shorts oembed form gives', function () {
    // The /watch form reports 16:9 even for vertical video, so querying the
    // right form is the whole point of this rung.
    Http::fake(['www.youtube.com/oembed*' => Http::response(['width' => 113, 'height' => 200])]);

    $out = resolver()->hydrate(cards([['id' => 'aaaaaaaaaaa']]));

    expect($out[0]->width)->toBe(1080)->and($out[0]->height)->toBe(1920);
});

it('does not force portrait on a landscape video sitting in a short feed', function () {
    // Verified against live YouTube: the /shorts/ form honestly returns 16:9
    // for a landscape video that merely appeared in a shorts search. Overriding
    // that with a "short means vertical" rule would corrupt it.
    Http::fake(['www.youtube.com/oembed*' => Http::response(['width' => 200, 'height' => 113])]);

    $out = resolver()->hydrate(cards([['id' => 'bbbbbbbbbbb', 'duration_ms' => 20000]]));

    expect($out[0]->width)->toBe(1280)->and($out[0]->height)->toBe(720);
});

it('queries the shorts form first for short videos and the watch form for long ones', function () {
    Http::fake(['www.youtube.com/oembed*' => Http::response(['width' => 113, 'height' => 200])]);

    resolver()->hydrate(cards([
        ['id' => 'aaaaaaaaaaa', 'duration_ms' => 30000],
        ['id' => 'bbbbbbbbbbb', 'duration_ms' => 900000],
    ]));

    Http::assertSent(fn ($r) => str_contains(urldecode($r->url()), 'youtube.com/shorts/aaaaaaaaaaa'));
    // Asking the shorts endpoint about a fifteen-minute video is a guaranteed
    // miss and a wasted round trip.
    Http::assertSent(fn ($r) => str_contains(urldecode($r->url()), 'watch?v=bbbbbbbbbbb'));
});

it('falls back to the watch form when the shorts form misses', function () {
    Http::fakeSequence()
        ->push('', 404)
        ->push(['width' => 200, 'height' => 113], 200);

    $out = resolver()->hydrate(cards([['id' => 'ccccccccccc', 'duration_ms' => 20000]]));

    // A shorts miss means "not a Short", not "no dimensions".
    expect($out[0]->width)->toBe(1280)->and($out[0]->height)->toBe(720);
});

it('never returns a zero dimension even when oembed is unreachable', function () {
    Http::fake(['*' => Http::response('', 500)]);

    $out = resolver()->hydrate(cards([['id' => 'ddddddddddd']]));

    // The contract is absolute: the chain terminates in a default rather than
    // handing a client something it cannot size a frame with.
    expect($out[0]->width)->toBeGreaterThan(0)
        ->and($out[0]->height)->toBeGreaterThan(0);
});

it('records which rung produced each answer', function () {
    Http::fake(['www.youtube.com/oembed*' => Http::response(['width' => 113, 'height' => 200])]);

    resolver()->hydrate(cards([['id' => 'eeeeeeeeeee']]));

    $row = VideoDimension::query()->where('provider_video_id', 'eeeeeeeeeee')->firstOrFail();

    // Otherwise a feed served entirely from guesses looks identical to one
    // served from exact data.
    expect($row->source)->toBe(DimensionSource::OEmbed)
        ->and($row->width)->toBe(1080);
});

it('marks a guessed value as a guess', function () {
    Http::fake(['*' => Http::response('', 500)]);

    resolver()->hydrate(cards([['id' => 'fffffffffff']]));

    expect(VideoDimension::query()->where('provider_video_id', 'fffffffffff')->firstOrFail()->source)
        ->toBe(DimensionSource::Heuristic);
});

it('reuses a stored exact answer without asking again', function () {
    VideoDimension::factory()->landscape()->create([
        'provider_video_id' => 'ggggggggggg',
        'source' => DimensionSource::OEmbed,
    ]);
    Http::fake();

    $out = resolver()->hydrate(cards([['id' => 'ggggggggggg']]));

    expect($out[0]->width)->toBe(1280)->and($out[0]->height)->toBe(720);
    Http::assertNothingSent();
});

it('retries a previously guessed video rather than cementing the guess', function () {
    VideoDimension::factory()->create([
        'provider_video_id' => 'hhhhhhhhhhh',
        'width' => 1080,
        'height' => 1920,
        'source' => DimensionSource::Heuristic,
    ]);
    Http::fake(['www.youtube.com/oembed*' => Http::response(['width' => 200, 'height' => 113])]);

    $out = resolver()->hydrate(cards([['id' => 'hhhhhhhhhhh']]));

    // A guess is not an answer. Re-asking costs no quota and corrects it.
    expect($out[0]->width)->toBe(1280)->and($out[0]->height)->toBe(720);
});

it('looks up a whole page in one pooled round of requests', function () {
    Http::fake(['www.youtube.com/oembed*' => Http::response(['width' => 113, 'height' => 200])]);

    $specs = array_map(fn (int $i) => ['id' => str_pad((string) $i, 11, 'x')], range(1, 20));
    $out = resolver()->hydrate(cards($specs));

    expect($out)->toHaveCount(20)
        ->and(collect($out)->every(fn ($c) => $c->width === 1080))->toBeTrue();
});

it('leaves already-known dimensions alone', function () {
    Http::fake();

    $card = new VideoCard('iiiiiiiiiii', 'T', 'C', 30000, null, 720, 1280);

    expect(resolver()->hydrate([$card])[0]->width)->toBe(720);
    Http::assertNothingSent();
});
