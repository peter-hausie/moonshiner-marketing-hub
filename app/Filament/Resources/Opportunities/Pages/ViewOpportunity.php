<?php

namespace App\Filament\Resources\Opportunities\Pages;

use App\Filament\Resources\ContentItems\ContentItemResource;
use App\Filament\Resources\Opportunities\OpportunityResource;
use App\Services\Content\AdoptOpportunity;
use Filament\Resources\Pages\ViewRecord;

class ViewOpportunity extends ViewRecord
{
    protected string $view = 'filament.opportunities.detail';

    public function adopt(): void
    {
        $item = app(AdoptOpportunity::class)->adopt($this->getRecord()->id, auth()->user());
        $this->redirect(ContentItemResource::getUrl('edit', ['record' => $item]));
    }

    protected static string $resource = OpportunityResource::class;
}
