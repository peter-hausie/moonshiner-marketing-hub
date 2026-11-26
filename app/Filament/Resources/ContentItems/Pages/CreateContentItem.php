<?php

namespace App\Filament\Resources\ContentItems\Pages;

use App\Filament\Resources\ContentItems\ContentItemResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;

class CreateContentItem extends CreateRecord
{
    protected static string $resource = ContentItemResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['submitted_by'] = auth()->id();
        $data['responsible_user_id'] ??= auth()->id();
        if (! auth()->user()->isCurator()) {
            $data['status'] = 'draft';
            $data['responsible_user_id'] = auth()->id();
            unset($data['reviewed_by'], $data['review_notes'], $data['scheduled_at']);
        }
        if (! empty($data['responsible_user_id'])) {
            abort_unless(User::whereKey($data['responsible_user_id'])->where('active', true)->exists(), 422);
        }

        return $data;
    }
}
