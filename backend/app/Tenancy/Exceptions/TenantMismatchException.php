<?php

namespace App\Tenancy\Exceptions;

use LogicException;

/**
 * A write would create, move or modify a record belonging to a tenant other
 * than the one in context.
 */
class TenantMismatchException extends LogicException
{
    public static function forModel(string $model, string $action): self
    {
        return new self(sprintf('Refusing to %s %s: record belongs to a different tenant than the current context.', $action, $model));
    }

    public static function tenantChange(string $model): self
    {
        return new self(sprintf('Refusing to change tenant_id of %s: records cannot move between tenants.', $model));
    }
}
