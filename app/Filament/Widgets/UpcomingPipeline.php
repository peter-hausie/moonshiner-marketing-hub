<?php

namespace App\Filament\Widgets;

use App\Enums\ContentStatus;
use App\Enums\ContentType;
use App\Filament\Resources\ContentItems\ContentItemResource;
use App\Filament\Resources\Pipeline\PipelineResource;
use App\Models\ContentItem;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class UpcomingPipeline extends TableWidget
{
    protected static ?string $heading = 'Als Nächstes in der Pipeline';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => PipelineResource::getEloquentQuery()
                ->where('scheduled_at', '>=', now()->startOfDay())
                ->orderBy('scheduled_at')
                ->limit(8))
            ->columns([
                TextColumn::make('scheduled_at')
                    ->label('Geplant für')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
                TextColumn::make('title')
                    ->label('Titel')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('content_type')
                    ->label('Typ')
                    ->formatStateUsing(fn (ContentType $state): string => $state->label())
                    ->badge(),
                TextColumn::make('channels')
                    ->label('Kanäle')
                    ->badge(),
                TextColumn::make('status')
                    ->label('Status')
                    ->formatStateUsing(fn (ContentStatus $state): string => $state->label())
                    ->badge(),
            ])
            ->recordUrl(fn (ContentItem $record): string => ContentItemResource::getUrl('edit', ['record' => $record]))
            ->paginated(false)
            ->emptyStateHeading('Noch nichts final eingeplant')
            ->emptyStateDescription('Freigegebene Pieces mit Planungstermin erscheinen hier automatisch.');
    }
}
