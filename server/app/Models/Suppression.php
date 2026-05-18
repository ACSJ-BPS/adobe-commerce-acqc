<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Suppression extends Model
{
    protected $fillable = [
        'project_id', 'rule_id', 'reason', 'suppressed_by_email',
        'acknowledged_by', 'suppressed_at', 'review_by', 'dashboard_acked_at',
    ];

    protected $appends = ['is_expired'];

    protected function casts(): array
    {
        return [
            'suppressed_at'     => 'date',
            'review_by'         => 'date',
            'dashboard_acked_at' => 'datetime',
        ];
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function acknowledgedByUser()
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    public function scopeExpired($query)
    {
        return $query->whereNotNull('review_by')
                     ->where('review_by', '<', now()->toDateString());
    }

    public function getIsExpiredAttribute(): bool
    {
        return $this->review_by !== null && $this->review_by->isPast();
    }
}
