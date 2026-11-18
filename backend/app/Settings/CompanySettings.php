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
    ];

    public function __construct(private readonly TenantContext $context) {}

    /**
     * @return array{timezone: string, work_start: string, work_end: string, grace_minutes: int, require_location: bool}
     */
    public function all(): array
    {
        $stored = $this->tenant()->settings ?? [];

        $settings = array_merge(
            self::DEFAULTS + ['timezone' => (string) config('hr.timezone', 'UTC')],
            array_intersect_key($stored, self::DEFAULTS + ['timezone' => null]),
        );

        return [
            'timezone' => in_array($settings['timezone'], DateTimeZone::listIdentifiers(), true) ? $settings['timezone'] : 'UTC',
            'work_start' => (string) $settings['work_start'],
            'work_end' => (string) $settings['work_end'],
            'grace_minutes' => (int) $settings['grace_minutes'],
            'require_location' => (bool) $settings['require_location'],
        ];
    }

    public function timezone(): string
    {
        return $this->all()['timezone'];
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
