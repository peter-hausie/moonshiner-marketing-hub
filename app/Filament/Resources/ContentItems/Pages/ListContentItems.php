<?php

namespace App\Filament\Resources\ContentItems\Pages;

use App\Enums\ContentType;
use App\Filament\Resources\ContentItems\ContentItemResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListContentItems extends ListRecords
{
    protected static string $resource = ContentItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('Alle')
                ->badge(fn (): int => ContentItemResource::getEloquentQuery()->count()),
            ...collect(ContentType::cases())->mapWithKeys(fn (ContentType $type): array => [
                $type->value => Tab::make($type->label())
                    ->badge(fn (): int => ContentItemResource::getEloquentQuery()->where('content_type', $type)->count())
                    ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('content_type', $type)),
            ])->all(),
        ];
    }
}
