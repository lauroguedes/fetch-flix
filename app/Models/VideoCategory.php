<?php

namespace App\Models;

use Database\Factories\VideoCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A curated discovery feed this deployment serves.
 *
 * @property int $id
 * @property string $slug
 * @property string $label
 * @property string $provider
 * @property string $query_template
 * @property string|null $region_code
 * @property bool $is_active
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['slug', 'label', 'provider', 'query_template', 'region_code', 'is_active', 'sort_order'])]
class VideoCategory extends Model
{
    /** @use HasFactory<VideoCategoryFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @param  Builder<VideoCategory>  $query
     * @return Builder<VideoCategory>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort_order');
    }
}
