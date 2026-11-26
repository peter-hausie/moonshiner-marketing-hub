<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\ContentItems\Pages\AgentConversation;
use App\Filament\Resources\Opportunities\Pages\ListOpportunities;
use App\Filament\Resources\Opportunities\Pages\ViewOpportunity;
use App\Models\AgentEditorialSetting;
use App\Models\ContentAgentMessage;
use App\Models\ContentItem;
use App\Models\Opportunity;
use App\Models\User;
use App\Services\Content\AdoptOpportunity;
use App\Services\Content\RecordAgentMessage;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class EditorialWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private function loginCurator(): User
    {
        $user = User::factory()->create(['role' => UserRole::Curator]);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $user;
    }

    public function test_adopt_button_creates_draft_once_and_preserves_edits(): void
    {
        Queue::fake();
        Http::fake();
        $user = $this->loginCurator();
        $topic = Opportunity::create(['title' => '[DEMO] Better questions', 'rationale' => 'A clear angle', 'provenance' => 'demo']);
        Livewire::test(ViewOpportunity::class, ['record' => $topic->id])->assertSee('Übernehmen')->call('adopt')->assertRedirect();
        $item = $topic->contentItems()->firstOrFail();
        $this->assertSame('draft', $item->status->value);
        $this->assertStringContainsString('A clear angle', $item->context);
        $item->update(['body' => 'Human edit']);
        Livewire::test(ListOpportunities::class)->assertSee('Beitrag öffnen')->call('adoptTopic', $topic->id)->assertRedirect();
        $again = app(AdoptOpportunity::class)->adopt($topic->id, $user);
        $this->assertSame($item->id, $again->id);
        $this->assertSame('Human edit', $again->body);
        $this->assertDatabaseCount('content_items', 1);
        $this->assertDatabaseCount('publications', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_briefing_feedback_snapshot_and_retry_without_dispatch(): void
    {
        Queue::fake();
        Http::fake();
        $user = $this->loginCurator();
        $item = ContentItem::factory()->create(['submitted_by' => $user->id, 'body' => 'Original text']);
        $writer = new AgentEditorialSetting;
        $writer->forceFill(['role' => 'writer', 'display_name' => 'Moon Writer', 'briefing' => 'Warm and precise'])->save();
        $key = (string) Str::uuid();
        $service = app(RecordAgentMessage::class);
        $first = $service->record($item->id, $user, 'draft', 'Write a useful article', $key);
        $item->update(['body' => 'Later edit']);
        $this->assertSame($first->id, $service->record($item->id, $user, 'draft', 'Write a useful article', $key)->id);
        $this->assertSame('Original text', $first->context_snapshot['body']);
        $this->assertSame('Warm and precise', $first->context_snapshot['tone_of_voice']);
        Livewire::test(AgentConversation::class, ['record' => $item->id])
            ->assertSee('Moon Writer')->assertSee('Agent noch nicht angebunden')
            ->set('kind', 'feedback')->set('message', 'Bitte lustiger schreiben')->call('saveMessage')->assertHasNoErrors()
            ->assertSee('Bitte lustiger schreiben')->assertSee('nicht gesendet');
        $this->assertDatabaseCount('content_agent_messages', 2);
        $this->assertSame('awaiting_connection', ContentAgentMessage::latest('id')->first()->status);
        $this->assertSame('Later edit', $item->fresh()->body);
        $this->assertDatabaseCount('publications', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_empty_feedback_and_foreign_content_are_rejected(): void
    {
        $this->loginCurator();
        $item = ContentItem::factory()->create();
        Livewire::test(AgentConversation::class, ['record' => $item->id])
            ->set('message', '   ')->call('saveMessage')->assertHasErrors('message');
        $other = User::factory()->create(['role' => UserRole::Contributor]);
        $this->actingAs($other)->get('/admin/content-items/'.$item->id.'/briefing')->assertNotFound();
        try {
            app(RecordAgentMessage::class)->record($item->id, $other, 'feedback', 'Unauthorized', (string) Str::uuid());
            $this->fail('Foreign access allowed');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->assertDatabaseCount('content_agent_messages', 0);
    }
}
