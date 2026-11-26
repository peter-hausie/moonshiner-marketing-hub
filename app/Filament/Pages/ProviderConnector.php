<?php

namespace App\Filament\Pages;

use App\Models\ConnectorRun;
use App\Models\ProviderConnection;
use App\Services\Connector\Connector;
use App\Services\Connector\ContentProvider;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class ProviderConnector extends Page
{
    protected static ?string $navigationLabel = 'Langdock Verbindung';

    protected static ?string $title = 'Langdock Verbindung';

    protected string $view = 'filament.pages.provider-connector';

    public string $researchInstruction = '';

    public static function canAccess(): bool
    {
        return auth()->user()?->canManageAccounts() ?? false;
    }

    public function testConnection(): void
    {
        abort_unless(static::canAccess(), 403);
        try {
            $connection = ProviderConnection::findOrFail(1);
            app(ContentProvider::class)->generate($connection, 'draft', [], 'Verbindungstest: Antworte mit Titel Verbindungstest und Text OK. Keine Werkzeuge aufrufen.');
            Notification::make()->success()->title('Textagent erreichbar; strukturiertes Ergebnis erhalten')->send();
        } catch (\Throwable) {
            Notification::make()->danger()->title('Test fehlgeschlagen. Schlüssel, Freigabe und Agenten ID in Langdock prüfen.')->send();
        }
    }

    public function startResearch(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->validate(['researchInstruction' => 'required|string|max:10000']);
        app(Connector::class)->research(auth()->user(), $this->researchInstruction);
        $this->researchInstruction = '';
        Notification::make()->success()->title('Recherche eingereiht')->send();
    }

    public function retry(int $id): void
    {
        abort_unless(static::canAccess(), 403);
        app(Connector::class)->retry($id, auth()->user());
    }

    protected function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);
        $connection = ProviderConnection::find(1);

        // API key never leaves server memory, including Livewire snapshots.
        return ['connection' => $connection?->only(['enabled', 'revision', 'writer_agent_id', 'research_agent_id']),
            'hasKey' => $connection && $connection->getRawOriginal('api_key') !== null,
            'runs' => ConnectorRun::orderByDesc('id')->limit(30)->get()];
    }
}
