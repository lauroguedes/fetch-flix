<?php

namespace App\Models;

use Database\Factories\ExtractorHealthFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $provider
 * @property string|null $yt_dlp_version
 * @property bool $ok
 * @property string|null $failure_code
 * @property string|null $notes
 * @property int|null $duration_ms
 * @property Carbon $checked_at
 */
#[Fillable(['provider', 'yt_dlp_version', 'ok', 'failure_code', 'notes', 'duration_ms', 'checked_at'])]
class ExtractorHealth extends Model
{
    /** @use HasFactory<ExtractorHealthFactory> */
    use HasFactory;

    protected $table = 'extractor_health';

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ok' => 'boolean',
            'checked_at' => 'datetime',
        ];
    }
}
