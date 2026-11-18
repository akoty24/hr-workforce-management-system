<?php

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Hourly permission: leave for part of a single working day.
 *
 * @property string $id
 * @property string $employee_id
 * @property Carbon $date
 * @property string $start_time HH:MM:SS
 * @property string $end_time HH:MM:SS
 * @property int $minutes
 * @property LeaveStatus $status
 */
class ShortLeave extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'employee_id', 'date', 'start_time', 'end_time', 'minutes', 'reason',
        'status', 'decided_by', 'decided_at', 'decision_note',
    ];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'minutes' => 'integer',
            'status' => LeaveStatus::class,
            'decided_at' => 'datetime',
        ];
    }

    public function isPending(): bool
    {
        return $this->status === LeaveStatus::Pending;
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
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
