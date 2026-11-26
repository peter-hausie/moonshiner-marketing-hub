<?php

namespace App\Filament\Resources\ContentItems\Pages;

use App\Filament\Resources\ContentItems\ContentItemResource;
use App\Models\AgentEditorialSetting;
use App\Models\ConnectorRun;
use App\Models\ContentAgentMessage;
use App\Models\ProviderConnection;
use App\Services\Connector\Connector;
use App\Services\Content\RecordAgentMessage;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\WithPagination;

class AgentConversation extends Page
{
    use InteractsWithRecord;
    use WithPagination;

    protected static string $resource = ContentItemResource::class;

    protected static ?string $title = 'Text-Agent briefen';

    protected string $view = 'filament.content.agent-conversation';

    public string $kind = 'draft';

    public string $message = '';

    #[Locked]
    public string $requestKey;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->requestKey = (string) Str::uuid();
    }

    public function saveMessage(): void
    {
        $this->validate(['kind' => 'required|in:draft,feedback', 'message' => 'required|string|max:10000']);
        app(RecordAgentMessage::class)->record($this->getRecord()->id, auth()->user(), $this->kind, $this->message, $this->requestKey);
        $this->message = '';
        $this->requestKey = (string) Str::uuid();
        Notification::make()->success()->title('Auftrag gespeichert; Status im Verlauf')->send();
    }

    public function retryRun(int $id): void
    {
        $run = ConnectorRun::findOrFail($id);
        abort_unless(($run->context['draft']['content_item_id'] ?? null) === $this->getRecord()->id, 404);
        app(Connector::class)->retry($id, auth()->user());
    }

    public function adoptRun(int $id): void
    {
        $run = ConnectorRun::findOrFail($id);
        abort_unless(($run->context['draft']['content_item_id'] ?? null) === $this->getRecord()->id, 404);
        app(Connector::class)->adopt($id, auth()->user());
        Notification::make()->success()->title('Vorschlag als Entwurf übernommen. Keine Veröffentlichung.')->send();
    }

    protected function getViewData(): array
    {
        $item = static::getResource()::getEloquentQuery()->findOrFail($this->getRecord()->id);

        return [
            'piece' => $item,
            'connected' => ProviderConnection::find(1)?->enabled ?? false,
            'runs' => ConnectorRun::whereIn('message_id', ContentAgentMessage::where('content_item_id', $item->id)->select('id'))->get()->keyBy('message_id'),
            'proposals' => DB::table('content_versions')->where('content_item_id', $item->id)->get()->keyBy('id'),
            'writer' => AgentEditorialSetting::find('writer'),
            'messages' => ContentAgentMessage::where('content_item_id', $item->id)->orderByDesc('id')->paginate(20),
        ];
    }
}
