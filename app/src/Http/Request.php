<?php

declare(strict_types=1);

namespace App\Http;

final readonly class Request
{
    /** @param array<string,string> $headers */
    public function __construct(
        public string $method,
        public string $path,
        public array $query,
        public array $post,
        public array $headers,
        public string $body,
    ) {
    }

    public static function fromGlobals(): self
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_') && is_string($value)) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = $value;
            }
        }

        return new self(
            method: strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            path: rawurldecode((string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/')),
            query: $_GET,
            post: $_POST,
            headers: $headers,
            body: (string) file_get_contents('php://input'),
        );
    }
}
