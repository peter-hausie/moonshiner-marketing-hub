<?php

namespace App\Enums;

enum ContentType: string
{
    case Editorial = 'editorial';
    case Posting = 'posting';
    case Snippet = 'snippet';
    case Fact = 'fact';

    public function label(): string
    {
        return match ($this) {
            self::Editorial => 'Editorial / Blog',
            self::Posting => 'Posting',
            self::Snippet => 'Snippet',
            self::Fact => 'Fact',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Editorial => 'Großer Beitrag oder Blogartikel mit etwa 7–10 Minuten Lesezeit.',
            self::Posting => 'Ein einzelner Post für eine beliebige Plattform.',
            self::Snippet => 'Ein kurzer Denkanstoß.',
            self::Fact => 'Referenz auf eine Studie oder Quelle mit 1–2 Begleitsätzen.',
        };
    }
}
