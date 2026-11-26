<?php

namespace App\Filament\Widgets;

use App\Enums\ContentType;
use App\Filament\Resources\ContentItems\ContentItemResource;
use Filament\Widgets\ChartWidget;

class ContentTypeChart extends ChartWidget
{
    protected ?string $heading = 'Pieces nach Inhaltstyp';

    protected function getData(): array
    {
        $types = collect(ContentType::cases());
        $counts = ContentItemResource::getEloquentQuery()
            ->selectRaw('content_type, count(*) as aggregate')
            ->groupBy('content_type')
            ->pluck('aggregate', 'content_type');

        return [
            'datasets' => [[
                'label' => 'Pieces',
                'data' => $types->map(fn (ContentType $type): int => (int) ($counts[$type->value] ?? 0))->all(),
                'backgroundColor' => ['#2563eb', '#7c3aed', '#f59e0b', '#10b981'],
            ]],
            'labels' => $types->map(fn (ContentType $type): string => $type->label())->all(),
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }
}
