<?php

namespace Tests\Fixtures\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Widget extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'fixture_widgets';

    protected $fillable = ['name', 'tenant_id'];
}
