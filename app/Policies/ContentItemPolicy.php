<?php

namespace App\Policies;

use App\Enums\ContentStatus;
use App\Models\ContentItem;
use App\Models\User;

class ContentItemPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->active ?? true;
    }

    public function create(User $user): bool
    {
        return $user->active ?? true;
    }

    public function view(User $user, ContentItem $item): bool
    {
        return ($user->active ?? true) && ($user->isCurator() || $item->submitted_by === $user->id || $item->responsible_user_id === $user->id);
    }

    public function update(User $user, ContentItem $item): bool
    {
        return $this->view($user, $item);
    }

    public function delete(User $user, ContentItem $item): bool
    {
        return $this->view($user, $item) && ($user->isCurator() || in_array($item->status, [ContentStatus::Draft, ContentStatus::Rejected], true));
    }

    public function deleteAny(User $user): bool
    {
        return $user->isCurator();
    }
}
