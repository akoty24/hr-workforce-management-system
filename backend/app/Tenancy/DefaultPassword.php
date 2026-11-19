<?php

namespace App\Tenancy;

use App\Models\Tenant;

/**
 * Starting password for logins created by an administrator: "{slug}Password123"
 * (e.g. "elnadyPassword123"). It is predictable, so every account that gets it
 * is flagged must_change_password and has to choose its own at first sign-in.
 */
final class DefaultPassword
{
    public static function for(Tenant $tenant): string
    {
        return $tenant->slug.'Password123';
    }
}
