<?php

namespace App\Filament\Widgets;

use App\Enums\ContentStatus;
use App\Filament\Resources\ContentItems\ContentItemResource;
use App\Filament\Resources\Pipeline\PipelineResource;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class EditorialOverview extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $query = ContentItemResource::getEloquentQuery();
        $isCurator = auth()->user()?->isCurator() ?? false;
        $reviewCount = (clone $query)
            ->whereIn('status', [ContentStatus::Submitted, ContentStatus::InReview])
            ->count();
        $pipelineCount = PipelineResource::getEloquentQuery()->count();

        return [
            Stat::make('Content gesamt', (clone $query)->count())
                ->description('Alle sichtbaren Pieces')
                ->icon('heroicon-o-document-text')
                ->color('primary')
                ->url(ContentItemResource::getUrl()),
            Stat::make($isCurator ? 'Warten auf dein Review' : 'Zur Prüfung eingereicht', $reviewCount)
                ->description($reviewCount === 1 ? 'Ein Piece braucht Aufmerksamkeit' : 'Pieces brauchen Aufmerksamkeit')
                ->icon('heroicon-o-eye')
                ->color($reviewCount > 0 ? 'warning' : 'success')
                ->url(ContentItemResource::getUrl()),
            Stat::make('In der Pipeline', $pipelineCount)
                ->description('Finale Pieces mit Termin')
                ->icon('heroicon-o-calendar-days')
                ->color('success')
                ->url(PipelineResource::getUrl()),
            Stat::make('Bereits veröffentlicht', (clone $query)->where('status', ContentStatus::Published)->count())
                ->description('Als veröffentlicht markiert')
                ->icon('heroicon-o-check-badge')
                ->color('gray'),
        ];
    }
}
