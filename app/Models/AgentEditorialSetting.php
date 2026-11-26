<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Installation-local editorial configuration, never external agent credentials. */
class AgentEditorialSetting extends Model
{
    protected $table = 'agent_editorial_settings';

    protected $primaryKey = 'role';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['competitors' => 'array', 'monitoring_required' => 'boolean'];
    }
}
