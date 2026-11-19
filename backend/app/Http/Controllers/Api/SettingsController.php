<?php

namespace App\Http\Controllers\Api;

use App\Settings\CompanySettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Company settings: everyone may read them (the attendance page shows the
 * schedule); admins and HR change them.
 */
class SettingsController
{
    public function __construct(private readonly CompanySettings $settings) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->settings->all()]);
    }

    public function update(Request $request): JsonResponse
    {
        abort_unless($request->user()->managesHr(), 403);

        $data = $request->validate([
            'timezone' => ['required', 'timezone:all'],
            'work_start' => ['required', 'date_format:H:i'],
            'work_end' => ['required', 'date_format:H:i', 'after:work_start'],
            'grace_minutes' => ['required', 'integer', 'min:0', 'max:240'],
            'require_location' => ['required', 'boolean'],
            // Payroll options (optional so older clients keep working).
            'payroll_day_divisor' => ['sometimes', 'integer', 'min:20', 'max:31'],
            'deduct_absence' => ['sometimes', 'boolean'],
            'late_multiplier' => ['sometimes', 'numeric', 'min:0', 'max:10'],
            'overtime_multiplier' => ['sometimes', 'numeric', 'min:0', 'max:10'],
            'social_insurance_enabled' => ['sometimes', 'boolean'],
            'social_insurance_rate' => ['sometimes', 'numeric', 'min:0', 'max:50'],
            'insurable_min' => ['sometimes', 'numeric', 'min:0'],
            'insurable_max' => ['sometimes', 'numeric', 'gte:insurable_min'],
            'income_tax_enabled' => ['sometimes', 'boolean'],
        ]);

        $this->settings->update($data);

        return response()->json(['data' => $this->settings->all()]);
    }
}
