<?php

namespace App\Console\Commands;

use App\Tenancy\Actions\CreateTenant;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class CreateTenantCommand extends Command
{
    protected $signature = 'tenant:create {slug : DNS-safe identifier, e.g. "acme"} {name : Company display name}';

    protected $description = 'Create a tenant (company)';

    public function handle(CreateTenant $createTenant): int
    {
        try {
            $tenant = $createTenant->handle($this->argument('slug'), $this->argument('name'));
        } catch (ValidationException $e) {
            foreach ($e->validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $this->components->info(sprintf('Tenant "%s" created (id %s).', $tenant->slug, $tenant->id));

        return self::SUCCESS;
    }
}
