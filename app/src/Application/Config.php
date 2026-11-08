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
    ) {
    }
}
