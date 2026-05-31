<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Source extends Model
{
    protected $fillable = [
        'name',
        'url',
        'feed_url',
        'logo_url',
        'category_id',
        'is_active',
        'prestige',
        'last_fetched_at',
        'last_fetch_outcome',
        'last_error_type',
        'last_error_message',
        'last_successful_fetch_at',
        'last_failed_fetch_at',
        'consecutive_failures',
        'last_items_found',
        'last_valid_items',
        'last_duplicate_count',
        'last_duration_ms',
    ];

    protected $appends = ['health_status'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_fetched_at' => 'datetime',
            'last_successful_fetch_at' => 'datetime',
            'last_failed_fetch_at' => 'datetime',
        ];
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function articles()
    {
        return $this->hasMany(Article::class);
    }

    public function fetchLogs()
    {
        return $this->hasMany(SourceFetchLog::class)->latest();
    }

    public function getHealthStatusAttribute(): string
    {
        if (! $this->is_active) {
            return 'disabled';
        }

        if (! $this->last_successful_fetch_at && ! $this->last_failed_fetch_at) {
            return 'never_fetched';
        }

        if (($this->consecutive_failures ?? 0) >= config('source_health.critical_after_failures', 3)) {
            return 'critical';
        }

        if (($this->consecutive_failures ?? 0) > 0) {
            return 'failed';
        }

        if ($this->last_successful_fetch_at?->lt(now()->subHours(config('source_health.stale_after_hours', 6)))) {
            return 'stale';
        }

        if ($this->last_fetch_outcome === 'warning' || $this->hasWarningMetrics()) {
            return 'warning';
        }

        return 'healthy';
    }

    public function hasWarningMetrics(): bool
    {
        if (($this->last_items_found ?? 0) === 0) {
            return true;
        }

        $warning = config('source_health.warning');
        $validRate = $this->last_items_found > 0 ? ($this->last_valid_items ?? 0) / $this->last_items_found : 0;
        $duplicateRate = ($this->last_valid_items ?? 0) > 0 ? ($this->last_duplicate_count ?? 0) / $this->last_valid_items : 0;

        return $validRate < ($warning['valid_item_rate_below'] ?? 0.8)
            || $duplicateRate > ($warning['duplicate_rate_above'] ?? 0.9)
            || ($this->last_duration_ms ?? 0) > ($warning['slow_duration_ms_above'] ?? 10000);
    }
}
