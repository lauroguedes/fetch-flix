<?php

use App\Media\Data\AspectRatio;
use App\Media\Data\Dimensions;

it('snaps a measured player box to the encode that ratio implies', function (int $w, int $h, int $ew, int $eh) {
    $snapped = AspectRatio::snap(new Dimensions($w, $h));

    expect($snapped->width)->toBe($ew)->and($snapped->height)->toBe($eh);
})->with([
    // The exact values live YouTube oEmbed returns for a vertical Short.
    'vertical short' => [113, 200, 1080, 1920],
    // ...and for a landscape video.
    'landscape' => [200, 113, 1280, 720],
    'square' => [200, 200, 1080, 1080],
    'four by three' => [200, 150, 1440, 1080],
    'cinematic' => [240, 100, 1280, 544],
]);

it('preserves an unusual ratio rather than forcing it into a standard one', function () {
    // Telling the client 16:9 for a 2:1 video is worse than an odd-looking
    // number, because the frame it sizes will be visibly wrong.
    $snapped = AspectRatio::snap(new Dimensions(200, 100));

    expect(round($snapped->width / $snapped->height, 2))->toBe(2.0);
});

it('scales an unusual portrait ratio on its long edge', function () {
    $snapped = AspectRatio::snap(new Dimensions(100, 300));

    expect($snapped->height)->toBe(1280)
        ->and($snapped->width)->toBe(427);
});

it('leaves a degenerate measurement alone rather than dividing by zero', function () {
    $snapped = AspectRatio::snap(new Dimensions(200, 0));

    expect($snapped->width)->toBe(200)->and($snapped->height)->toBe(0);
});

it('reports orientation', function () {
    expect((new Dimensions(1080, 1920))->isPortrait())->toBeTrue()
        ->and((new Dimensions(1280, 720))->isPortrait())->toBeFalse();
});
