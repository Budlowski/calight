<?php

declare(strict_types=1);

namespace App\View;

use App\Http\Response;
use App\Http\Session;
use RuntimeException;

final readonly class View
{
    public function __construct(private string $templateDirectory)
    {
    }

    /** @param array<string,mixed> $data */
    public function render(string $template, array $data = [], int $status = 200): Response
    {
        $templateFile = $this->templateDirectory . '/' . $template . '.php';
        if (!is_file($templateFile)) {
            throw new RuntimeException('Template wurde nicht gefunden: ' . $template);
        }

        $escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $csrfToken = Session::csrfToken();
        $currentUser = Session::user();
        $flashMessages = Session::consumeFlash();
        extract($data, EXTR_SKIP);
        ob_start();
        require $templateFile;
        $content = (string) ob_get_clean();

        ob_start();
        require $this->templateDirectory . '/layout/app.php';
        return new Response((string) ob_get_clean(), $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
