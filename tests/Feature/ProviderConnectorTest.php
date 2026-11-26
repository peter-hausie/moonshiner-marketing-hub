<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Pages\ProviderConnector;
use App\Filament\Resources\ContentItems\Pages\AgentConversation;
use App\Models\ConnectorRun;
use App\Models\ContentItem;
use App\Models\ProviderConnection;
use App\Models\User;
use App\Services\Connector\Connector;
use App\Services\Connector\LangdockProvider;
use App\Services\Connector\SaveConnection;
use App\Services\Content\RecordAgentMessage;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class ProviderConnectorTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Curator, 'is_admin' => true]);
    }

    private function enable(User $admin): void
    {
        app(SaveConnection::class)->save($admin, ['revision' => 0, 'enabled' => true, 'api_key' => 'fixture-not-a-real-key',
            'writer_agent_id' => 'writer-test', 'research_agent_id' => 'research-test']);
    }

    private function request(User $user): array
    {
        $item = ContentItem::factory()->create(['submitted_by' => $user->id, 'title' => 'Original title', 'body' => 'Original body', 'status' => 'draft']);
        $message = app(RecordAgentMessage::class)->record($item->id, $user, 'draft', 'Make this clearer', (string) Str::uuid());

        return [$item, ConnectorRun::where('message_id', $message->id)->firstOrFail()];
    }

    public function test_end_to_end_draft_is_proposal_then_explicit_adoption_and_replay_is_noop(): void
    {
        $user = $this->admin();
        $this->enable($user);
        [$item,$run] = $this->request($user);
        Http::fake([LangdockProvider::ENDPOINT => Http::response(['output' => ['title' => 'New title', 'body' => 'New body']])]);
        app(Connector::class)->process($run->id);
        app(Connector::class)->process($run->id);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['agentId'] === 'writer-test' && $request['stream'] === false && $request['output']['schema']['required'] === ['title', 'body']);
        $this->assertSame('succeeded', $run->fresh()->status);
        $this->assertSame('Original body', $item->fresh()->body);
        $this->assertDatabaseCount('draft_submission_receipts', 1);
        app(Connector::class)->adopt($run->id, $user);
        app(Connector::class)->adopt($run->id, $user);
        $this->assertSame('New body', $item->fresh()->body);
        $this->assertSame('draft', $item->fresh()->status->value);
        $this->assertDatabaseCount('publications', 0);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(AgentConversation::class, ['record' => $item->id])->assertSee('New body')->assertSee('In Beitrag übernommen');
    }

    public function test_stale_before_provider_is_rejected_without_network(): void
    {
        $user = $this->admin();
        $this->enable($user);
        [$item,$run] = $this->request($user);
        $item->update(['body' => 'Human edit']);
        Http::preventStrayRequests();
        app(Connector::class)->process($run->id);
        $this->assertSame('conflict', $run->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_human_edit_during_request_preserved_and_proposal_not_written(): void
    {
        $user = $this->admin();
        $this->enable($user);
        [$item,$run] = $this->request($user);
        Http::fake(function () use ($item) {
            $item->update(['body' => 'New human text']);

            return Http::response(['output' => ['title' => 'AI', 'body' => 'AI body']]);
        });
        app(Connector::class)->process($run->id);
        $this->assertSame('conflict', $run->fresh()->status);
        $this->assertSame('New human text', $item->fresh()->body);
        $this->assertDatabaseCount('draft_submission_receipts', 0);
    }

    public function test_configuration_change_and_user_revocation_fence_provider_result(): void
    {
        $user = $this->admin();
        $this->enable($user);
        [$item,$run] = $this->request($user);
        Http::fake(function () {
            ProviderConnection::whereKey(1)->update(['enabled' => false]);

            return Http::response(['output' => ['title' => 'AI', 'body' => 'AI body']]);
        });
        app(Connector::class)->process($run->id);
        $this->assertSame('cancelled', $run->fresh()->status);
        $this->assertDatabaseCount('draft_submission_receipts', 0);
        ProviderConnection::whereKey(1)->update(['enabled' => true]);
        [$item2,$run2] = $this->request($user);
        $user->forceFill(['active' => false])->save();
        Http::fake();
        app(Connector::class)->process($run2->id);
        Http::assertNothingSent();
        $this->assertSame('cancelled', $run2->fresh()->status);
    }

    public function test_failure_redacts_provider_body_and_explicit_retry_can_succeed(): void
    {
        $user = $this->admin();
        $this->enable($user);
        [$item,$run] = $this->request($user);
        Http::fake([LangdockProvider::ENDPOINT => Http::sequence()->push('secret-provider-error', 429)->push(['output' => ['title' => 'AI', 'body' => 'Good']])]);
        app(Connector::class)->process($run->id);
        $this->assertSame('provider_rate_limited', $run->fresh()->error_code);
        $this->assertStringNotContainsString('secret-provider-error', $run->fresh()->toJson());
        app(Connector::class)->retry($run->id, $user);
        app(Connector::class)->process($run->id);
        $this->assertSame('succeeded', $run->fresh()->status);
        $this->assertSame(2, $run->fresh()->attempt);
    }

    public function test_invalid_output_and_provider_redirect_are_not_followed(): void
    {
        $user = $this->admin();
        $this->enable($user);
        [$item,$run] = $this->request($user);
        Http::fake([LangdockProvider::ENDPOINT => Http::sequence()->push(['output' => ['title' => 'x', 'body' => 'x', 'approved' => true]])->push('', 302, ['Location' => 'https://evil.test'])]);
        app(Connector::class)->process($run->id);
        $this->assertSame('invalid_result', $run->fresh()->error_code);
        app(Connector::class)->retry($run->id, $user);
        app(Connector::class)->process($run->id);
        Http::assertSentCount(2);
        $this->assertSame('provider_unavailable', $run->fresh()->error_code);
    }

    public function test_research_creates_topics_once_and_never_publishes(): void
    {
        $user = $this->admin();
        $this->enable($user);
        $run = app(Connector::class)->research($user, 'Research example');
        Http::fake([LangdockProvider::ENDPOINT => Http::response(['output' => ['sources' => [
            ['title' => 'Source', 'url' => 'https://example.org/evidence', 'summary' => 'Observed evidence'],
        ]]])]);
        app(Connector::class)->process($run->id);
        app(Connector::class)->process($run->id);
        $this->assertSame('succeeded', $run->fresh()->status);
        $this->assertDatabaseCount('opportunities', 1);
        $this->assertDatabaseCount('research_items', 1);
        $this->assertDatabaseCount('publications', 0);
        Http::assertSentCount(1);
    }

    public function test_configuration_key_is_encrypted_and_never_rendered_or_flashed(): void
    {
        $user = $this->admin();
        $this->enable($user);
        $this->assertNotSame('fixture-not-a-real-key', DB::table('provider_connections')->value('api_key'));
        $this->assertStringNotContainsString('fixture-not-a-real-key', ProviderConnection::find(1)->toJson());
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(ProviderConnector::class)->assertSee('Langdock')->assertDontSee('fixture-not-a-real-key');
        $this->post('/admin/connector-settings', ['revision' => 1, 'enabled' => 'bad', 'api_key' => 'do-not-flash-me'])
            ->assertSessionHasErrors()->assertSessionMissing('_old_input');
        $this->actingAs(User::factory()->create(['role' => UserRole::Curator]));
        $this->get('/admin/provider-connector')->assertForbidden();
        $this->post('/admin/connector-settings', [])->assertForbidden();
    }

    public function test_disabled_connection_does_not_enqueue_old_or_new_requests(): void
    {
        $user = $this->admin();
        $item = ContentItem::factory()->create(['submitted_by' => $user->id]);
        $entry = app(RecordAgentMessage::class)->record($item->id, $user, 'draft', 'Old request', (string) Str::uuid());
        $this->enable($user);
        $this->artisan('agents:dispatch')->assertSuccessful();
        $this->assertDatabaseCount('connector_runs', 0);
        $this->assertSame('awaiting_connection', $entry->fresh()->status);
    }

    public function test_adoption_rejects_later_edit_and_other_users(): void
    {
        $user = $this->admin();
        $this->enable($user);
        [$item,$run] = $this->request($user);
        Http::fake([LangdockProvider::ENDPOINT => Http::response(['output' => ['title' => 'AI', 'body' => 'AI']])]);
        app(Connector::class)->process($run->id);
        $item->update(['body' => 'Later human edit']);
        try {
            app(Connector::class)->adopt($run->id, $user);
            $this->fail('Stale proposal adopted');
        } catch (ConflictHttpException) {
            $this->assertSame('Later human edit', $item->fresh()->body);
        }
        $other = User::factory()->create(['role' => UserRole::Contributor]);
        $this->actingAs($other);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(AgentConversation::class, ['record' => $item->id])->assertNotFound();
    }

    public function test_interrupted_lease_blocks_late_result_and_requires_manual_retry(): void
    {
        $user = $this->admin();
        $this->enable($user);
        [$item,$run] = $this->request($user);
        Http::fake(function () {
            $this->travel(4)->minutes();
            app(Connector::class)->expire();

            return Http::response(['output' => ['title' => 'Late', 'body' => 'Late']]);
        });
        app(Connector::class)->process($run->id);
        $this->assertSame('worker_interrupted', $run->fresh()->error_code);
        $this->assertDatabaseCount('draft_submission_receipts', 0);
    }

    public function test_configuration_updates_keep_key_and_reject_stale_form(): void
    {
        $user = $this->admin();
        $this->enable($user);
        $this->actingAs($user)->post('/admin/connector-settings', [
            'revision' => 1, 'enabled' => 0, 'api_key' => '', 'writer_agent_id' => 'writer-test', 'research_agent_id' => '',
        ])->assertRedirect('/admin/provider-connector');
        $this->assertSame('fixture-not-a-real-key', ProviderConnection::find(1)->api_key);
        $this->assertFalse(ProviderConnection::find(1)->enabled);
        $this->post('/admin/connector-settings', [
            'revision' => 1, 'enabled' => 1, 'api_key' => '', 'writer_agent_id' => 'writer-test', 'research_agent_id' => '',
        ])->assertStatus(409);
        $this->assertFalse(ProviderConnection::find(1)->enabled);
    }

    public function test_duplicate_delivery_while_provider_is_running_does_not_call_twice(): void
    {
        $user = $this->admin();
        $this->enable($user);
        [$item,$run] = $this->request($user);
        Http::fake(function () use ($run) {
            app(Connector::class)->process($run->id);

            return Http::response(['output' => ['title' => 'One', 'body' => 'Only one']]);
        });
        app(Connector::class)->process($run->id);
        Http::assertSentCount(1);
        $this->assertDatabaseCount('draft_submission_receipts', 1);
    }

    public function test_adoption_stops_pending_publication_without_changing_approved_snapshot(): void
    {
        $user = $this->admin();
        $this->enable($user);
        [$item,$run] = $this->request($user);
        Http::fake([LangdockProvider::ENDPOINT => Http::response(['output' => ['title' => 'New', 'body' => 'New']])]);
        app(Connector::class)->process($run->id);
        $publication = $item->publications()->create(['channel' => 'website', 'status' => 'pending']);
        app(Connector::class)->adopt($run->id, $user);
        $this->assertSame('failed', $publication->fresh()->status->value);
        $this->assertSame('draft', $item->fresh()->status->value);
        $this->assertDatabaseCount('publication_attempts', 0);
    }

    public function test_successful_provider_result_and_receipt_roll_back_together(): void
    {
        $user = $this->admin();
        $this->enable($user);
        [$item,$run] = $this->request($user);
        Http::fake([LangdockProvider::ENDPOINT => Http::response(['output' => ['title' => 'New', 'body' => 'New']])]);
        ConnectorRun::saving(function ($model) {
            if ($model->status === 'succeeded') {
                throw new \RuntimeException('test failure');
            }
        });
        try {
            app(Connector::class)->process($run->id);
            $this->assertSame('failed', $run->fresh()->status);
            $this->assertDatabaseCount('draft_submission_receipts', 0);
            $this->assertDatabaseCount('content_versions', 1);
        } finally {
            ConnectorRun::flushEventListeners();
        }
    }
}
