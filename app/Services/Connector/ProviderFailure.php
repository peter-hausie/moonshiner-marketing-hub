<?php

namespace App\Services\Connector;

/** Only fixed diagnostic codes leave the adapter; never provider bodies or credentials. */
final class ProviderFailure extends \RuntimeException {}
