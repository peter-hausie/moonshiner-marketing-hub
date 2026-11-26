<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Read model; review writes belong to RecordPublicationReview. */
class PublicationReview extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
