<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Illuminate\Support\Str;

/** Read-only, curated repository documentation; never accepts arbitrary file paths. */
class TechnicalHandover extends Page
{
    protected static ?string $navigationLabel = 'Technische Übergabe';

    protected static ?string $title = 'Moonshiner Marketing Hub: Übergabe';

    protected string $view = 'filament.pages.technical-handover';

    public static function canAccess(): bool
    {
        return auth()->user()?->isCurator() ?? false;
    }

    public function documents(): array
    {
        abort_unless(static::canAccess(), 403);
        $documents = [];
        foreach (['HANDOVER', 'OPERATIONS', 'AGENTS-INTEGRATION', 'NEXT-AGENT', 'ARCHITECTURE'] as $name) {
            $documents[$name] = Str::markdown(file_get_contents(base_path('docs/'.$name.'.md')), [
                'html_input' => 'strip', 'allow_unsafe_links' => false,
            ]);
        }

        return $documents;
    }
}
