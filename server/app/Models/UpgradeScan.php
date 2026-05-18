<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UpgradeScan extends Model
{
    protected $fillable = [
        'project_id',
        'source_version',
        'target_version',
        'uri_score',
        'uri_grade',
        'engineering_hours',
        'qa_hours',
        'infra_hours',
        'total_hours_with_contingency',
        'recommended_timeline',
        'blocker_count',
        'critical_count',
        'error_count',
        'warning_count',
        'full_report_json',
    ];

    protected function casts(): array
    {
        return [
            'uri_score'        => 'float',
            'full_report_json' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function scopeForTarget($query, string $version)
    {
        return $query->where('target_version', $version);
    }

    public function getGradeColor(): string
    {
        return match ($this->uri_grade) {
            'A'     => 'success',
            'B'     => 'info',
            'C'     => 'warning',
            'D'     => 'warning',
            'F'     => 'error',
            default => 'info',
        };
    }
}
