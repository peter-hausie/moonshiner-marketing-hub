<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\ContentItems\Pages\EditContentItem;
use App\Filament\Resources\Opportunities\Pages\ViewOpportunity;
use App\Models\Opportunity;
use App\Models\ResearchItem;
use App\Models\User;
use App\Services\Content\CreateOpportunityDraft;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class OpportunityDraftTest extends TestCase
{
    use RefreshDatabase;

    public function test_source_linked_draft_can_be_edited_and_retry_preserves_changes_without_publishing(): void
    {
        Queue::fake();
        $user = User::factory()->create(['role' => UserRole::Curator]);
        $opportunity = Opportunity::create(['title' => 'Geschäftsreisen', 'provenance' => 'reviewed']);
        $source = ResearchItem::create(['title' => 'BACS', 'source_url' => 'https://www.bacs.admin.ch/en/26w42-en', 'observed_at' => now(), 'provenance' => 'reviewed']);
        $opportunity->researchItems()->attach($source);
        $service = app(CreateOpportunityDraft::class);
        $item = $service->create($opportunity, $user, 'Erster Entwurf');
        $this->assertStringContainsString('nicht vom Underwood-Agenten', $item->context);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(ViewOpportunity::class, ['record' => $opportunity->id])->assertSee('/admin/content-items/'.$item->id.'/edit');
        Livewire::test(EditContentItem::class, ['record' => $item->id])
            ->assertSuccessful()->assertSee($source->source_url)->assertSee('Recherche öffnen')
            ->fillForm(['title' => 'Redaktionell überarbeitet', 'body' => 'Geprüfter neuer Text'])
            ->call('save')->assertHasNoFormErrors();
        $again = $service->create($opportunity, $user, 'Nicht überschreiben');
        $this->assertSame($item->id, $again->id);
        $this->assertSame('Geprüfter neuer Text', $again->body);
        $this->assertDatabaseCount('content_items', 1);
        $this->assertDatabaseCount('content_item_opportunity', 1);
        $this->assertDatabaseCount('publications', 0);
        Queue::assertNothingPushed();
        $other = User::factory()->create(['role' => UserRole::Contributor]);
        $this->actingAs($other)->get('/admin/content-items/'.$item->id.'/edit')->assertNotFound();
    }

    public function test_contributor_cannot_create_draft_from_research(): void
    {
        $this->expectException(HttpException::class);
        app(CreateOpportunityDraft::class)->create(
            Opportunity::create(['title' => 'Test', 'provenance' => 'reviewed']),
            User::factory()->create(['role' => UserRole::Contributor]), 'Text',
        );
    }
}
