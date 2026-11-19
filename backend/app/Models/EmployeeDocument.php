<?php

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A file on an employee's record, optionally with an expiry date.
 *
 * @property string $id
 * @property string $employee_id
 * @property string $type
 * @property string $title
 * @property string $path tenant-relative storage path
 * @property string $original_name
 * @property string $mime
 * @property int $size
 * @property Carbon|null $expires_at
 * @property-read Employee $employee
 */
class EmployeeDocument extends Model
{
    use BelongsToTenant, HasUuids;

    public const TYPES = ['contract', 'national_id', 'passport', 'social_insurance', 'certificate', 'medical', 'other'];

    /** Documents expiring within this many days are flagged. */
    public const WARN_DAYS = 30;

    protected $fillable = ['employee_id', 'type', 'title', 'path', 'original_name', 'mime', 'size', 'expires_at', 'uploaded_by'];

    protected function casts(): array
    {
        return ['expires_at' => 'date', 'size' => 'integer'];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
