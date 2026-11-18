<?php

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $employee_id
 * @property string $leave_type_id
 * @property Carbon $start_date
 * @property Carbon $end_date
 * @property int $days
 * @property LeaveStatus $status
 */
class LeaveRequest extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'employee_id', 'leave_type_id', 'start_date', 'end_date', 'days', 'reason',
        'status', 'decided_by', 'decided_at', 'decision_note',
    ];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'days' => 'integer',
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
     * @return BelongsTo<LeaveType, $this>
     */
    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
