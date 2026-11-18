<?php

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One entry of an employee's salary history.
 *
 * @property string $id
 * @property string $employee_id
 * @property string $amount decimal string
 * @property string $currency
 * @property Carbon $effective_from
 */
class Salary extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['employee_id', 'amount', 'currency', 'effective_from', 'note', 'created_by'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'effective_from' => 'date',
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
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Salary in force on a date for each employee (latest effective_from <= date).
     *
     * @param  list<string>|null  $employeeIds
     * @return array<string, Salary> keyed by employee id
     */
    public static function currentFor(?array $employeeIds = null, ?string $date = null): array
    {
        $date ??= now()->toDateString();

        $rows = self::query()
            ->when($employeeIds !== null, fn ($q) => $q->whereIn('employee_id', $employeeIds))
            ->whereDate('effective_from', '<=', $date)
            ->orderBy('effective_from')
            ->orderBy('created_at')
            ->get();

        $current = [];
        foreach ($rows as $row) {
            $current[$row->employee_id] = $row; // later rows overwrite earlier ones
        }

        return $current;
    }
}
