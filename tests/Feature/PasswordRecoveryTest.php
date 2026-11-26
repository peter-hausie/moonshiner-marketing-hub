<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Notifications\ResetPassword as RecoveryNotification;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset;
use Filament\Auth\Pages\PasswordReset\ResetPassword;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;
use Tests\TestCase;

class PasswordRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_login_exposes_recovery_and_dashboard_requires_authentication(): void
    {
        $this->get('/admin/login')->assertOk()->assertSee('password-reset');
        $this->get('/admin/password-reset/request')->assertOk();
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_recovery_sends_only_to_existing_account_without_changing_password_or_role(): void
    {
        Notification::fake();
        $user = User::factory()->create(['role' => UserRole::Curator]);
        $before = $user->password;
        Livewire::test(RequestPasswordReset::class)->fillForm(['email' => $user->email])->call('request')->assertHasNoFormErrors();
        Notification::assertSentTo($user, RecoveryNotification::class, fn ($n) => $n->connection === 'sync');
        $this->assertSame($before, $user->fresh()->password);
        $this->assertSame(UserRole::Curator, $user->fresh()->role);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_unknown_account_does_not_send_a_notification(): void
    {
        Notification::fake();
        Livewire::test(RequestPasswordReset::class)->fillForm(['email' => 'unknown@example.test'])->call('request');
        Notification::assertNothingSent();
    }

    public function test_valid_reset_changes_password_once_and_preserves_other_users(): void
    {
        $user = User::factory()->create(['role' => UserRole::Curator]);
        $other = User::factory()->create();
        $otherHash = $other->password;
        $token = Password::createToken($user);
        $password = 'Local-test-only-2026!';
        Livewire::test(ResetPassword::class, ['email' => $user->email, 'token' => $token])
            ->fillForm(['password' => $password, 'passwordConfirmation' => $password])->call('resetPassword')->assertHasNoFormErrors();
        $this->assertTrue(Hash::check($password, $user->fresh()->password));
        $this->assertFalse(Password::tokenExists($user, $token));
        $this->assertSame($otherHash, $other->fresh()->password);
        $this->assertSame(UserRole::Curator, $user->fresh()->role);
        $this->assertTrue(auth()->attempt(['email' => $user->email, 'password' => $password]));
        $this->get('/admin')->assertOk();
    }

    public function test_expired_token_cannot_change_password(): void
    {
        $user = User::factory()->create();
        $before = $user->password;
        $token = Password::createToken($user);
        $this->travel(61)->minutes();
        Livewire::test(ResetPassword::class, ['email' => $user->email, 'token' => $token])
            ->fillForm(['password' => 'Local-test-only-2026!', 'passwordConfirmation' => 'Local-test-only-2026!'])->call('resetPassword');
        $this->assertSame($before, $user->fresh()->password);
    }

    public function test_recovery_refuses_production_log_mailer(): void
    {
        app()->instance('env', 'production');
        config(['mail.default' => 'log']);
        $this->expectException(\LogicException::class);
        (new RecoveryNotification('fake-test-token'))->toMail(User::factory()->make());
    }
}
