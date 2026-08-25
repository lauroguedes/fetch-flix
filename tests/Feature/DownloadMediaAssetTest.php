<?php

use App\Api\Exceptions\ApiException;
use App\Media\Data\AssetStatus;
use App\Media\Jobs\DownloadMediaAsset;
use App\Media\Storage\AssetKey;
use App\Media\Storage\MediaDownloader;
use App\Models\MediaAsset;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeMediaDownloader;

beforeEach(function () {
    Storage::fake('media');
    config()->set('media.storage.disk', 'media');
    config()->set('media.storage.tmp_dir', sys_get_temp_dir().'/media-tmp-test');
});

function downloadable(array $attributes = []): MediaAsset
{
    return MediaAsset::factory()->create([
        'provider_video_id' => 'dQw4w9WgXcQ',
        'container' => 'mp4',
        'mime_type' => 'video/mp4',
        'format_id' => '18',
        ...$attributes,
    ]);
}

function runDownload(MediaAsset $asset, MediaDownloader $downloader, string $format = '18'): void
{
    app()->instance(MediaDownloader::class, $downloader);

    app()->call([
        new DownloadMediaAsset($asset->asset_key, 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', $format),
        'handle',
    ]);
}

it('stores the downloaded file and records where it landed', function () {
    $asset = downloadable();

    runDownload($asset, FakeMediaDownloader::writing('video-bytes'));

    $stored = $asset->fresh();
    $expected = AssetKey::storagePath('youtube', 'dQw4w9WgXcQ', '18', 'mp4');

    expect($stored->status)->toBe(AssetStatus::Stored)
        ->and($stored->path)->toBe($expected)
        ->and($stored->disk)->toBe('media')
        ->and($stored->bytes)->toBe(strlen('video-bytes'))
        ->and($stored->checksum)->toBe(hash('sha256', 'video-bytes'))
        ->and($stored->downloaded_at)->not->toBeNull();

    Storage::disk('media')->assertExists($expected);
    expect(Storage::disk('media')->get($expected))->toBe('video-bytes');
});

it('removes the temporary file once the bytes are on the disk', function () {
    $asset = downloadable();
    $downloader = FakeMediaDownloader::writing();

    runDownload($asset, $downloader);

    // A leftover per download would fill the local disk long before the media
    // disk filled up.
    expect(is_file($downloader->received[0]['destination']))->toBeFalse();
});

it('removes the temporary file when the download fails', function () {
    $asset = downloadable();

    try {
        runDownload($asset, FakeMediaDownloader::failingWith('ERROR: HTTP Error 503'));
    } catch (ApiException) {
        // Retryable failures are rethrown so the queue tries again.
    }

    expect(glob(sys_get_temp_dir().'/media-tmp-test/*'))->toBe([]);
});

it('downloads once when two workers race for the same asset', function () {
    $asset = downloadable();
    $downloader = FakeMediaDownloader::writing();

    runDownload($asset, $downloader);
    runDownload($asset->fresh(), $downloader);

    expect($downloader->calls)->toBe(1);
});

it('does nothing for an asset that is already stored', function () {
    $asset = MediaAsset::factory()->stored()->create();
    $downloader = FakeMediaDownloader::writing();

    runDownload($asset, $downloader);

    expect($downloader->calls)->toBe(0);
});

it('rethrows a retryable failure so the queue tries again, leaving the row claimable', function () {
    $asset = downloadable();

    expect(fn () => runDownload($asset, FakeMediaDownloader::failingWith('ERROR: HTTP Error 503')))
        ->toThrow(ApiException::class);

    // Marked failed before rethrowing: a row left in `downloading` would make
    // the next queue attempt a silent no-op.
    expect($asset->fresh()->status)->toBe(AssetStatus::Failed)
        ->and($asset->fresh()->error_code)->toBe('download_failed');
});

it('does not retry a permanent failure', function () {
    $asset = downloadable();

    // Retrying a private video spends the remaining attempts to reach exactly
    // the same answer.
    runDownload($asset, FakeMediaDownloader::failingWith('ERROR: Private video'));

    expect($asset->fresh()->status)->toBe(AssetStatus::Failed)
        ->and($asset->fresh()->error_code)->toBe('private_or_restricted');
});

it('records a size refusal as too_large rather than a transfer fault', function () {
    $asset = downloadable();

    runDownload($asset, FakeMediaDownloader::refusing(ApiException::tooLarge()));

    expect($asset->fresh()->status)->toBe(AssetStatus::Failed)
        ->and($asset->fresh()->error_code)->toBe('too_large');

    Storage::disk('media')->assertDirectoryEmpty('/');
});

it('passes the deployment-wide ceiling to the transfer', function () {
    config()->set('media.storage.max_bytes', 1024);

    $asset = downloadable();
    $downloader = FakeMediaDownloader::writing();

    runDownload($asset, $downloader);

    expect($downloader->received[0]['max_bytes'])->toBe(1024);
});

it('downloads from the page url, not a format url', function () {
    // Format URLs expire and are bound to the IP that extracted them, so by the
    // time a queued download runs the one from the resolve may be dead.
    $asset = downloadable();
    $downloader = FakeMediaDownloader::writing();

    runDownload($asset, $downloader);

    expect($downloader->received[0]['url'])->toBe('https://www.youtube.com/watch?v=dQw4w9WgXcQ')
        ->and($downloader->received[0]['format'])->toBe('18');
});

it('gives two variants that chose the same format one stored file', function () {
    $a = downloadable(['max_height' => 720, 'asset_key' => str_repeat('a', 64)]);
    $b = downloadable(['max_height' => 1080, 'asset_key' => str_repeat('b', 64)]);

    $downloader = FakeMediaDownloader::writing();
    runDownload($a, $downloader);
    runDownload($b, $downloader);

    expect($a->fresh()->path)->toBe($b->fresh()->path)
        ->and(Storage::disk('media')->allFiles())->toHaveCount(1);
});

it('stops retrying once the attempt ceiling is reached', function () {
    config()->set('media.storage.max_attempts', 2);

    $asset = downloadable();
    $downloader = FakeMediaDownloader::failingWith('ERROR: HTTP Error 503');

    foreach (range(1, 4) as $ignored) {
        try {
            runDownload($asset->fresh(), $downloader);
        } catch (ApiException) {
            // Expected while attempts remain.
        }
    }

    expect($downloader->calls)->toBe(2)
        ->and($asset->fresh()->attempts)->toBe(2);
});
