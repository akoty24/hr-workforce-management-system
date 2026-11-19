<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Owner of the whole installation (not of a company). Signs in on the
 * owner console host with the "platform" guard.
 *
 * @property string $id
 * @property string $name
 * @property string $email
 * @property string $password
 */
class PlatformAdmin extends Authenticatable
{
    use HasUuids;

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['password' => 'hashed'];
    }
}
