<?php

namespace App\Filament\Resources\Opportunities\Pages;

use App\Filament\Resources\ContentItems\ContentItemResource;
use App\Filament\Resources\Opportunities\OpportunityResource;
use App\Services\Content\AdoptOpportunity;
use Filament\Resources\Pages\ListRecords;

class ListOpportunities extends ListRecords
{
    protected string $view = 'filament.opportunities.index';

    public function adoptTopic(int $id): void
    {
        $item = app(AdoptOpportunity::class)->adopt($id, auth()->user());
        $this->redirect(ContentItemResource::getUrl('edit', ['record' => $item]));
    }

    protected static string $resource = OpportunityResource::class;
}
