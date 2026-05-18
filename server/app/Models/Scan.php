<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Scan extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id', 'triggered_by', 'status', 'score', 'grade',
        'total_files', 'total_lines',
        'critical_count', 'error_count', 'warning_count', 'info_count',
        'total_rules_evaluated', 'violated_rules_count',
        'category_breakdown', 'sub_indexes', 'error_message',
        'started_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'category_breakdown' => 'array',
            'sub_indexes'        => 'array',
            'started_at'         => 'datetime',
            'completed_at'       => 'datetime',
        ];
    }

    public function project()   { return $this->belongsTo(Project::class); }
    public function triggeredBy(){ return $this->belongsTo(User::class, 'triggered_by'); }
    public function findings()  { return $this->hasMany(Finding::class); }

    public function getHealthColorAttribute(): string
    {
        return match(true) {
            $this->score >= 80 => 'green',
            $this->score >= 50 => 'yellow',
            default            => 'red',
        };
    }
}
