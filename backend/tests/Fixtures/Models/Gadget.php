<?php

namespace Tests\Fixtures\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Gadget extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'fixture_gadgets';

    protected $fillable = ['widget_id', 'tenant_id'];
}
