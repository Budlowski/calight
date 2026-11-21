<?php

declare(strict_types=1);

namespace App\View;

use App\Application\ModuleService;
use App\Http\Response;
use App\Http\Session;
use RuntimeException;

final readonly class View
{
    public function __construct(private string $templateDirectory, private ?ModuleService $modules = null)
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
        // Modulprüfung für Navigation und Oberfläche (ohne Registrierung: alles aktiv).
        $modules = $this->modules;
        $moduleEnabled = fn (string $module): bool => $this->modules === null || $this->modules->isEnabled($module);
        extract($data, EXTR_SKIP);
        ob_start();
        require $templateFile;
        $content = (string) ob_get_clean();

        // Layout: Büro (Standard) oder mobiles Frontend (`layout/mobile`); nur feste Werte zulässig.
        $layoutTemplate = ($data['layout'] ?? null) === 'layout/mobile' ? 'layout/mobile' : 'layout/app';
        ob_start();
        require $this->templateDirectory . '/' . $layoutTemplate . '.php';
        return new Response((string) ob_get_clean(), $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
