<?php

namespace App\Models;

enum UserRole: string
{
    /** Manages the company's data and users. */
    case Admin = 'admin';

    /** Read access to company data. */
    case Employee = 'employee';
}
