<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Pages\AgentSetup;
use App\Filament\Widgets\AgentSetupOverview;
use App\Models\AgentEditorialSetting;
use App\Models\User;
use App\Services\Agents\AgentSetupOverview as Status;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class AgentSetupTest extends TestCase
{
    use RefreshDatabase;

    private function curator(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Curator]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_brand_specific_setup_roundtrips_without_claiming_connectivity(): void
    {
        Http::fake();
        $this->curator();
        Livewire::test(AgentSetup::class)->set('writerName', 'Moon Writer')->set('researchName', 'Moon Scout')
            ->set('tone', 'Klar und direkt. Sie-Ansprache.')->set('researchBrief', 'IT im DACH-Raum')
            ->set('competitors', [['name' => 'Beispiel', 'url' => 'https://example.org/profile']])
            ->call('save')->assertHasNoErrors();
        Livewire::test(AgentSetup::class)->assertSet('writerName', 'Moon Writer')
            ->assertSet('competitors.0.url', 'https://example.org/profile');
        $rows = app(Status::class)->rows();
        $this->assertSame('Moon Scout', $rows[0]['name']);
        $this->assertTrue($rows[0]['checks'][0]['ok']);
        $this->assertSame('Setup erforderlich', $rows[0]['status']);
        Livewire::test(AgentSetupOverview::class)->assertSee('Moon Writer')->assertSee('Setup erforderlich');
        Http::assertNothingSent();
    }

    public function test_contributors_cannot_read_or_write_setup(): void
    {
        $this->get('/admin/agent-setup')->assertRedirect('/admin/login');
        $this->actingAs(User::factory()->create(['role' => UserRole::Contributor]));
        $this->get('/admin/agent-setup')->assertForbidden();
        $this->assertFalse(AgentSetupOverview::canView());
        Livewire::test(AgentSetup::class)->assertForbidden();
    }

    public function test_links_reject_active_schemes_and_embedded_credentials(): void
    {
        $this->curator();
        foreach (['javascript:alert(1)', 'https://user:pass@example.org'] as $url) {
            Livewire::test(AgentSetup::class)->set('competitors', [['name' => 'Example', 'url' => $url]])
                ->call('save')->assertHasErrors('competitors.0.url');
        }
        $this->assertDatabaseCount('agent_editorial_settings', 0);
    }

    public function test_stale_setup_cannot_overwrite_another_edit(): void
    {
        $this->curator();
        $first = Livewire::test(AgentSetup::class);
        Livewire::test(AgentSetup::class)->set('writerName', 'New writer')->call('save');
        $first->set('writerName', 'Old writer')->call('save')->assertStatus(409);
        $this->assertSame('New writer', AgentEditorialSetting::find('writer')->display_name);
    }

    public function test_overview_handles_partial_live_schema_without_errors(): void
    {
        Schema::drop('personal_access_tokens');
        $rows = app(Status::class)->rows();
        $this->assertCount(2, $rows);
        $this->assertSame('Setup erforderlich', $rows[0]['status']);
        $this->assertNull($rows[0]['last_success']);
    }
}
