<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConnectorRun extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['context' => 'array', 'result_ids' => 'array', 'adoption_context' => 'array', 'started_at' => 'datetime', 'adopted_at' => 'datetime'];
    }

    public function label(): string
    {
        return match ($this->status) {
            'queued' => 'Wartet auf Verarbeitung', 'running' => 'In Bearbeitung', 'succeeded' => 'Vorschlag verfügbar',
            'adopted' => 'In Beitrag übernommen', 'conflict' => 'Konflikt: Beitrag wurde geändert',
            'failed' => 'Fehlgeschlagen', 'cancelled' => 'Gestoppt: Konfiguration oder Rechte geändert', default => $this->status,
        };
    }
}
