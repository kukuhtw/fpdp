<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Exceptions\HttpException;
use App\Core\Http\JsonEnvelope;
use App\Core\Http\Request;
use App\Core\Http\Response;

final class Router
{
    /**
     * @var array<int, array{method: string, path: string, handler: callable}>
     */
    private array $routes = [];

    public function get(string $path, callable $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, callable $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    public function patch(string $path, callable $handler): void
    {
        $this->add('PATCH', $path, $handler);
    }

    public function put(string $path, callable $handler): void
    {
        $this->add('PUT', $path, $handler);
    }

    public function delete(string $path, callable $handler): void
    {
        $this->add('DELETE', $path, $handler);
    }

    private function add(string $method, string $path, callable $handler): void
    {
        $this->routes[] = ['method' => $method, 'path' => $path, 'handler' => $handler];
    }

    public function dispatch(Request $request): Response
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== $request->method) {
                continue;
            }

            $params = $this->match($route['path'], $request->path);
            if ($params !== null) {
                try {
                    return ($route['handler'])($request, $params);
                } catch (HttpException $e) {
                    return JsonEnvelope::error($e->getErrorCode(), $e->getMessage(), $e->getStatusCode(), $e->getDetails());
                }
            }
        }

        return JsonEnvelope::error('NOT_FOUND', 'The requested resource was not found.', 404);
    }

    /**
     * @return array<string, string>|null
     */
    private function match(string $pattern, string $path): ?array
    {
        $patternSegments = self::segments($pattern);
        $pathSegments = self::segments($path);

        if (count($patternSegments) !== count($pathSegments)) {
            return null;
        }

        $params = [];

        foreach ($patternSegments as $index => $segment) {
            if (str_starts_with($segment, '{') && str_ends_with($segment, '}')) {
                $params[substr($segment, 1, -1)] = $pathSegments[$index];
                continue;
            }

            if (preg_match('/^(.*)\{([A-Za-z][A-Za-z0-9_]*)\}(.*)$/', $segment, $matches) === 1) {
                $prefix = $matches[1];
                $suffix = $matches[3];
                $value = $pathSegments[$index];
                if (!str_starts_with($value, $prefix) || !str_ends_with($value, $suffix)) {
                    return null;
                }
                $length = strlen($value) - strlen($prefix) - strlen($suffix);
                if ($length <= 0) {
                    return null;
                }
                $params[$matches[2]] = substr($value, strlen($prefix), $length);
                continue;
            }

            if ($segment !== $pathSegments[$index]) {
                return null;
            }
        }

        return $params;
    }

    /**
     * @return array<int, string>
     */
    private static function segments(string $path): array
    {
        $trimmed = trim($path, '/');

        return $trimmed === '' ? [] : explode('/', $trimmed);
    }
}
