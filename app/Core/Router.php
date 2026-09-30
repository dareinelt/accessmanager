<?php

declare(strict_types=1);

namespace App\Core;

final class Router
{
    private array $routes = [];

    public function get(string $path, mixed $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, mixed $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    public function put(string $path, mixed $handler): void
    {
        $this->add('PUT', $path, $handler);
    }

    public function patch(string $path, mixed $handler): void
    {
        $this->add('PATCH', $path, $handler);
    }

    public function delete(string $path, mixed $handler): void
    {
        $this->add('DELETE', $path, $handler);
    }

    private function add(string $method, string $path, mixed $handler): void
    {
        $this->routes[] = [
            'method' => $method,
            'regex' => $this->compile($path),
            'handler' => $handler,
        ];
    }

    private function compile(string $path): string
    {
        $regex = preg_replace('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', '(?P<$1>[^/]+)', $path);
        return '#^' . $regex . '$#';
    }

    public function dispatch(Request $request): never
    {
        $method = $request->method();
        $path = $request->path();

        if ($this->match($method, $path, $request)) {
            exit;
        }

        // Support method override (e.g. forms posting to PATCH/DELETE).
        if ($method === 'POST') {
            $override = $request->input('_method') ?: $request->header('x-http-method-override');
            if ($override && $this->match(strtoupper((string) $override), $path, $request)) {
                exit;
            }
        }

        Response::error('Route nicht gefunden', 'NOT_FOUND', 404);
    }

    private function match(string $method, string $path, Request $request): bool
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }
            if (!preg_match($route['regex'], $path, $matches)) {
                continue;
            }
            $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
            $this->invoke($route['handler'], $params, $request);
            return true;
        }
        return false;
    }

    private function invoke(mixed $handler, array $params, Request $request): void
    {
        if (is_callable($handler)) {
            call_user_func($handler, $request, $params);
            return;
        }

        if (is_string($handler) && str_contains($handler, '@')) {
            [$class, $method] = explode('@', $handler, 2);
            if (!class_exists($class)) {
                Response::error('Controller nicht gefunden', 'SERVER_ERROR', 500);
            }
            $controller = new $class();
            $controller->{$method}($request, $params);
            return;
        }

        Response::error('Ungültiger Route-Handler', 'SERVER_ERROR', 500);
    }
}
