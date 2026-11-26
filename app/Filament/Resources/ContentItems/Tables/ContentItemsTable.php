<?php

namespace App\Filament\Resources\ContentItems\Tables;

use App\Enums\ContentStatus;
use App\Enums\ContentType;
use App\Filament\Resources\ContentItems\ContentItemResource;
use App\Models\ContentItem;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ContentItemsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('image_path')
                    ->label('Bild')
                    ->disk('public')
                    ->square()
                    ->defaultImageUrl(asset('images/content-placeholder.svg')),
                TextColumn::make('title')
                    ->label('Titel')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('submitter.name')
                    ->label('Eingereicht von')
                    ->sortable(),
                TextColumn::make('content_type')
                    ->label('Typ')
                    ->formatStateUsing(fn (ContentType $state): string => $state->label())
                    ->badge()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->formatStateUsing(fn (ContentStatus $state): string => $state->label())
                    ->badge(),
                TextColumn::make('channels')
                    ->label('Kanäle')
                    ->badge(),
                TextColumn::make('scheduled_at')
                    ->label('Geplant für')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('updated_at')
                    ->label('Aktualisiert')
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('content_type')
                    ->label('Typ')
                    ->options(collect(ContentType::cases())->mapWithKeys(
                        fn (ContentType $type): array => [$type->value => $type->label()],
                    )->all()),
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(collect(ContentStatus::cases())->mapWithKeys(
                        fn (ContentStatus $status): array => [$status->value => $status->label()],
                    )->all()),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('briefAgent')->label('Text-Agent')->icon('heroicon-o-chat-bubble-left-right')
                    ->url(fn (ContentItem $record): string => ContentItemResource::getUrl('briefing', ['record' => $record])),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
