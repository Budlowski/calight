<?php

declare(strict_types=1);

namespace App\Http;

final class Router
{
    /** @var list<array{method:string,pattern:string,handler:callable}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $this->routes[] = ['method' => $method, 'pattern' => $pattern, 'handler' => $handler];
    }

    /**
     * Registrierte Routen (Methode und Muster), z. B. für den Abgleich mit AuthorizationPolicy.
     *
     * @return list<array{method:string,pattern:string}>
     */
    public function routes(): array
    {
        return array_map(static fn (array $route): array => ['method' => $route['method'], 'pattern' => $route['pattern']], $this->routes);
    }

    public function dispatch(Request $request): Response
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== $request->method) {
                continue;
            }
            $pattern = preg_replace('/\{([a-zA-Z][a-zA-Z0-9_]*)\}/', '(?P<$1>[^/]+)', $route['pattern']);
            if ($pattern !== null && preg_match('#^' . $pattern . '$#D', $request->path, $matches) === 1) {
                $parameters = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                return ($route['handler'])($request, $parameters);
            }
        }
        return new Response('<h1>404</h1><p>Seite nicht gefunden.</p>', 404, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
