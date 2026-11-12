<?php

declare(strict_types=1);

namespace App\Http;

final readonly class Request
{
    /**
     * @param array<string,string> $headers
     * @param array<string,array{name:string,type:string,tmp_name:string,error:int,size:int}> $files
     *        Einzeldatei-Uploads je Feldname (Mehrfachfelder werden nicht unterstützt)
     */
    public function __construct(
        public string $method,
        public string $path,
        public array $query,
        public array $post,
        public array $headers,
        public string $body,
        public array $files = [],
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
            files: self::singleFiles($_FILES),
        );
    }

    /**
     * @param array<string,mixed> $files
     * @return array<string,array{name:string,type:string,tmp_name:string,error:int,size:int}>
     */
    private static function singleFiles(array $files): array
    {
        $result = [];
        foreach ($files as $field => $file) {
            if (is_array($file) && is_string($file['name'] ?? null) && is_string($file['tmp_name'] ?? null)) {
                $result[(string) $field] = [
                    'name' => $file['name'],
                    'type' => (string) ($file['type'] ?? ''),
                    'tmp_name' => $file['tmp_name'],
                    'error' => (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE),
                    'size' => (int) ($file['size'] ?? 0),
                ];
            }
        }
        return $result;
    }
}
