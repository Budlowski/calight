<?php

declare(strict_types=1);

namespace App\Application;

final readonly class Config
{
    public function __construct(
        public string $databasePath,
        public string $offersPath,
        public string $documentsPath,
        public string $templatesPath,
        public string $sessionName,
        public bool $secureCookies,
        public string $timezone,
        // Optionaler eigener Hostname des mobilen Frontends (z. B. m.calight.example.de), ohne Port.
        // Leer: Das mobile Frontend ist nur unter /mobile/ auf demselben Host erreichbar.
        public string $mobileHost = '',
    ) {
    }
}
