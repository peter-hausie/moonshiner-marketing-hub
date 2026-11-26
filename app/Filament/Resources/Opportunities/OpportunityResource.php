<?php

namespace App\Filament\Resources\Opportunities;

use App\Filament\Resources\ContentItems\ContentItemResource;
use App\Filament\Resources\Opportunities\Pages\ListOpportunities;
use App\Filament\Resources\Opportunities\Pages\ViewOpportunity;
use App\Models\ContentItem;
use App\Models\Opportunity;
use BackedEnum;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class OpportunityResource extends Resource
{
    protected static ?string $model = Opportunity::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLightBulb;

    protected static string|UnitEnum|null $navigationGroup = 'Inhalte';

    protected static ?string $modelLabel = 'Themenvorschlag';

    protected static ?string $pluralModelLabel = 'Themenvorschläge';

    protected static ?string $slug = 'opportunities';

    protected static ?int $navigationSort = 1;

    public static function canViewAny(): bool
    {
        return auth()->user()?->isCurator() ?? false;
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
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

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['researchItems', 'contentItems']);

        return static::canViewAny() ? $query : $query->whereRaw('1 = 0');
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Stack::make([
                ViewColumn::make('title')->label('Themenvorschlag')->view('filament.opportunities.card')->searchable(),
            ]),
        ])->contentGrid(['md' => 2])->defaultSort('created_at', 'desc')->recordUrl(null)->recordAction(null);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('title')->label('Themenvorschlag')->columnSpanFull(),
            TextEntry::make('rationale')->label('Relevanz und redaktioneller Ansatz')->columnSpanFull(),
            RepeatableEntry::make('contentItems')->label('Bearbeitbare Entwürfe')->schema([
                TextEntry::make('title')->label('Entwurf öffnen')->url(fn (ContentItem $record): string => ContentItemResource::getUrl('edit', ['record' => $record])),
            ])->columnSpanFull(),
            TextEntry::make('editorial_note')->label('Nächster Schritt')
                ->state('Redaktionell prüfen. Dieser Vorschlag ist noch kein fertiger Beitrag und keine Veröffentlichungsfreigabe.')->columnSpanFull(),
            RepeatableEntry::make('researchItems')->label('Recherche und Originalquellen')->schema([
                TextEntry::make('title')->label('Fund'),
                TextEntry::make('source_url')->label('Originalquelle')->url(function (string $state): ?string {
                    return in_array(parse_url($state, PHP_URL_SCHEME), ['http', 'https'], true) ? $state : null;
                })->openUrlInNewTab(),
                TextEntry::make('excerpt')->label('Zusammenfassung und Einordnung'),
                TextEntry::make('observed_at')->label('Erfasst am')->dateTime('d.m.Y H:i'),
                TextEntry::make('provenance')->label('Herkunft'),
            ])->columnSpanFull(),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListOpportunities::route('/'), 'view' => ViewOpportunity::route('/{record}')];
    }
}
