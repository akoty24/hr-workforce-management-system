<?php

namespace App\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Restricts every Eloquent query on a tenant-scoped model to the current
 * tenant. Throws (fails closed) when no tenant is in context.
 *
 * Bypassing it (withoutGlobalScope(TenantScope::class)) is reserved for
 * platform-level code and must be reviewed explicitly.
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where($model->qualifyColumn('tenant_id'), app(TenantContext::class)->id());
    }
}
