<?php

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $name
 * @property int|null $days_per_year null = unlimited
 * @property bool $prorate_first_year allowance is prorated in the hire year
 * @property bool $is_active
 */
class LeaveType extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['name', 'days_per_year', 'prorate_first_year', 'is_paid', 'is_active'];

    protected $attributes = ['is_active' => true, 'prorate_first_year' => false, 'is_paid' => true];

    protected function casts(): array
    {
        return [
            'days_per_year' => 'integer',
            'is_paid' => 'boolean',
            'prorate_first_year' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<LeaveRequest, $this>
     */
    public function requests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }
}
