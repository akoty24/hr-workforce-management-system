<?php

namespace App\Models;

enum UserRole: string
{
    /** Manages the company's data and users. */
    case Admin = 'admin';

    /** Employee who also sees their direct reports and decides their leave. */
    case TeamLeader = 'team_leader';

    /** Read access to company data and own records. */
    case Employee = 'employee';
}
