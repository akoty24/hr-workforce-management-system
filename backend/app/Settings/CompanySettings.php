<?php

namespace App\Settings;

use App\Models\Tenant;
use App\Tenancy\TenantContext;
use DateTimeZone;

/**
 * Per-company settings stored in tenants.settings (JSON), with defaults.
 */
final class CompanySettings
{
    /** @var array<string, mixed> */
    private const DEFAULTS = [
        'work_start' => '09:00',
        'work_end' => '17:00',
        'grace_minutes' => 15,
        'require_location' => true,
        // Payroll (Egyptian defaults; every company can change them).
        'payroll_day_divisor' => 30,          // day rate = monthly salary / this
        'deduct_absence' => true,             // working day without check-in or leave
        'late_multiplier' => 1.0,             // late minutes × minute rate × this (0 = off)
        'overtime_multiplier' => 1.35,        // overtime minutes × minute rate × this (0 = off)
        'social_insurance_enabled' => true,
        'social_insurance_rate' => 11.0,      // employee share, % of insurable wage
        'insurable_min' => 2300,
        'insurable_max' => 14500,
        'income_tax_enabled' => true,
    ];

    /** Payroll keys and their types, for casting stored JSON values. */
    private const TYPES = [
        'payroll_day_divisor' => 'int', 'deduct_absence' => 'bool', 'late_multiplier' => 'float',
        'overtime_multiplier' => 'float', 'social_insurance_enabled' => 'bool', 'social_insurance_rate' => 'float',
        'insurable_min' => 'float', 'insurable_max' => 'float', 'income_tax_enabled' => 'bool',
    ];

    public function __construct(private readonly TenantContext $context) {}

    /**
     * @return array<string, mixed> timezone, schedule, and the payroll options in DEFAULTS
     */
    public function all(): array
    {
        $stored = $this->tenant()->settings ?? [];

        $settings = array_merge(
            self::DEFAULTS + ['timezone' => (string) config('hr.timezone', 'UTC')],
            array_intersect_key($stored, self::DEFAULTS + ['timezone' => null]),
        );

        $payroll = [];
        foreach (self::TYPES as $key => $type) {
            $payroll[$key] = match ($type) {
                'int' => (int) $settings[$key],
                'float' => (float) $settings[$key],
                default => (bool) $settings[$key],
            };
        }

        return [
            'timezone' => in_array($settings['timezone'], DateTimeZone::listIdentifiers(), true) ? $settings['timezone'] : 'UTC',
            'work_start' => (string) $settings['work_start'],
            'work_end' => (string) $settings['work_end'],
            'grace_minutes' => (int) $settings['grace_minutes'],
            'require_location' => (bool) $settings['require_location'],
        ] + $payroll;
    }

    public function timezone(): string
    {
        return $this->all()['timezone'];
    }

    /** Scheduled working minutes per day (work_end − work_start). */
    public function dailyMinutes(): int
    {
        $all = $this->all();
        [$sh, $sm] = array_map('intval', explode(':', $all['work_start']));
        [$eh, $em] = array_map('intval', explode(':', $all['work_end']));

        return max(1, ($eh * 60 + $em) - ($sh * 60 + $sm));
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function update(array $values): void
    {
        $tenant = $this->tenant();
        $tenant->settings = array_merge($tenant->settings ?? [], $values);
        $tenant->save();
    }

    private function tenant(): Tenant
    {
        return $this->context->current();
    }
}
