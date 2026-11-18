<?php

namespace App\Tenancy\Concerns;

use App\Models\Tenant;
use App\Tenancy\Exceptions\TenantMismatchException;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Makes an Eloquent model tenant-owned.
 *
 * Guarantees (all fail closed — no tenant in context throws):
 *  - reads:   every query is filtered to the current tenant (TenantScope);
 *  - create:  tenant_id is filled from context; a different tenant_id is rejected;
 *  - update:  only rows of the current tenant; tenant_id can never change;
 *  - delete:  only rows of the current tenant.
 *
 * The table must have a tenant_id column (Blueprint::tenantId()).
 *
 * @mixin Model
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model): void {
            $current = app(TenantContext::class)->id();

            if ($model->getAttribute('tenant_id') === null) {
                $model->setAttribute('tenant_id', $current);
            } elseif ($model->getAttribute('tenant_id') !== $current) {
                throw TenantMismatchException::forModel($model::class, 'create');
            }
        });

        static::updating(function (Model $model): void {
            if ($model->isDirty('tenant_id')) {
                throw TenantMismatchException::tenantChange($model::class);
            }

            static::assertBelongsToCurrentTenant($model, 'update');
        });

        static::deleting(function (Model $model): void {
            static::assertBelongsToCurrentTenant($model, 'delete');
        });
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    private static function assertBelongsToCurrentTenant(Model $model, string $action): void
    {
        if ($model->getOriginal('tenant_id') !== app(TenantContext::class)->id()) {
            throw TenantMismatchException::forModel($model::class, $action);
        }
    }
}
