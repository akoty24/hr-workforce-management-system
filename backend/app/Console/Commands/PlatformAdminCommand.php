<?php

namespace App\Console\Commands;

use App\Models\PlatformAdmin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/** One-time setup of the system owner who uses the owner console. */
class PlatformAdminCommand extends Command
{
    protected $signature = 'platform:admin
        {email : Owner email}
        {--name=Owner : Display name}
        {--password= : Leave empty to generate a strong password}';

    protected $description = 'Create the owner console account (or reset its password)';

    public function handle(): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));
        $password = (string) ($this->option('password') ?: Str::password(16, symbols: false));
        $generated = ! $this->option('password');

        $validator = Validator::make(['email' => $email, 'password' => $password], [
            'email' => ['required', 'email', 'max:255'],
            'password' => [Password::min(12)->letters()->numbers()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $admin = PlatformAdmin::query()->firstOrNew(['email' => $email]);
        $existed = $admin->exists;
        $admin->fill(['name' => $existed ? $admin->name : (string) $this->option('name'), 'password' => $password])->save();

        $this->components->info($existed ? 'Owner password reset.' : 'Owner account created.');
        $this->components->twoColumnDetail('Email', $admin->email);

        if ($generated) {
            $this->components->twoColumnDetail('Password', $password);
            $this->components->warn('Store this password now; it is not shown again.');
        }

        return self::SUCCESS;
    }
}
