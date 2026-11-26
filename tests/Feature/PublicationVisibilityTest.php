<?php

namespace Tests\Feature;

use App\Enums\PublicationStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Publications\Pages\ListPublications;
use App\Filament\Resources\Publications\PublicationResource;
use App\Models\ContentItem;
use App\Models\Publication;
use App\Models\PublicationAttempt;
use App\Models\User;
use App\Services\Content\CreateContentVersion;
use App\Services\Content\PublicationReviewFingerprint;
use App\Services\Content\RecordPublicationReview;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class PublicationVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function publication(User $owner, PublicationStatus $status, string $channel = 'Blog'): Publication
    {
        return Publication::create([
            'content_item_id' => ContentItem::factory()->create(['submitted_by' => $owner, 'image_path' => null])->id,
            'channel' => $channel,
            'status' => $status,
            'scheduled_at' => now()->subDay(),
            'last_error' => 'PRIVATE_PROVIDER_DIAGNOSTICS',
            'external_url' => 'https://example.test/private-provider-response',
        ]);
    }

    public function test_curator_can_inspect_and_filter_channel_outcomes_without_mutation_or_dispatch(): void
    {
        Http::fake();
        Queue::fake();
        $user = User::factory()->create(['role' => UserRole::Curator]);
        $unknown = $this->publication($user, PublicationStatus::Failed);
        $processing = $this->publication($user, PublicationStatus::Processing, 'LinkedIn');
        $published = $this->publication($user, PublicationStatus::Published);
        $before = Publication::orderBy('id')->get()->toArray();
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ListPublications::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$unknown, $processing, $published])
            ->assertSee('Abgleich erforderlich')
            ->assertSee('Nicht erneut senden')
            ->assertDontSee('PRIVATE_PROVIDER_DIAGNOSTICS')
            ->assertDontSee('private-provider-response')
            ->filterTable('status', 'failed')
            ->assertCanSeeTableRecords([$unknown])
            ->assertCanNotSeeTableRecords([$processing, $published])
            ->resetTableFilters()
            ->filterTable('channel', 'LinkedIn')
            ->assertCanSeeTableRecords([$processing])
            ->assertCanNotSeeTableRecords([$unknown, $published]);

        $this->assertSame($before, Publication::orderBy('id')->get()->toArray());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
        $this->assertFalse(PublicationResource::canCreate());
        $this->assertFalse(PublicationResource::canEdit($unknown));
        $this->assertFalse(PublicationResource::canDelete($unknown));
        $this->assertFalse(PublicationResource::canDeleteAny());
    }

    public function test_contributor_cannot_see_or_search_other_owners_publications_or_channels(): void
    {
        $user = User::factory()->create(['role' => UserRole::Contributor]);
        $own = $this->publication($user, PublicationStatus::Failed);
        $other = $this->publication(User::factory()->create(), PublicationStatus::Failed, 'PrivateChannel');
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ListPublications::class)
            ->assertCanSeeTableRecords([$own])
            ->assertCanNotSeeTableRecords([$other])
            ->assertDontSee('PrivateChannel')
            ->searchTable($other->contentItem->title)
            ->assertCanNotSeeTableRecords([$own, $other]);
    }

    public function test_latest_attempt_uses_sequence_not_insert_order_and_never_renders_raw_diagnostics(): void
    {
        Http::fake();
        Queue::fake();
        $user = User::factory()->create(['role' => UserRole::Curator]);
        $publication = $this->publication($user, PublicationStatus::Failed);
        $legacy = $this->publication($user, PublicationStatus::Published);
        $latest = PublicationAttempt::create([
            'publication_id' => $publication->id, 'content_item_id' => $publication->content_item_id,
            'attempt_number' => 2, 'provenance' => 'PRIVATE_PROVENANCE', 'started_at' => now()->subMinute(),
        ]);
        $latest->forceFill(['status' => 'PRIVATE_UNKNOWN_STATUS', 'error_code' => 'PRIVATE_ERROR', 'external_id' => 'PRIVATE_EXTERNAL'])->save();
        $older = $latest->replicate();
        $older->attempt_number = 1;
        $older->status = 'published';
        $older->save();
        $before = PublicationAttempt::orderBy('id')->get()->toArray();
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ListPublications::class)
            ->assertCanSeeTableRecords([$publication, $legacy])
            ->assertTableColumnStateSet('latestExecutionAttempt.attempt_number', 2, $publication)
            ->assertSee('Ergebnis ungeklärt')
            ->assertSee('Kein Versuch protokolliert')
            ->assertSee('keine Aussage über frühere Veröffentlichungen')
            ->assertDontSee('Erfolg protokolliert')
            ->assertDontSee('PRIVATE_UNKNOWN_STATUS')
            ->assertDontSee('PRIVATE_ERROR')
            ->assertDontSee('PRIVATE_EXTERNAL')
            ->assertDontSee('PRIVATE_PROVENANCE');

        $this->assertSame($before, PublicationAttempt::orderBy('id')->get()->toArray());
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_review_evidence_is_latest_binding_specific_and_not_a_dispatch_authorization(): void
    {
        Http::fake();
        Queue::fake();
        $user = User::factory()->create(['role' => UserRole::Curator]);
        $records = [];
        foreach (['absent', 'approved', 'revoked', 'channel_changed', 'version_changed'] as $state) {
            $publication = $this->publication($user, PublicationStatus::Pending, 'blog');
            $version = app(CreateContentVersion::class)->create($publication->content_item_id, $user->id, 'human_snapshot');
            $publication->forceFill(['content_version_id' => $version])->save();
            if ($state !== 'absent') {
                $hash = app(PublicationReviewFingerprint::class)->forVerifiedPublication($publication->id);
                app(RecordPublicationReview::class)->approve($publication->id, $user->id, $hash);
                if ($state === 'revoked') {
                    app(RecordPublicationReview::class)->revoke($publication->id, $user->id);
                } elseif ($state === 'channel_changed') {
                    $publication->forceFill(['channel' => 'linkedin'])->save();
                } elseif ($state === 'version_changed') {
                    $publication->forceFill(['content_version_id' => null])->save();

                }
            }
            $records[$state] = $publication;
        }
        $before = DB::table('publication_reviews')->orderBy('id')->get()->toJson();
        $publications = Publication::orderBy('id')->get()->toArray();
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(ListPublications::class)
            ->assertCanSeeTableRecords(array_values($records))
            ->assertTableColumnStateSet('review_evidence', 'Keine Freigabe protokolliert.', $records['absent'])
            ->assertTableColumnStateSet('review_evidence', 'Freigabe protokolliert; aktuelle Gültigkeit wird vor Versand erneut geprüft.', $records['approved'])
            ->assertTableColumnStateSet('review_evidence', 'Freigabe widerrufen.', $records['revoked'])
            ->assertTableColumnStateSet('review_evidence', 'Freigabe für andere Version oder anderen Kanal; erneute Prüfung erforderlich.', $records['channel_changed'])
            ->assertTableColumnStateSet('review_evidence', 'Freigabe für andere Version oder anderen Kanal; erneute Prüfung erforderlich.', $records['version_changed']);
        $this->assertSame($before, DB::table('publication_reviews')->orderBy('id')->get()->toJson());
        $this->assertSame($publications, Publication::orderBy('id')->get()->toArray());
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_guest_has_no_publication_query_access_and_page_requires_login(): void
    {
        $this->publication(User::factory()->create(), PublicationStatus::Failed);
        $this->assertSame(0, PublicationResource::getEloquentQuery()->count());
        $this->get('/admin/publications')->assertRedirect('/admin/login');
    }
}
