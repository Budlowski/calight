<?php

declare(strict_types=1);

namespace App\Application;

use App\Security\RolePermissions;

/**
 * Auswahl zwischen Büro- und mobiler Oberfläche.
 *
 * Beide Oberflächen nutzen dieselben Routen-, Rollen-, Modul- und Datensatzrechte. Die Auswahl
 * bestimmt ausschließlich den Einstieg nach der Anmeldung und ist keine Sicherheitsgrenze.
 * Monteure landen immer in der mobilen Oberfläche; Büro und Administratoren standardmäßig in der
 * Büroansicht und können über das Benutzermenü wechseln (Recht `interface.switch`). Es gibt
 * bewusst keine Weiterleitung anhand der Bildschirmgröße.
 */
final readonly class UserInterface
{
    public const OFFICE = 'office';
    public const MOBILE = 'mobile';
    public const OFFICE_HOME = '/dashboard';
    public const MOBILE_HOME = '/mobile/';

    /** @param string $mobileHost optionaler eigener Hostname des mobilen Frontends (leer: keiner) */
    public function __construct(private string $mobileHost = '')
    {
    }

    /** Darf das Konto die Oberfläche selbst wählen? */
    public static function canSwitch(array $user): bool
    {
        return RolePermissions::allows((string) ($user['role'] ?? ''), 'interface.switch');
    }

    /** Wirksame Oberfläche eines Kontos aus Rolle und gespeicherter Einstellung. */
    public static function preferred(array $user): string
    {
        if (!self::canSwitch($user)) {
            return ($user['role'] ?? '') === 'technician' ? self::MOBILE : self::OFFICE;
        }
        $stored = $user['preferredInterface'] ?? null;
        return in_array($stored, [self::OFFICE, self::MOBILE], true) ? $stored : self::OFFICE;
    }

    public static function home(string $interface): string
    {
        return $interface === self::MOBILE ? self::MOBILE_HOME : self::OFFICE_HOME;
    }

    /**
     * Einstiegspfad nach der Anmeldung bzw. für „/“. Auf dem optional konfigurierten mobilen
     * Hostnamen ist das immer die mobile Oberfläche – Rechte ändern sich dadurch nicht.
     */
    public function landingPath(array $user, string $hostHeader): string
    {
        if ($this->isMobileHost($hostHeader)) {
            return self::MOBILE_HOME;
        }
        return self::home(self::preferred($user));
    }

    public function isMobileHost(string $hostHeader): bool
    {
        if ($this->mobileHost === '') {
            return false;
        }
        // Host-Header ohne Port vergleichen (IPv6-Literale in Klammern bleiben unverändert).
        $host = strtolower(trim($hostHeader));
        if (!str_starts_with($host, '[')) {
            $host = (string) preg_replace('/:\d+$/', '', $host);
        }
        return $host === $this->mobileHost;
    }
}
