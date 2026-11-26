<?php

use App\Providers\AgentCredentialServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;

return [
    AppServiceProvider::class,
    AgentCredentialServiceProvider::class,
    AdminPanelProvider::class,
];
