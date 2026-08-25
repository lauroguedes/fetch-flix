<?php

use App\Media\Storage\AssetKey;

it('derives the same key for the same request', function () {
    expect((string) AssetKey::for('youtube', 'dQw4w9WgXcQ', 720, false))
        ->toBe((string) AssetKey::for('youtube', 'dQw4w9WgXcQ', 720, false));
});

it('separates every dimension of the request', function () {
    $base = (string) AssetKey::for('youtube', 'dQw4w9WgXcQ', 720, false);

    expect((string) AssetKey::for('youtube', 'dQw4w9WgXcQ', 1080, false))->not->toBe($base)
        ->and((string) AssetKey::for('youtube', 'dQw4w9WgXcQ', 720, true))->not->toBe($base)
        ->and((string) AssetKey::for('youtube', 'other_id_00', 720, false))->not->toBe($base)
        ->and((string) AssetKey::for('vimeo', 'dQw4w9WgXcQ', 720, false))->not->toBe($base);
});

it('does not leak the video id', function () {
    // The key travels in the signed delivery URL. A guessable one would let
    // anybody enumerate what this deployment has cached.
    expect((string) AssetKey::for('youtube', 'dQw4w9WgXcQ', 720, false))
        ->not->toContain('dQw4w9WgXcQ')
        ->toMatch('/^[0-9a-f]{64}$/');
});

it('refuses a malformed key from the network', function () {
    expect(fn () => AssetKey::fromString('../../etc/passwd'))
        ->toThrow(InvalidArgumentException::class);
});

it('round-trips a well-formed key', function () {
    $key = (string) AssetKey::for('youtube', 'dQw4w9WgXcQ', 720, false);

    expect((string) AssetKey::fromString($key))->toBe($key);
});

it('gives two heights that pick the same format one path', function () {
    // Since YouTube removed itag 22, a 720p and a 1080p request both fall back
    // to itag 18. Keying the path on the requested height would store the same
    // bytes twice.
    expect(AssetKey::storagePath('youtube', 'dQw4w9WgXcQ', '18', 'mp4'))
        ->toBe(AssetKey::storagePath('youtube', 'dQw4w9WgXcQ', '18', 'mp4'))
        ->and(AssetKey::storagePath('youtube', 'dQw4w9WgXcQ', '18', 'mp4'))
        ->not->toBe(AssetKey::storagePath('youtube', 'dQw4w9WgXcQ', '22', 'mp4'));
});

it('shards paths across directories', function () {
    $path = AssetKey::storagePath('youtube', 'dQw4w9WgXcQ', '18', 'mp4');

    expect($path)->toBe('youtube/'.substr(sha1('dQw4w9WgXcQ'), 0, 2).'/dQw4w9WgXcQ/18.mp4');
});

it('neutralises a traversal attempt in an extractor-supplied id', function () {
    $path = AssetKey::storagePath('youtube', '../../../etc/passwd', '18', 'mp4');

    expect($path)->not->toContain('..')
        ->and($path)->not->toContain('etc/passwd');
});

it('keeps two unsafe ids apart instead of collapsing them', function () {
    // Stripping unsafe characters could map two distinct videos onto one path,
    // which would serve the wrong file. Hashing cannot.
    expect(AssetKey::storagePath('youtube', 'a/b', '18', 'mp4'))
        ->not->toBe(AssetKey::storagePath('youtube', 'a\\b', '18', 'mp4'));
});

it('falls back to a safe extension for an odd container', function () {
    expect(AssetKey::storagePath('youtube', 'dQw4w9WgXcQ', '18', '../sh'))
        ->toEndWith('.bin');
});
