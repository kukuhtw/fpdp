<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\JsonEnvelope;
use App\Core\Http\Response;

final class HealthController
{
    public function show(): Response
    {
        return JsonEnvelope::success([
            'status' => 'OK',
            'version' => '0.1.0',
            'dependencies' => [],
        ]);
    }
}
