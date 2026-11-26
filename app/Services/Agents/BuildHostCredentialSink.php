<?php

namespace App\Services\Agents;

use Closure;
use RuntimeException;
use Throwable;

/** Configuration-only construction: no token read, network call or container binding. */
final class BuildHostCredentialSink
{
    public function build(): OpenBaoCredentialSink
    {
        try {
            $url = config('agents.credential_store.mount_url');
            $path = config('agents.credential_store.token_file');
            $ca = config('agents.credential_store.ca_bundle');
            if (! is_string($url) || ! is_string($path) || ! str_starts_with($path, '/')
                || ($ca !== null && ! is_string($ca))) {
                throw new RuntimeException;
            }

            // Do not read/cache authentication during container resolution or config caching.
            $resolver = new OpenBaoAgentFileToken($path);

            return new OpenBaoCredentialSink(new CurlOpenBaoTransport(
                $url, Closure::fromCallable($resolver), $ca,
            ));
        } catch (Throwable) {
            throw new RuntimeException('Host credential store configuration unavailable.');
        }
    }
}
