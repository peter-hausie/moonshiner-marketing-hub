<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Pages\Accounts;
use App\Filament\Pages\EditorialCalendar;
use App\Models\ContentItem;
use App\Models\User;
use App\Notifications\AccountInvitationMail;
use App\Services\Accounts\ManageAccounts;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class AccountsAndCalendarTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create(['role' => UserRole::Curator]);
        $user->forceFill(['is_admin' => true])->save();
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $user;
    }

    public function test_invitation_mail_activation_single_use_and_account_permissions(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $user = app(ManageAccounts::class)->invite($admin, 'Invited Author', 'author@example.test', 'contributor');
        $this->assertFalse($user->active);
        Notification::assertSentTo($user, AccountInvitationMail::class);
        $this->assertDatabaseHas('account_invitations', ['user_id' => $user->id, 'delivery_status' => 'sent']);
        $notification = Notification::sent($user, AccountInvitationMail::class)->first();
        $url = $notification->toMail($user)->actionUrl;
        $this->get($url)->assertOk()->assertSee('Invited Author');
        $this->post($url, ['password' => 'ValidPassword123!', 'password_confirmation' => 'ValidPassword123!'])->assertRedirect('/admin/login');
        $this->assertTrue($user->fresh()->active);
        $this->get($url)->assertForbidden();
        $this->app['session']->flush();
        $this->actingAs($user->fresh())->get('/admin/accounts')->assertForbidden();
        $this->app['session']->flush();
        $this->actingAs(User::factory()->create(['role' => UserRole::Curator]))->get('/admin/accounts')->assertForbidden();
        $this->app['session']->flush();
        $this->actingAs($admin)->get('/admin/accounts')->assertOk();
    }

    public function test_resend_invalidates_old_link_and_deactivation_invalidates_new_link(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $service = app(ManageAccounts::class);
        $user = $service->invite($admin, 'Pending', 'pending@example.test', 'curator');
        $old = Notification::sent($user, AccountInvitationMail::class)->first()->toMail($user)->actionUrl;
        $service->sendInvitation($admin, $user->id);
        $this->get($old)->assertForbidden();
        $latest = Notification::sent($user, AccountInvitationMail::class)->last()->toMail($user)->actionUrl;
        $service->update($admin, $user->id, 'contributor', false);
        $this->get($latest)->assertForbidden();
    }

    public function test_admin_cannot_remove_own_access_and_disabled_user_is_denied(): void
    {
        $admin = $this->admin();
        Livewire::test(Accounts::class)->callAction('editAccess', data: ['access' => 'contributor', 'active' => false], arguments: ['id' => $admin->id])->assertStatus(422);
        $this->assertTrue($admin->fresh()->canManageAccounts());
        $user = User::factory()->create();
        $user->forceFill(['active' => false])->save();
        $this->actingAs($user)->get('/admin')->assertForbidden();
    }

    public function test_drag_and_drop_preserves_time_and_rejects_stale_revision_without_publishing(): void
    {
        Queue::fake();
        $this->admin();
        $item = ContentItem::factory()->create(['status' => 'in_review', 'scheduled_at' => '2026-11-23 09:30:00', 'channels' => ['LinkedIn', 'Newsletter']]);
        $rev = EditorialCalendar::revision($item);
        Livewire::test(EditorialCalendar::class)->call('dropPiece', $item->id, '2026-11-26', $rev)->assertSuccessful();
        $this->assertSame('2026-11-26 09:30:00', $item->fresh()->scheduled_at->format('Y-m-d H:i:s'));
        Livewire::test(EditorialCalendar::class)->call('dropPiece', $item->id, '2026-11-27', $rev)->assertSuccessful();
        $this->assertSame('2026-11-26 09:30:00', $item->fresh()->scheduled_at->format('Y-m-d H:i:s'));
        Livewire::test(EditorialCalendar::class)->call('dropPiece', $item->id, null, EditorialCalendar::revision($item->fresh()));
        $this->assertNull($item->fresh()->scheduled_at);
        $this->assertSame('in_review', $item->fresh()->status->value);
        $this->assertDatabaseCount('publications', 0);
        Queue::assertNothingPushed();
    }

    public function test_non_curator_cannot_drag_and_assignment_grants_piece_access_only(): void
    {
        $owner = User::factory()->create();
        $assigned = User::factory()->create();
        $stranger = User::factory()->create();
        $item = ContentItem::factory()->create(['submitted_by' => $owner->id, 'responsible_user_id' => $assigned->id]);
        $this->actingAs($assigned)->get('/admin/content-items/'.$item->id.'/edit')->assertOk();
        $this->actingAs($stranger)->get('/admin/content-items/'.$item->id.'/edit')->assertNotFound();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(EditorialCalendar::class)->assertForbidden();
    }

    public function test_expired_invitation_cannot_activate(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $user = app(ManageAccounts::class)->invite($admin, 'Expired', 'expired@example.test', 'contributor');
        $url = Notification::sent($user, AccountInvitationMail::class)->first()->toMail($user)->actionUrl;
        $this->travel(49)->hours();
        $this->get($url)->assertForbidden();
        $this->assertFalse($user->fresh()->active);
    }
}
