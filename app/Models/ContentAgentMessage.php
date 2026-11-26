<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContentAgentMessage extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['context_snapshot' => 'array'];
    }
}
