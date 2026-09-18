<?php
declare(strict_types=1);

namespace YouthSync\Http;

final class Router
{
    /** @var list<array{method:string,path:string,regex:string,params:list<string>,handler:callable}> */
    private array $routes = [];

    public function add(string $method, string $path, callable $handler): void
    {
        $path = self::normalize($path);
        $params = [];
        $regex = preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', static function (array $m) use (&$params): string {
            $params[] = $m[1];
            return '([0-9]+)';
        }, $path);
        $this->routes[] = [
            'method' => strtoupper($method),
            'path' => $path,
            'regex' => '#^' . $regex . '$#',
            'params' => $params,
            'handler' => $handler,
        ];
    }

    public function dispatch(string $method, string $path): void
    {
        $method = strtoupper($method);
        $path = self::normalize($path);

        $matchedOtherMethod = false;
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $matches)) {
                continue;
            }
            if ($route['method'] !== $method) {
                $matchedOtherMethod = true;
                continue;
            }
            $params = [];
            foreach ($route['params'] as $i => $name) {
                $params[$name] = $matches[$i + 1];
            }
            ($route['handler'])($params);
            return;
        }

        if ($matchedOtherMethod) {
            Json::error('METHOD_NOT_ALLOWED', 'This HTTP method is not allowed for this resource.', 405);
        }

        Json::error('NOT_FOUND', 'The requested resource was not found.', 404);
    }

    public static function normalize(string $path): string
    {
        $path = '/' . trim($path, '/');
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }
        return $path;
    }

    public static function requestPath(string $basePath): string
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        if (!is_string($uri) || $uri === '') {
            $uri = '/';
        }

        if ($basePath !== '' && str_starts_with($uri, $basePath)) {
            $uri = substr($uri, strlen($basePath)) ?: '/';
        }

        if (str_starts_with($uri, '/public')) {
            $uri = substr($uri, strlen('/public')) ?: '/';
        }

        return self::normalize($uri);
    }
}
