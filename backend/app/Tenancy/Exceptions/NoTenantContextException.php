<?php

namespace App\Tenancy\Exceptions;

use LogicException;

/**
 * Tenant-scoped data was accessed with no tenant in context.
 *
 * This is a programming error, never a user error: tenancy fails closed
 * instead of silently returning or writing data across tenants.
 */
class NoTenantContextException extends LogicException
{
    public function __construct(string $message = 'No tenant in context. Tenant-scoped data requires a resolved tenant (request middleware, queued job payload, or TenantContext::run()).')
    {
        parent::__construct($message);
    }
}
