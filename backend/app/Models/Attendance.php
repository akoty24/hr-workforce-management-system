<?php

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Check-in / check-out of one employee on one company-local day.
 *
 * @property string $id
 * @property string $employee_id
 * @property Carbon $date
 * @property Carbon|null $check_in_at UTC
 * @property Carbon|null $check_out_at UTC
 * @property int $late_minutes
 * @property int|null $worked_minutes
 */
class Attendance extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'employee_id', 'date',
        'check_in_at', 'check_in_latitude', 'check_in_longitude', 'check_in_accuracy',
        'check_out_at', 'check_out_latitude', 'check_out_longitude', 'check_out_accuracy',
        'late_minutes', 'worked_minutes', 'note', 'edited_by',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'check_in_at' => 'datetime',
            'check_out_at' => 'datetime',
            'check_in_latitude' => 'float',
            'check_in_longitude' => 'float',
            'check_out_latitude' => 'float',
            'check_out_longitude' => 'float',
            'check_in_accuracy' => 'integer',
            'check_out_accuracy' => 'integer',
            'late_minutes' => 'integer',
            'worked_minutes' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by');
    }
}
