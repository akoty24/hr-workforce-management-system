<?php

namespace Tests\Feature\Auth;

use Tests\Concerns\CreatesTenants;
use Tests\Concerns\RefreshTestDatabase;
use Tests\TestCase;

/** Validation messages follow the interface language the SPA sends. */
class LocaleTest extends TestCase
{
    use CreatesTenants, RefreshTestDatabase;

    public function test_arabic_and_english_validation_messages(): void
    {
        config(['tenancy.resolver' => 'subdomain', 'tenancy.base_domain' => 'example.test']);
        $this->createTenant('acme');
        $url = 'http://acme.example.test/api/v1/auth/login';

        $this->postJson($url, [], ['Accept-Language' => 'ar'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'البريد الإلكتروني مطلوب.');

        $this->postJson($url, [], ['Accept-Language' => 'en'])
            ->assertJsonPath('errors.email.0', 'The email field is required.');

        // Unknown languages fall back to English.
        $this->postJson($url, [], ['Accept-Language' => 'fr'])
            ->assertJsonPath('errors.email.0', 'The email field is required.');
    }
}
