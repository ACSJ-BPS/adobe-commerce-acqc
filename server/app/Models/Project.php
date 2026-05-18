<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Project extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name', 'dr_no', 'description', 'repo_url', 'local_path',
        'tech_stack', 'status', 'created_by',
    ];

    protected function casts(): array
    {
        return ['tech_stack' => 'array'];
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function members()
    {
        return $this->belongsToMany(User::class, 'project_user')
                    ->withPivot('role')
                    ->withTimestamps();
    }

    public function scans()
    {
        return $this->hasMany(Scan::class);
    }

    public function latestScan()
    {
        return $this->hasOne(Scan::class)->latestOfMany()->where('status', 'complete');
    }

    public function suppressions()
    {
        return $this->hasMany(Suppression::class);
    }

    public function upgradeScans(): HasMany
    {
        return $this->hasMany(UpgradeScan::class);
    }

    public function latestUpgradeScan(?string $targetVersion = null): HasOne
    {
        return $this->hasOne(UpgradeScan::class)
            ->when($targetVersion, fn ($q) => $q->where('target_version', $targetVersion))
            ->latestOfMany();
    }

    public function scopeVisibleTo($query, User $user)
    {
        if ($user->isAdmin()) {
            return $query;
        }
        return $query->whereHas('members', fn($q) => $q->where('users.id', $user->id))
                     ->orWhere('created_by', $user->id);
    }
}
