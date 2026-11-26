<?php

namespace App\Filament\Resources\Publications;

use App\Enums\PublicationStatus;
use App\Filament\Resources\Publications\Pages\ListPublications;
use App\Models\Publication;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class PublicationResource extends Resource
{
    protected static ?string $model = Publication::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaperAirplane;

    protected static string|UnitEnum|null $navigationGroup = 'Inhalte';

    protected static ?string $modelLabel = 'Veröffentlichung';

    protected static ?string $pluralModelLabel = 'Veröffentlichungen';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('contentItem.title')->label('Inhalt')->searchable()->wrap(),
                TextColumn::make('channel')->label('Kanal')->badge(),
                TextColumn::make('content_version_id')->label('Version-ID')->placeholder('Keine Version gebunden'),
                TextColumn::make('review_evidence')->label('Letzte Freigabeentscheidung')->wrap()
                    ->getStateUsing(function (Publication $record): string {
                        $review = $record->latestReview;
                        if ($review === null) {
                            return 'Keine Freigabe protokolliert.';
                        }
                        if ($review->decision === 'revoked') {
                            return 'Freigabe widerrufen.';
                        }
                        if ($review->decision !== 'approved') {
                            return 'Freigabeentscheidung ungeklärt.';
                        }
                        if ($review->content_version_id !== $record->content_version_id || $review->channel !== $record->channel) {
                            return 'Freigabe für andere Version oder anderen Kanal; erneute Prüfung erforderlich.';
                        }

                        return 'Freigabe protokolliert; aktuelle Gültigkeit wird vor Versand erneut geprüft.';
                    }),
                TextColumn::make('latestReview.created_at')->label('Entschieden am')->dateTime('d.m.Y H:i'),
                TextColumn::make('scheduled_at')->label('Geplant für')->dateTime('d.m.Y H:i')->sortable(),
                TextColumn::make('status')->label('Versandstatus')->badge()
                    ->formatStateUsing(fn (PublicationStatus $state): string => self::statusLabels()[$state->value]),
                TextColumn::make('next_step')->label('Hinweis')->wrap()
                    ->getStateUsing(fn (Publication $record): string => match ($record->status) {
                        PublicationStatus::Pending => 'Versand nur mit gültiger Freigabe und erfüllten Voraussetzungen.',
                        PublicationStatus::Processing => 'Versand läuft oder Ergebnis fehlt. Nicht erneut senden; bei ausbleibendem Ergebnis beim Zielkanal prüfen.',
                        PublicationStatus::Failed => 'Ergebnis beim Zielkanal abgleichen. Ein Fehler beweist nicht, dass nichts veröffentlicht wurde. Nicht erneut senden.',
                        PublicationStatus::Published => 'Veröffentlichung bestätigt.',
                    }),
                TextColumn::make('latestExecutionAttempt.attempt_number')->label('Letzter Versuch')
                    ->placeholder('Kein Versuch protokolliert'),
                TextColumn::make('attempt_outcome')->label('Versuchsergebnis')->wrap()
                    ->getStateUsing(fn (Publication $record): string => match ($record->latestExecutionAttempt?->status) {
                        null => 'Keine protokollierte Ausführung; keine Aussage über frühere Veröffentlichungen.',
                        'started' => 'Versuch angelegt; Versand nicht bestätigt.',
                        'sending' => 'Versand begonnen; Ergebnis noch offen. Nicht erneut senden.',
                        'published' => 'Erfolg protokolliert.',
                        default => 'Ergebnis ungeklärt; beim Zielkanal abgleichen. Nicht erneut senden.',
                    }),
                TextColumn::make('latestExecutionAttempt.started_at')->label('Versuch gestartet')->dateTime('d.m.Y H:i'),
                TextColumn::make('latestExecutionAttempt.finished_at')->label('Versuch abgeschlossen')->dateTime('d.m.Y H:i'),
                TextColumn::make('published_at')->label('Veröffentlicht am')->dateTime('d.m.Y H:i'),
            ])
            ->filters([
                SelectFilter::make('status')->label('Versandstatus')->options(self::statusLabels()),
                SelectFilter::make('channel')->label('Kanal')->options(fn (): array => self::getEloquentQuery()
                    ->reorder()->distinct()->pluck('channel', 'channel')->all()),
            ])
            ->defaultSort('scheduled_at', 'desc')
            ->recordActions([])
            ->toolbarActions([])
            ->recordUrl(null);
    }

    private static function statusLabels(): array
    {
        return [
            'pending' => 'Ausstehend',
            'processing' => 'In Bearbeitung',
            'failed' => 'Abgleich erforderlich',
            'published' => 'Veröffentlicht',
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['contentItem', 'latestExecutionAttempt', 'latestReview']);
        $user = auth()->user();

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if (! $user->isCurator()) {
            $query->whereHas('contentItem', fn (Builder $items) => $items->where('submitted_by', $user->id));
        }

        return $query;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListPublications::route('/')];
    }
}
