<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Exceptions\TooManyRequestsException;
use App\Repositories\RateLimitRepository;
use App\Services\Security\RateLimiter;

$connection = new PDO('sqlite::memory:');
$connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$connection->exec('
    CREATE TABLE rate_limits (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        rate_key TEXT NOT NULL UNIQUE,
        attempts INTEGER NOT NULL DEFAULT 1,
        window_started_at TIMESTAMP NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
');

$limiter = new RateLimiter(new RateLimitRepository($connection));

// Up to the limit succeeds.
for ($i = 0; $i < 3; $i++) {
    $limiter->hit('test_action', '203.0.113.5', 3, 60);
}

// The next hit within the same window is rejected.
$rejected = false;
try {
    $limiter->hit('test_action', '203.0.113.5', 3, 60);
} catch (TooManyRequestsException $e) {
    $rejected = true;
}
if (!$rejected) {
    fwrite(STDERR, "Exceeding the limit within the window was not rejected\n");
    exit(1);
}

// A different identifier has its own independent counter.
try {
    $limiter->hit('test_action', '198.51.100.9', 3, 60);
} catch (TooManyRequestsException $e) {
    fwrite(STDERR, "A different identifier was incorrectly rate limited\n");
    exit(1);
}

// A different action for the same identifier also has its own counter.
try {
    $limiter->hit('other_action', '203.0.113.5', 3, 60);
} catch (TooManyRequestsException $e) {
    fwrite(STDERR, "A different action was incorrectly rate limited\n");
    exit(1);
}

fwrite(STDOUT, "Rate limit test passed\n");
