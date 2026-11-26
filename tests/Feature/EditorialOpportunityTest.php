<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\Opportunities\Pages\ListOpportunities;
use App\Filament\Resources\Opportunities\Pages\ViewOpportunity;
use App\Models\Opportunity;
use App\Models\ResearchItem;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class EditorialOpportunityTest extends TestCase
{
    use RefreshDatabase;

    public function test_imported_research_and_reasoning_are_visible_without_duplicate_or_publication(): void
    {
        Http::fake();
        Queue::fake();
        $user = User::factory()->create(['role' => UserRole::Curator]);
        $file = tempnam(sys_get_temp_dir(), 'chub-proposal-');
        file_put_contents($file, json_encode([
            'source_url' => 'https://www.bacs.admin.ch/en/26w42-en',
            'source_title' => 'Hotel booking phishing targets TWINT',
            'excerpt' => 'Bekannte Buchungsdaten sind kein Echtheitsbeweis.',
            'observed_at' => '2026-10-21T08:21:00Z',
            'provenance' => 'Journalist-Briefing 2026-10-21; redaktionell geprüft',
            'title' => 'Phishing nach der Hotelbuchung',
            'rationale' => 'Geschäftsreisende lernen, Zahlungsaufforderungen unabhängig zu prüfen.',
        ], JSON_THROW_ON_ERROR));
        try {
            for ($i = 0; $i < 2; $i++) {
                $this->artisan('content-hub:import-opportunity', ['file' => $file, '--curator' => $user->email])->assertSuccessful();
            }
        } finally {
            unlink($file);
        }
        $this->assertDatabaseCount('research_items', 1);
        $this->assertDatabaseCount('opportunities', 1);
        $this->assertDatabaseCount('opportunity_research_item', 1);
        $this->assertDatabaseCount('publications', 0);
        $this->assertDatabaseCount('content_items', 0);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $opportunity = Opportunity::firstOrFail();
        Livewire::test(ListOpportunities::class)->assertSuccessful()->assertCanSeeTableRecords([$opportunity]);
        Livewire::test(ViewOpportunity::class, ['record' => $opportunity->id])
            ->assertSuccessful()->assertSee($opportunity->title)->assertSee($opportunity->rationale)
            ->assertSee('https://www.bacs.admin.ch/en/26w42-en')->assertSee(ResearchItem::first()->excerpt);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_source_once_preserves_edits_across_briefing_dates(): void
    {
        Http::fake();
        Queue::fake();
        $user = User::factory()->create(['role' => UserRole::Curator]);
        $file = tempnam(sys_get_temp_dir(), 'journalist-');
        $data = [
            'source_url' => 'https://example.org/original', 'source_title' => 'Source',
            'excerpt' => 'Evidence', 'observed_at' => '2026-10-21',
            'provenance' => 'Journalist 2026-10-21', 'title' => 'Proposed title', 'rationale' => 'Reason',
        ];
        try {
            file_put_contents($file, json_encode($data));
            $args = ['file' => $file, '--curator' => $user->email, '--source-once' => true];
            $this->artisan('content-hub:import-opportunity', $args)->assertSuccessful();
            Opportunity::firstOrFail()->update(['title' => 'Human title', 'rationale' => 'Human reasoning']);
            $data['provenance'] = 'Journalist 2026-10-22';
            $data['title'] = 'Another machine title';
            file_put_contents($file, json_encode($data));
            $this->artisan('content-hub:import-opportunity', $args)->assertSuccessful();
            $this->assertDatabaseCount('opportunities', 1);
            $this->assertDatabaseCount('research_items', 1);
            $this->assertDatabaseHas('opportunities', ['title' => 'Human title', 'rationale' => 'Human reasoning']);
            $this->assertDatabaseCount('publications', 0);
            $this->assertDatabaseCount('content_items', 0);
            Queue::assertNothingPushed();
            Http::assertNothingSent();
        } finally {
            unlink($file);
        }
    }

    public function test_research_requires_curator_access(): void
    {
        $this->get('/admin/opportunities')->assertRedirect('/admin/login');
        $user = User::factory()->create(['role' => UserRole::Contributor]);
        $this->actingAs($user)->get('/admin/opportunities')->assertForbidden();
        $this->artisan('content-hub:import-opportunity', ['file' => '/missing', '--curator' => $user->email])->assertFailed();
        $this->assertDatabaseCount('opportunities', 0);
    }
}
