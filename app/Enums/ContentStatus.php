<?php

namespace App\Enums;

enum ContentStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case InReview = 'in_review';
    case Approved = 'approved';
    case Scheduled = 'scheduled';
    case Published = 'published';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Entwurf',
            self::Submitted => 'Eingereicht',
            self::InReview => 'In Prüfung',
            self::Approved => 'Freigegeben',
            self::Scheduled => 'Geplant',
            self::Published => 'Veröffentlicht',
            self::Rejected => 'Abgelehnt',
        };
    }
}
