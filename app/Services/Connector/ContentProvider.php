<?php

namespace App\Services\Connector;

use App\Models\ProviderConnection;

interface ContentProvider
{
    public function generate(ProviderConnection $connection, string $kind, array $context, string $instruction): array;
}
