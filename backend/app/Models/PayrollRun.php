<?php

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A month's payroll for the company: draft (editable) or finalized (locked).
 *
 * @property string $id
 * @property int $year
 * @property int $month
 * @property string $status
 * @property string|null $created_by
 * @property Carbon|null $finalized_at
 */
class PayrollRun extends Model
{
    use BelongsToTenant, HasUuids;

    public const DRAFT = 'draft';

    public const FINALIZED = 'finalized';

    protected $fillable = ['year', 'month', 'status', 'created_by', 'finalized_at'];

    protected $attributes = ['status' => self::DRAFT];

    protected function casts(): array
    {
        return ['year' => 'integer', 'month' => 'integer', 'finalized_at' => 'datetime'];
    }

    /** @return HasMany<Payslip, $this> */
    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isDraft(): bool
    {
        return $this->status === self::DRAFT;
    }
}
