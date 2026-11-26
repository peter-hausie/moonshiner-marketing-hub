<?php

return [
    // Display names only: protocol identities remain stable across white-label installations.
    'labels' => [
        'journalist' => env('RESEARCH_AGENT_LABEL', 'Recherche-Agent'),
        'underwood' => env('DRAFT_AGENT_LABEL', 'Text-Agent'),
    ],

    // Non-secret locations only. Authentication is read from the host Agent sink per request.
    'credential_store' => [
        'enabled' => env('AGENT_CREDENTIAL_STORE_ENABLED', false),
        'mount_url' => env('AGENT_CREDENTIAL_STORE_MOUNT_URL'),
        'token_file' => env('AGENT_CREDENTIAL_STORE_TOKEN_FILE'),
        'ca_bundle' => env('AGENT_CREDENTIAL_STORE_CA_BUNDLE'),
    ],

    // Only boolean true opts in; ambiguous configuration must remain disabled.
    'draft_api_enabled' => env('AGENT_DRAFT_API_ENABLED', false),

    'research_api_enabled' => env('AGENT_RESEARCH_API_ENABLED', false),
];
