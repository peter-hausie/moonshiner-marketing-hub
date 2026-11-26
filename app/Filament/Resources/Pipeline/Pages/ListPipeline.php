<?php

namespace App\Filament\Resources\Pipeline\Pages;

use App\Filament\Resources\Pipeline\PipelineResource;
use Filament\Resources\Pages\ListRecords;

class ListPipeline extends ListRecords
{
    protected static string $resource = PipelineResource::class;

    protected static ?string $title = 'Content-Pipeline';
}
