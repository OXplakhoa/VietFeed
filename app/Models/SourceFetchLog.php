<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SourceFetchLog extends Model
{
    protected $fillable = [
        'source_id',
        'type',
        'status',
        'http_status',
        'items_found',
        'items_created',
        'items_updated',
        'valid_items',
        'invalid_items',
        'missing_image_count',
        'date_parse_error_count',
        'duplicate_count',
        'error_type',
        'error_message',
        'duration_ms',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function source()
    {
        return $this->belongsTo(Source::class);
    }
}
