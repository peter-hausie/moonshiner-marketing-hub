<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Pages\TechnicalHandover;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class HandoverTest extends TestCase
{
    use RefreshDatabase;

    public function test_documentation_is_restricted_and_renders_for_curators(): void
    {
        $this->get('/admin/technical-handover')->assertRedirect('/admin/login');
        $this->actingAs(User::factory()->create(['role' => UserRole::Contributor]));
        $this->get('/admin/technical-handover')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => UserRole::Curator]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(TechnicalHandover::class)->assertSee('Moonshiner Marketing Hub')
            ->assertSee('Langdock')->assertSee('standardmäßig deaktiviert');
    }

    public function test_first_admin_is_created_with_explicit_privileges_and_hashed_password(): void
    {
        $this->artisan('hub:create-admin')->expectsQuestion('Name', 'Example Admin')
            ->expectsQuestion('Email', 'admin@example.test')
            ->expectsQuestion('Password (at least 12 characters)', 'test-only-password')
            ->expectsQuestion('Repeat password', 'test-only-password')->assertSuccessful();
        $user = User::where('email', 'admin@example.test')->firstOrFail();
        $this->assertTrue($user->canManageAccounts());
        $this->assertTrue($user->isCurator());
        $this->assertNotSame('test-only-password', $user->getRawOriginal('password'));
    }
}
