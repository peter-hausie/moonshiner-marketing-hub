<?php

namespace App\Services\Agents;

use SensitiveParameter;

interface OpenBaoTransport
{
    /** Return only HTTP status and decoded metadata/write acknowledgement. */
    public function request(string $method, string $path, #[SensitiveParameter] array $payload = []): array;
}
