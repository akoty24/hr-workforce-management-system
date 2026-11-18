<?php

namespace App\Tenancy;

enum TenantStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
}
