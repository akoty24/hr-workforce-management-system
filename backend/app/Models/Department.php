<?php

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string|null $description
 */
class Department extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['name', 'description'];
}
