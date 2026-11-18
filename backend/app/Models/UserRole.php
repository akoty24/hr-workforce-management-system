<?php

namespace App\Models;

enum UserRole: string
{
    /** System administrator: everything HR can do, plus user accounts and roles. */
    case Admin = 'admin';

    /** HR manager: employees, departments, leave setup, decides all requests. No user/role management. */
    case Hr = 'hr';

    /** Employee who also sees their direct reports and decides their leave. */
    case TeamLeader = 'team_leader';

    /** Read access to company data and own records. */
    case Employee = 'employee';
}
