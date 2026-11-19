<?php

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * A person who can sign in. Always belongs to exactly one tenant; all
 * lookups (including the session guard's) are tenant-scoped.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $email
 * @property UserRole $role
 * @property bool $is_active
 * @property bool $must_change_password
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use BelongsToTenant, HasFactory, HasUuids, Notifiable;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'is_active',
        'must_change_password',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $attributes = [
        'role' => 'employee',
        'is_active' => true,
        'must_change_password' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isHr(): bool
    {
        return $this->role === UserRole::Hr;
    }

    /**
     * Admins and HR manage company HR data and decide every request.
     * Only admins manage user accounts and roles.
     */
    public function managesHr(): bool
    {
        return $this->isAdmin() || $this->isHr();
    }

    public function isTeamLeader(): bool
    {
        return $this->role === UserRole::TeamLeader;
    }

    /**
     * Whether this user is the team leader (direct manager) of $employee.
     */
    public function leads(Employee $employee): bool
    {
        return $this->isTeamLeader()
            && $this->employee !== null
            && $employee->manager_id === $this->employee->id;
    }

    /**
     * The employee record linked to this sign-in account, if any.
     *
     * @return HasOne<Employee, $this>
     */
    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }
}
