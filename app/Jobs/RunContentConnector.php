<?php

namespace App\Jobs;

use App\Services\Connector\Connector;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Queue contains only a row ID, never the provider key or a serialized request. */
class RunContentConnector implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $runId) {}

    public function handle(Connector $connector): void
    {
        $connector->process($this->runId);
    }
}
