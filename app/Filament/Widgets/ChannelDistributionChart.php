<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\ContentItems\ContentItemResource;
use Filament\Widgets\ChartWidget;

class ChannelDistributionChart extends ChartWidget
{
    protected ?string $heading = 'Geplante Posts nach Kanal';

    protected function getData(): array
    {
        $counts = ContentItemResource::getEloquentQuery()
            ->whereNotNull('channels')
            ->pluck('channels')
            ->flatten()
            ->countBy()
            ->sortDesc();

        return [
            'datasets' => [[
                'label' => 'Pieces',
                'data' => $counts->values()->all(),
                'backgroundColor' => '#2563eb',
                'borderRadius' => 6,
            ]],
            'labels' => $counts->keys()->all(),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
