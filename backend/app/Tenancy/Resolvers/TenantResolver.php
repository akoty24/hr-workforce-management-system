<?php

namespace App\Tenancy\Resolvers;

use App\Models\Tenant;
use Illuminate\Http\Request;

interface TenantResolver
{
    /**
     * Identify the tenant a request is for, or null when it cannot be
     * determined unambiguously (the caller must then fail closed).
     *
     * May return a non-active tenant; the caller decides how to treat it.
     */
    public function resolve(Request $request): ?Tenant;
}
