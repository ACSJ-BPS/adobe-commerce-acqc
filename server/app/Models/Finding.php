<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Finding extends Model
{
    protected $fillable = [
        'scan_id', 'file_path', 'line_number', 'rule_id', 'rule_name',
        'severity', 'category', 'description', 'recommendation',
        'fingerprint', 'resolved_by', 'resolved_at',
    ];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    public function scan()     { return $this->belongsTo(Scan::class); }
    public function resolver() { return $this->belongsTo(User::class, 'resolved_by'); }

    public function scopeUnresolved($query) { return $query->whereNull('resolved_at'); }
    public function scopeResolved($query)   { return $query->whereNotNull('resolved_at'); }
}
