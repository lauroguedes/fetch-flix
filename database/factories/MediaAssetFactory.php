<?php

namespace Database\Factories;

use App\Media\Data\AssetStatus;
use App\Media\Storage\AssetKey;
use App\Models\MediaAsset;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MediaAsset>
 */
class MediaAssetFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $videoId = Str::random(11);

        return [
            'asset_key' => (string) AssetKey::for('youtube', $videoId, 720, false),
            'provider' => 'youtube',
            'provider_video_id' => $videoId,
            'max_height' => 720,
            'audio_only' => false,
            'status' => AssetStatus::Pending,
            'disk' => null,
            'path' => null,
            'format_id' => null,
            'bytes' => null,
            'mime_type' => null,
            'container' => null,
            'width' => null,
            'height' => null,
            'has_audio' => true,
            'has_video' => true,
            'duration_ms' => 213000,
            'title' => fake()->sentence(4),
            'author' => fake()->name(),
            'thumbnail_url' => 'https://i.ytimg.com/vi/'.$videoId.'/hqdefault.jpg',
        ];
    }

    public function stored(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AssetStatus::Stored,
            'disk' => 'media',
            'path' => 'youtube/ab/'.$attributes['provider_video_id'].'/18.mp4',
            'format_id' => '18',
            'bytes' => 10_485_760,
            'mime_type' => 'video/mp4',
            'container' => 'mp4',
            'width' => 640,
            'height' => 360,
            'checksum' => hash('sha256', 'fixture'),
            'serve_count' => 0,
            'downloaded_at' => now(),
            'last_accessed_at' => now(),
        ]);
    }

    public function downloading(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AssetStatus::Downloading,
            'attempts' => 1,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AssetStatus::Failed,
            'error_code' => 'download_failed',
            'attempts' => 3,
        ]);
    }

    public function audioOnly(): static
    {
        return $this->state(fn (array $attributes) => [
            'audio_only' => true,
            'has_video' => false,
            'asset_key' => (string) AssetKey::for('youtube', $attributes['provider_video_id'], $attributes['max_height'], true),
        ]);
    }
}
