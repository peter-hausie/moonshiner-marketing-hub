<?php

namespace App\Filament\Widgets;

use App\Services\Agents\AgentSetupOverview as SetupStatus;
use Filament\Widgets\Widget;

class AgentSetupOverview extends Widget
{
    protected string $view = 'filament.widgets.agent-setup-overview';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = -10;

    protected static bool $isLazy = false;

    public static function canView(): bool
    {
        return auth()->user()?->isCurator() ?? false;
    }

    protected function getViewData(): array
    {
        // Authorize Livewire hydration as well as dashboard discovery.
        abort_unless(static::canView(), 403);

        return ['agents' => app(SetupStatus::class)->rows()];
    }
}
