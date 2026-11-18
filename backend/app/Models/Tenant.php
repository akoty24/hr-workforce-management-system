<?php

namespace App\Models;

use App\Tenancy\TenantStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A company using the system. Not itself tenant-scoped.
 *
 * @property string $id UUIDv7
 * @property string $slug
 * @property string $name
 * @property TenantStatus $status
 */
class Tenant extends Model
{
    use HasUuids; // UUIDv7 in Laravel 12

    protected $fillable = ['slug', 'name', 'status'];

    protected $attributes = [
        'status' => 'active',
    ];

    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
        ];
    }

    public function isActive(): bool
    {
        return $this->status === TenantStatus::Active;
    }

    /**
     * @param  Builder<Tenant>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', TenantStatus::Active->value);
    }
}
