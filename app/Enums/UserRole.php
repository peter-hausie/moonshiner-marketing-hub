<?php

namespace App\Enums;

enum UserRole: string
{
    case Contributor = 'contributor';
    case Curator = 'curator';
}
