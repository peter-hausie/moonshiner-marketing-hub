<?php

namespace App\Console\Commands;

use App\Jobs\RunContentConnector;
use App\Models\ConnectorRun;
use App\Models\ProviderConnection;
use App\Services\Connector\Connector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class DispatchConnector extends Command
{
    protected $signature = 'agents:dispatch';

    protected $description = 'Dispatch pending configured provider jobs without publishing';

    public function handle(Connector $connector): int
    {
        if (! Schema::hasTable('connector_runs')) {
            return self::SUCCESS;
        }
        $connector->expire();
        if (ProviderConnection::find(1)?->enabled) {
            ConnectorRun::where('status', 'queued')->orderBy('id')->limit(20)->get()
                ->each(fn ($run) => RunContentConnector::dispatch($run->id));
        }

        return self::SUCCESS;
    }
}
