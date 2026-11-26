<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProviderConnection extends Model
{
    protected $guarded = ['*'];

    protected $hidden = ['api_key'];

    protected function casts(): array
    {
        return ['revision' => 'integer', 'enabled' => 'boolean', 'api_key' => 'encrypted'];
    }
}
