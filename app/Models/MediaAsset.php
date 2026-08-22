<?php

namespace App\Models;

use App\Media\Data\AssetStatus;
use Database\Factories\MediaAssetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One downloadable variant of one video, stored on the media disk.
 *
 * Shared by every tenant: the bytes of a public video are the same for
 * everybody, so a single download serves all of them and nothing tenant-derived
 * is recorded here.
 *
 * @property int $id
 * @property string $asset_key
 * @property string $provider
 * @property string $provider_video_id
 * @property int $max_height
 * @property bool $audio_only
 * @property AssetStatus $status
 * @property string|null $disk
 * @property string|null $path
 * @property string|null $format_id
 * @property int|null $bytes
 * @property string|null $mime_type
 * @property string|null $container
 * @property int|null $width
 * @property int|null $height
 * @property bool $has_audio
 * @property bool $has_video
 * @property int|null $duration_ms
 * @property string|null $title
 * @property string|null $author
 * @property string|null $thumbnail_url
 * @property string|null $checksum
 * @property string|null $error_code
 * @property int $attempts
 * @property Carbon|null $downloaded_at
 * @property Carbon|null $last_accessed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable([
    'asset_key', 'provider', 'provider_video_id', 'max_height', 'audio_only',
    'status', 'disk', 'path', 'format_id',
    'bytes', 'mime_type', 'container', 'width', 'height', 'has_audio', 'has_video',
    'duration_ms', 'title', 'author', 'thumbnail_url',
    'checksum', 'error_code', 'attempts', 'downloaded_at', 'last_accessed_at',
])]
class MediaAsset extends Model
{
    /** @use HasFactory<MediaAssetFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AssetStatus::class,
            'audio_only' => 'boolean',
            'has_audio' => 'boolean',
            'has_video' => 'boolean',
            'downloaded_at' => 'datetime',
            'last_accessed_at' => 'datetime',
        ];
    }

    /**
     * Servable means the row claims to be stored *and* names a file.
     *
     * Both halves are checked because a row can be marked stored with a null
     * path only through a bug, and the cost of that bug should be a cache miss
     * rather than a delivery endpoint handing back an empty response.
     */
    public function isServable(): bool
    {
        return $this->status === AssetStatus::Stored
            && $this->path !== null
            && $this->disk !== null;
    }
}
