<?php

namespace App\Models;

use App\Media\Data\DimensionSource;
use Database\Factories\VideoDimensionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $provider
 * @property string $provider_video_id
 * @property int $width
 * @property int $height
 * @property DimensionSource $source
 * @property Carbon|null $created_at
 */
#[Fillable(['provider', 'provider_video_id', 'width', 'height', 'source', 'created_at'])]
class VideoDimension extends Model
{
    /** @use HasFactory<VideoDimensionFactory> */
    use HasFactory;

    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => DimensionSource::class,
            'created_at' => 'datetime',
        ];
    }
}
