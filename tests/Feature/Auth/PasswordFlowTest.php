<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\PasswordChangedNotification;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Manual test cases covered: PW-01 to PW-07.
 */
class PasswordFlowTest extends TestCase
{
    use RefreshDatabase;

    private const GENERIC_FORGOT_MESSAGE = 'If an account exists for that email, a password reset link has been sent.';
    private const INVALID_LINK_MESSAGE = 'This reset link is invalid or has expired. Please request a new one.';

    // ---------------------------------------------------------------- PW-01 / PW-02

    public function test_forgot_password_sends_a_reset_link_to_a_registered_email(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->postJson('/api/auth/forgot-password', ['email' => $user->email])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', self::GENERIC_FORGOT_MESSAGE);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_forgot_password_gives_the_same_message_and_sends_nothing_for_an_unregistered_email(): void
    {
        Notification::fake();

        $this->postJson('/api/auth/forgot-password', ['email' => 'nobody@example.com'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', self::GENERIC_FORGOT_MESSAGE);

        Notification::assertNothingSent();
    }

    public function test_forgot_password_requires_a_valid_email(): void
    {
        $this->postJson('/api/auth/forgot-password', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->postJson('/api/auth/forgot-password', ['email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    // ---------------------------------------------------------------- PW-04 (validate link)

    public function test_validate_token_accepts_a_fresh_token(): void
    {
        $user = User::factory()->create();
        $token = Password::broker()->createToken($user);

        $this->postJson('/api/auth/reset-password/validate', ['token' => $token, 'email' => $user->email])
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_validate_token_rejects_wrong_token_and_unknown_email_with_the_same_message(): void
    {
        $user = User::factory()->create();
        Password::broker()->createToken($user);

        $this->postJson('/api/auth/reset-password/validate', ['token' => 'wrong-token', 'email' => $user->email])
            ->assertStatus(422)
            ->assertJsonPath('message', self::INVALID_LINK_MESSAGE);

        $this->postJson('/api/auth/reset-password/validate', ['token' => 'wrong-token', 'email' => 'ghost@example.com'])
            ->assertStatus(422)
            ->assertJsonPath('message', self::INVALID_LINK_MESSAGE);
    }

    public function test_validate_token_rejects_an_expired_token(): void
    {
        $user = User::factory()->create();
        $token = Password::broker()->createToken($user);

        $this->travel(61)->minutes();

        $this->postJson('/api/auth/reset-password/validate', ['token' => $token, 'email' => $user->email])
            ->assertStatus(422)
            ->assertJsonPath('message', self::INVALID_LINK_MESSAGE);
    }

    // ---------------------------------------------------------------- PW-03 / PW-07 (reset)

    public function test_reset_password_changes_the_password_signs_out_every_session_and_notifies_the_owner(): void
    {
        Notification::fake();
        $user = User::factory()->create(['password' => 'OldPassword!23']);
        $user->createToken('browser-a');
        $user->createToken('browser-b');
        $token = Password::broker()->createToken($user);
        $this->assertSame(2, $user->tokens()->count());

        $this->postJson('/api/auth/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'NewPassword!45',
            'password_confirmation' => 'NewPassword!45',
        ])->assertOk()->assertJsonPath('success', true);

        $user->refresh();
        $this->assertTrue(Hash::check('NewPassword!45', $user->password));
        $this->assertFalse(Hash::check('OldPassword!23', $user->password));
        $this->assertSame(0, $user->tokens()->count(), 'Every existing session must be revoked.');
        Notification::assertSentTo($user, PasswordChangedNotification::class);
        $this->assertDatabaseHas('activity_logs', ['action' => 'account.password_reset']);
    }

    public function test_after_a_reset_the_old_password_fails_and_the_new_one_logs_in(): void
    {
        $user = User::factory()->create(['password' => 'OldPassword!23']);
        $token = Password::broker()->createToken($user);

        $this->postJson('/api/auth/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'NewPassword!45',
            'password_confirmation' => 'NewPassword!45',
        ])->assertOk();

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'OldPassword!23'])
            ->assertStatus(422);
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'NewPassword!45'])
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_reset_link_cannot_be_used_twice(): void
    {
        $user = User::factory()->create(['password' => 'OldPassword!23']);
        $token = Password::broker()->createToken($user);

        $payload = fn (string $pw) => [
            'token' => $token,
            'email' => $user->email,
            'password' => $pw,
            'password_confirmation' => $pw,
        ];

        $this->postJson('/api/auth/reset-password', $payload('NewPassword!45'))->assertOk();

        $this->postJson('/api/auth/reset-password', $payload('Another!Pass99'))
            ->assertStatus(422)
            ->assertJsonPath('message', self::INVALID_LINK_MESSAGE);

        $this->assertTrue(Hash::check('NewPassword!45', $user->fresh()->password), 'Second submission must change nothing.');
    }

    public function test_reset_with_an_invalid_token_changes_nothing(): void
    {
        $user = User::factory()->create(['password' => 'OldPassword!23']);

        $this->postJson('/api/auth/reset-password', [
            'token' => 'wrong-token',
            'email' => $user->email,
            'password' => 'NewPassword!45',
            'password_confirmation' => 'NewPassword!45',
        ])->assertStatus(422)->assertJsonPath('message', self::INVALID_LINK_MESSAGE);

        $this->assertTrue(Hash::check('OldPassword!23', $user->fresh()->password));
    }

    public function test_reset_with_an_expired_token_is_rejected(): void
    {
        $user = User::factory()->create(['password' => 'OldPassword!23']);
        $token = Password::broker()->createToken($user);

        $this->travel(61)->minutes();

        $this->postJson('/api/auth/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'NewPassword!45',
            'password_confirmation' => 'NewPassword!45',
        ])->assertStatus(422)->assertJsonPath('message', self::INVALID_LINK_MESSAGE);

        $this->assertTrue(Hash::check('OldPassword!23', $user->fresh()->password));
    }

    // ---------------------------------------------------------------- PW-05 (password rules)

    /**
     * Every password field (registration, change and reset) enforces: minimum 8 characters, a letter, a number and a matching confirmation.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function invalidPasswords(): array
    {
        return [
            'too short' => ['Ab1', 'Ab1'],
            'letters only' => ['onlyletters', 'onlyletters'],
            'numbers only' => ['12345678', '12345678'],
            'confirmation mismatch' => ['ValidPass123', 'DifferentPass123'],
        ];
    }



    #[DataProvider('invalidPasswords')]
    public function test_reset_rejects_passwords_that_break_the_rules(string $password, string $confirmation): void
    {
        $user = User::factory()->create(['password' => 'OldPassword!23']);
        $token = Password::broker()->createToken($user);

        $this->postJson('/api/auth/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => $password,
            'password_confirmation' => $confirmation,
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->assertTrue(Hash::check('OldPassword!23', $user->fresh()->password));
    }

    #[DataProvider('invalidPasswords')]
    public function test_client_registration_enforces_the_password_rules(string $password, string $confirmation): void
    {
        $this->postJson('/api/auth/register-client', [
            'name' => 'Jane',
            'email' => 'jane@gmail.com',
            'phone_number' => '09171234567',
            'password' => $password,
            'password_confirmation' => $confirmation,
            'terms_accepted' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->assertDatabaseMissing('users', ['email' => 'jane@gmail.com']);
    }

    // ---------------------------------------------------------------- PW-06 (change password)

    public function test_client_can_change_their_password_and_log_in_with_the_new_one(): void
    {
        $user = User::factory()->create(['password' => 'OldPassword!23']);
        Sanctum::actingAs($user);

        $this->postJson('/api/client/change-password', [
            'current_password' => 'OldPassword!23',
            'password' => 'NewPassword!45',
            'password_confirmation' => 'NewPassword!45',
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertTrue(Hash::check('NewPassword!45', $user->fresh()->password));
    }

    public function test_change_password_rejects_a_wrong_current_password(): void
    {
        $user = User::factory()->create(['password' => 'OldPassword!23']);
        Sanctum::actingAs($user);

        $this->postJson('/api/client/change-password', [
            'current_password' => 'WrongPassword!1',
            'password' => 'NewPassword!45',
            'password_confirmation' => 'NewPassword!45',
        ])->assertStatus(422)->assertJsonValidationErrors('current_password');

        $this->assertTrue(Hash::check('OldPassword!23', $user->fresh()->password));
    }

    #[DataProvider('invalidPasswords')]
    public function test_change_password_enforces_the_password_rules(string $password, string $confirmation): void
    {
        $user = User::factory()->create(['password' => 'OldPassword!23']);
        Sanctum::actingAs($user);

        $this->postJson('/api/client/change-password', [
            'current_password' => 'OldPassword!23',
            'password' => $password,
            'password_confirmation' => $confirmation,
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->assertTrue(Hash::check('OldPassword!23', $user->fresh()->password));
    }

    public function test_change_password_requires_authentication(): void
    {
        $this->postJson('/api/client/change-password', [
            'current_password' => 'OldPassword!23',
            'password' => 'NewPassword!45',
            'password_confirmation' => 'NewPassword!45',
        ])->assertUnauthorized();
    }
}