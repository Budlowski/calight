<?php

declare(strict_types=1);

namespace App\Http;

use JsonException;

final readonly class Response
{
    /** @param array<string,string> $headers */
    public function __construct(public string $body = '', public int $status = 200, public array $headers = [])
    {
    }

    public static function redirect(string $location, int $status = 303): self
    {
        return new self('', $status, ['Location' => $location]);
    }

    /** @param array<string,mixed> $data */
    public static function json(array $data, int $status = 200, array $headers = []): self
    {
        try {
            $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return new self('{"error":"Antwort konnte nicht erzeugt werden."}', 500, ['Content-Type' => 'application/json; charset=utf-8']);
        }
        return new self($body, $status, ['Content-Type' => 'application/json; charset=utf-8'] + $headers);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $this->body;
    }
}
