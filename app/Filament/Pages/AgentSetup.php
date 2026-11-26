<?php

namespace App\Filament\Pages;

use App\Models\AgentEditorialSetting;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

class AgentSetup extends Page
{
    protected static ?string $navigationLabel = 'Agenten-Setup';

    protected static ?string $title = 'Agenten-Setup';

    protected string $view = 'filament.pages.agent-setup';

    public string $researchName = '';

    public string $writerName = '';

    public string $tone = '';

    public string $researchBrief = '';

    public bool $monitoringRequired = true;

    public array $competitors = [];

    public string $revision = '';

    public static function canAccess(): bool
    {
        return auth()->user()?->isCurator() ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $settings = AgentEditorialSetting::all()->keyBy('role');
        $research = $settings->get('research');
        $writer = $settings->get('writer');
        $this->researchName = $research?->display_name ?? config('agents.labels.journalist', 'Recherche-Agent');
        $this->writerName = $writer?->display_name ?? config('agents.labels.underwood', 'Text-Agent');
        $this->tone = $writer?->briefing ?? '';
        $this->researchBrief = $research?->briefing ?? '';
        $this->monitoringRequired = $research?->monitoring_required ?? true;
        $this->competitors = $research?->competitors ?? [];
        $this->revision = $this->fingerprint();
    }

    public function addCompetitor(): void
    {
        abort_unless(static::canAccess(), 403);
        if (count($this->competitors) < 30) {
            $this->competitors[] = ['name' => '', 'url' => ''];
        }
    }

    public function removeCompetitor(int $index): void
    {
        abort_unless(static::canAccess(), 403);
        unset($this->competitors[$index]);
        $this->competitors = array_values($this->competitors);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->validate([
            'researchName' => 'required|string|max:100', 'writerName' => 'required|string|max:100',
            'tone' => 'nullable|string|max:20000', 'researchBrief' => 'nullable|string|max:20000',
            'monitoringRequired' => 'boolean', 'competitors' => 'array|max:30',
            'competitors.*' => 'array:name,url', 'competitors.*.name' => 'required|string|max:200',
            'competitors.*.url' => ['nullable', 'url:http,https', 'max:2048', function ($attribute, $value, $fail) {
                if ($value && (parse_url($value, PHP_URL_USER) !== null || parse_url($value, PHP_URL_PASS) !== null)) {
                    $fail('Links dürfen keine Zugangsdaten enthalten.');
                }
            }],
        ]);
        DB::transaction(function (): void {
            // A stable lock also covers first-time setup where no settings rows exist yet.
            DB::table('users')->orderBy('id')->lockForUpdate()->first();
            abort_unless(hash_equals($this->fingerprint(), $this->revision), 409, 'Setup wurde inzwischen geändert. Bitte Seite neu laden.');
            foreach ([
                'research' => ['display_name' => trim($this->researchName), 'briefing' => trim($this->researchBrief), 'competitors' => $this->competitors, 'monitoring_required' => $this->monitoringRequired],
                'writer' => ['display_name' => trim($this->writerName), 'briefing' => trim($this->tone), 'competitors' => [], 'monitoring_required' => false],
            ] as $role => $values) {
                $setting = AgentEditorialSetting::find($role) ?? new AgentEditorialSetting;
                $setting->forceFill(['role' => $role] + $values)->save();
            }
        });
        $this->revision = $this->fingerprint();
        Notification::make()->title('Redaktionelles Setup gespeichert')->success()->send();
    }

    private function fingerprint(): string
    {
        return hash('sha256', AgentEditorialSetting::orderBy('role')->get()->toJson());
    }
}
