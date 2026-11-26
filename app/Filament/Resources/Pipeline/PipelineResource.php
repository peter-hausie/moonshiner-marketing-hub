<?php

namespace App\Filament\Resources\Pipeline;

use App\Enums\ContentStatus;
use App\Enums\ContentType;
use App\Filament\Resources\ContentItems\ContentItemResource;
use App\Filament\Resources\Pipeline\Pages\ListPipeline;
use App\Models\ContentItem;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class PipelineResource extends Resource
{
    protected static ?string $model = ContentItem::class;

    protected static ?string $slug = 'pipeline';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Inhalte';

    protected static ?string $navigationLabel = 'Pipeline';

    protected static ?string $modelLabel = 'Pipeline';

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('image_path')
                    ->label('Bild')
                    ->disk('public')
                    ->square(),
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
                    ->label('Finaler Status')
                    ->formatStateUsing(fn (ContentStatus $state): string => $state->label())
                    ->badge(),
            ])
            ->defaultSort('scheduled_at')
            ->recordUrl(fn (ContentItem $record): string => ContentItemResource::getUrl('edit', ['record' => $record]));
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->whereNotNull('scheduled_at')
            ->whereIn('status', [
                ContentStatus::Approved,
                ContentStatus::Scheduled,
                ContentStatus::Published,
            ]);

        $user = auth()->user();

        if ($user && ! $user->isCurator()) {
            $query->where('submitted_by', $user->id);
        }

        return $query;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPipeline::route('/'),
        ];
    }
}
