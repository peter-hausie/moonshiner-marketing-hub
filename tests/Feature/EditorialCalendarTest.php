<?php

namespace Tests\Feature;

use App\Enums\ContentStatus;
use App\Enums\UserRole;
use App\Filament\Pages\EditorialCalendar;
use App\Models\ContentItem;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class EditorialCalendarTest extends TestCase
{
    use RefreshDatabase;

    public function test_curator_can_plan_move_remove_and_reopen_draft_without_publication(): void
    {
        Http::fake();
        Queue::fake();
        $user = User::factory()->create(['role' => UserRole::Curator]);
        $item = ContentItem::factory()->create(['submitted_by' => $user->id, 'status' => ContentStatus::Draft, 'scheduled_at' => null, 'channels' => []]);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $page = Livewire::test(EditorialCalendar::class)->assertSuccessful()->assertSee($item->title);
        $page->callAction('plan', data: ['date' => '2026-10-25 10:00:00'], arguments: ['id' => $item->id])->assertHasNoActionErrors();
        $this->assertSame('2026-10-25 09:00', $item->fresh()->scheduled_at->utc()->format('Y-m-d H:i'));
        Livewire::test(EditorialCalendar::class)->set('month', '2026-10')->assertSee($item->title)->assertSee('10:00')->assertSee('/admin/content-items/'.$item->id.'/edit');
        $page->callAction('plan', data: ['date' => '2026-11-03 14:30:00'], arguments: ['id' => $item->id])->assertHasNoActionErrors()->assertSet('month', '2026-11');
        $this->assertSame('2026-11-03 13:30', $item->fresh()->scheduled_at->utc()->format('Y-m-d H:i'));
        $page->callAction('plan', data: ['date' => null], arguments: ['id' => $item->id])->assertHasNoActionErrors();
        $this->assertNull($item->fresh()->scheduled_at);
        $this->assertSame(ContentStatus::Draft, $item->fresh()->status);
        $this->assertDatabaseCount('publications', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_calendar_is_curator_only_and_non_draft_cannot_be_planned(): void
    {
        $this->get('/admin/editorial-calendar')->assertRedirect('/admin/login');
        $this->actingAs(User::factory()->create(['role' => UserRole::Contributor]))->get('/admin/editorial-calendar')->assertForbidden();
        $user = User::factory()->create(['role' => UserRole::Curator]);
        $item = ContentItem::factory()->create(['status' => ContentStatus::Published]);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(EditorialCalendar::class)->call('mountAction', 'plan', ['id' => $item->id])->assertForbidden();
    }
}
