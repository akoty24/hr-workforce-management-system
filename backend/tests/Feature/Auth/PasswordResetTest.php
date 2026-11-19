<?php

namespace Tests\Feature\Auth;

use App\Models\PasswordResetToken;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ResetPasswordLink;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\RefreshTestDatabase;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use CreatesTenants, RefreshTestDatabase;

    private Tenant $acme;

    private Tenant $globex;

    private User $sara;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'tenancy.resolver' => 'subdomain',
            'tenancy.base_domain' => 'example.test',
            'app.url' => 'http://localhost:5173',
        ]);
        Notification::fake();

        $this->acme = $this->createTenant('acme');
        $this->globex = $this->createTenant('globex');
        $this->sara = $this->asTenant($this->acme, fn () => User::factory()->create([
            'email' => 'sara@acme.test', 'password' => 'OldPassword123',
        ]));
    }

    private function url(string $path, string $tenant = 'acme'): string
    {
        return "http://{$tenant}.example.test/api/v1/auth{$path}";
    }

    /** Ask for a link and return the plain token from the emailed URL. */
    private function requestToken(string $email = 'Sara@Acme.test'): string
    {
        $this->postJson($this->url('/forgot-password'), ['email' => $email])->assertOk();

        $token = null;
        Notification::assertSentTo($this->sara, ResetPasswordLink::class, function (ResetPasswordLink $n) use (&$token) {
            $this->assertStringStartsWith('http://acme.example.test:5173/reset-password?', $n->url);
            parse_str((string) parse_url($n->url, PHP_URL_QUERY), $query);
            $this->assertSame('sara@acme.test', $query['email']);
            $token = $query['token'];

            return true;
        });

        return $token;
    }

    public function test_full_flow_changes_the_password_once(): void
    {
        $token = $this->requestToken();

        // Only a hash is stored.
        $this->asTenant($this->acme, fn () => $this->assertSame(
            PasswordResetToken::hash($token),
            PasswordResetToken::query()->value('token_hash'),
        ));

        $this->postJson($this->url('/reset-password'), [
            'token' => $token, 'email' => 'sara@acme.test',
            'password' => 'NewPassword456', 'password_confirmation' => 'NewPassword456',
        ])->assertOk();

        $this->assertTrue(Hash::check('NewPassword456', $this->sara->fresh()->password));
        $this->postJson($this->url('/login'), ['email' => 'sara@acme.test', 'password' => 'NewPassword456'])->assertOk();

        // The link works once.
        $this->postJson($this->url('/reset-password'), [
            'token' => $token, 'email' => 'sara@acme.test',
            'password' => 'Another-pass789', 'password_confirmation' => 'Another-pass789',
        ])->assertUnprocessable()->assertJsonValidationErrors('token');
    }

    public function test_unknown_or_inactive_emails_get_the_same_answer_and_no_email(): void
    {
        $known = $this->postJson($this->url('/forgot-password'), ['email' => 'sara@acme.test'])->json('message');
        $unknown = $this->postJson($this->url('/forgot-password'), ['email' => 'nobody@acme.test'])->assertOk()->json('message');
        $this->assertSame($known, $unknown);

        // Same email in another company: nothing is sent to Acme's Sara.
        $this->postJson($this->url('/forgot-password', 'globex'), ['email' => 'sara@acme.test'])->assertOk();
        Notification::assertSentToTimes($this->sara, ResetPasswordLink::class, 1);

        $this->asTenant($this->acme, fn () => $this->sara->forceFill(['is_active' => false])->save());
        $this->postJson($this->url('/forgot-password'), ['email' => 'sara@acme.test'])->assertOk();
        Notification::assertSentToTimes($this->sara, ResetPasswordLink::class, 1);
    }

    public function test_expired_wrong_email_and_weak_passwords_are_rejected(): void
    {
        $token = $this->requestToken();
        $reset = fn (array $data) => $this->postJson($this->url('/reset-password'), $data + [
            'token' => $token, 'email' => 'sara@acme.test',
            'password' => 'NewPassword456', 'password_confirmation' => 'NewPassword456',
        ]);

        $reset(['email' => 'other@acme.test'])->assertUnprocessable()->assertJsonValidationErrors('token');
        $reset(['password' => 'short', 'password_confirmation' => 'short'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $reset(['password_confirmation' => 'Different456'])->assertUnprocessable()->assertJsonValidationErrors('password');

        // A token from one company does not work in another.
        $this->postJson($this->url('/reset-password', 'globex'), [
            'token' => $token, 'email' => 'sara@acme.test',
            'password' => 'NewPassword456', 'password_confirmation' => 'NewPassword456',
        ])->assertUnprocessable()->assertJsonValidationErrors('token');

        $this->travel(PasswordResetToken::LIFETIME_MINUTES + 1)->minutes();
        $reset([])->assertUnprocessable()->assertJsonValidationErrors('token');

        $this->assertTrue(Hash::check('OldPassword123', $this->sara->fresh()->password));
    }

    public function test_a_new_request_replaces_the_previous_link(): void
    {
        $first = $this->requestToken();
        Notification::fake();
        $second = $this->requestToken();

        $this->assertNotSame($first, $second);
        $this->asTenant($this->acme, fn () => $this->assertSame(1, PasswordResetToken::query()->count()));
    }

    public function test_requests_are_throttled(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson($this->url('/forgot-password'), ['email' => 'nobody@acme.test'])->assertOk();
        }

        $this->postJson($this->url('/forgot-password'), ['email' => 'nobody@acme.test'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
    }
}
