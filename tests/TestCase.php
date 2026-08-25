<?php

namespace Tests;

use App\Media\Storage\MediaDownloader;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeMediaDownloader;

abstract class TestCase extends BaseTestCase
{
    /**
     * The suite never touches the network.
     *
     * The extractor is substituted per test, because tests care which fixture
     * comes back. The downloader is substituted here instead, because the queue
     * runs synchronously under test and a resolve now dispatches a download:
     * without this, any test that resolves anything would shell out to yt-dlp
     * and hit YouTube. A test that cares about transfer behaviour rebinds this
     * with its own fake.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Nothing here reaches the internet unless a test faked it first. This
        // is not belt-and-braces: a discovery test that forgets its fake spends
        // 100 units of a real, shared daily quota, and `DataApiClient` catches
        // Throwable — so the call would be swallowed into a 503 and look like a
        // configuration problem rather than a bill.
        Http::preventStrayRequests();

        $this->app->instance(MediaDownloader::class, FakeMediaDownloader::writing());

        // And never writes into the real media disk: a resolve now stores a
        // file, and the suite must not leave one behind in storage/app.
        Storage::fake('media');
    }
}
