<?php

namespace App\Filament\Resources\ContentItems\Pages;

use App\Enums\ContentStatus;
use App\Filament\Resources\ContentItems\ContentItemResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditContentItem extends EditRecord
{
    protected static string $resource = ContentItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('briefAgent')->label('Text-Agent briefen')->icon('heroicon-o-chat-bubble-left-right')
                ->url(fn (): string => ContentItemResource::getUrl('briefing', ['record' => $this->record])),
            Action::make('submit')
                ->label('Zur Prüfung einreichen')
                ->icon('heroicon-o-paper-airplane')
                ->color('primary')
                ->visible(fn (): bool => ! auth()->user()->isCurator()
                    && in_array($this->record->status, [ContentStatus::Draft, ContentStatus::Rejected], true))
                ->requiresConfirmation()
                ->action(fn () => $this->transitionTo(ContentStatus::Submitted, 'Inhalt wurde eingereicht.')),
            Action::make('startReview')
                ->label('Prüfung starten')
                ->icon('heroicon-o-magnifying-glass')
                ->visible(fn (): bool => auth()->user()->isCurator()
                    && $this->record->status === ContentStatus::Submitted)
                ->action(fn () => $this->transitionTo(ContentStatus::InReview, 'Prüfung wurde gestartet.')),
            Action::make('approve')
                ->label('Freigeben')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (): bool => auth()->user()->isCurator()
                    && in_array($this->record->status, [ContentStatus::Submitted, ContentStatus::InReview], true))
                ->requiresConfirmation()
                ->action(fn () => $this->transitionTo(ContentStatus::Approved, 'Inhalt wurde freigegeben.')),
            Action::make('reject')
                ->label('Zurückgeben')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('danger')
                ->visible(fn (): bool => auth()->user()->isCurator()
                    && in_array($this->record->status, [ContentStatus::Submitted, ContentStatus::InReview], true))
                ->requiresConfirmation()
                ->action(fn () => $this->transitionTo(ContentStatus::Rejected, 'Inhalt wurde zurückgegeben.')),
            DeleteAction::make()
                ->visible(fn (): bool => auth()->user()->isCurator()
                    || $this->record->status === ContentStatus::Draft),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        abort_unless(auth()->user()->can('update', $this->record), 403);
        if (! auth()->user()->isCurator()) {
            unset($data['status'], $data['scheduled_at'], $data['reviewed_by'], $data['review_notes'], $data['responsible_user_id']);
        }
        if (! empty($data['responsible_user_id'])) {
            abort_unless(User::whereKey($data['responsible_user_id'])->where('active', true)->exists(), 422);
        }

        return $data;
    }

    private function transitionTo(ContentStatus $status, string $message): void
    {
        abort_unless(auth()->user()->can('update', $this->record), 403);
        abort_unless($status === ContentStatus::Submitted || auth()->user()->isCurator(), 403);
        $this->record->update([
            'status' => $status,
            'reviewed_by' => auth()->user()->isCurator() ? auth()->id() : $this->record->reviewed_by,
        ]);

        $this->refreshFormData(['status', 'reviewed_by']);

        Notification::make()
            ->success()
            ->title($message)
            ->send();
    }
}
