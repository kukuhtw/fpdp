<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Http\JsonEnvelope;
use App\Core\Http\Response;

final class HealthController
{
    public function show(): Response
    {
        return JsonEnvelope::success([
            'status' => 'OK',
            'version' => Config::get('APP_VERSION', '0.1.0'),
            'dependencies' => [],
        ]);
    }
}
