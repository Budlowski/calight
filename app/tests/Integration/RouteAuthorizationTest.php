<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\Application;
use App\Application\Bootstrap;
use App\Application\Config;
use App\Http\Request;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Database\Migrator;
use App\Security\AuthorizationPolicy;
use App\Security\RolePermissions;
use App\Tests\TestCase;

/**
 * Systemaudit F-03/F-18: Jede registrierte Route (Methode und Pfad) hat eine ausdrückliche Regel in
 * AuthorizationPolicy; ungeregelte Pfade sind für alle Rollen gesperrt. Neue Routen ohne Regel lassen
 * diesen Test fehlschlagen. Zusätzlich direkte Aufrufe ohne Anmeldung bzw. ohne Recht.
 */
final class RouteAuthorizationTest extends TestCase
{
    /** Öffentliche Routen (ohne Anmeldung) – jede Änderung hier ist eine bewusste Sicherheitsentscheidung. */
    private const PUBLIC_ROUTES = ['GET /', 'GET /login', 'POST /login', 'POST /logout', 'GET /mobile/manifest.webmanifest', 'GET /mobile/sw.js'];

    /** Nur für Administratoren (Stichproben über alle Bereiche, die Büro und Monteur nie erreichen dürfen). */
    private const ADMIN_ONLY_PREFIXES = ['/api/users', '/settings/users', '/settings/permissions', '/settings/modules', '/api/modules', '/settings/system', '/api/system/', '/api/operational-settings'];

    public function testEveryRegisteredRouteHasAnExplicitRule(): void
    {
        $this->withApplication(function (Application $application): void {
            $routes = $application->routes();
            $this->assertTrue(count($routes) > 200, 'Alle Controller registriert.');
            $public = [];
            foreach ($routes as $route) {
                $key = $route['method'] . ' ' . $route['pattern'];
                $required = AuthorizationPolicy::required($this->request($route['method'], $route['pattern']));
                $this->assertTrue($required !== [AuthorizationPolicy::UNREGISTERED], 'Route ohne Berechtigungsregel: ' . $key);
                if ($required === []) {
                    $public[] = $key;
                }
                foreach ($required as $permission) {
                    $this->assertTrue($this->knownPermission($permission), 'Unbekanntes Recht ' . $permission . ' für ' . $key);
                }
                // Rollen: Administratoren erreichen jede geregelte Route; Admin-Bereiche nie Büro oder Monteur.
                $this->assertTrue($this->allowed('admin', $required), 'Administrator gesperrt: ' . $key);
                foreach (self::ADMIN_ONLY_PREFIXES as $prefix) {
                    if (str_starts_with($route['pattern'], $prefix)) {
                        $this->assertTrue(!$this->allowed('office', $required) && !$this->allowed('technician', $required), 'Nur Administratoren: ' . $key);
                    }
                }
                if (str_starts_with($route['pattern'], '/api/purchase-orders') || str_starts_with($route['pattern'], '/api/offer-calculation')) {
                    $this->assertTrue(!$this->allowed('technician', $required), 'Keine kaufmännischen Daten für Monteure: ' . $key);
                }
            }
            sort($public);
            $expected = self::PUBLIC_ROUTES;
            sort($expected);
            $this->assertSame($expected, array_values(array_unique($public)), 'Öffentliche Routen sind ausdrücklich festgelegt.');
        });
    }

    public function testUnknownPathsAreDeniedForEveryRole(): void
    {
        foreach (['GET /api/gibt-es-nicht', 'POST /api/neu/route', 'GET /neue-seite', 'DELETE /api/x'] as $route) {
            [$method, $path] = explode(' ', $route, 2);
            $required = AuthorizationPolicy::required($this->request($method, $path));
            $this->assertSame([AuthorizationPolicy::UNREGISTERED], $required, $route);
            foreach (['admin', 'office', 'technician', 'employee'] as $role) {
                $this->assertTrue(!$this->allowed($role, $required), $role . ' darf ungeregelte Route nicht nutzen: ' . $route);
            }
        }
    }

    public function testDirectRequestsWithoutLoginOrPermissionAreRejected(): void
    {
        $this->withApplication(function (Application $application, \PDO $pdo): void {
            $_SESSION = [];
            $this->assertSame(401, $application->handle($this->request('GET', '/api/orders'))->status, 'API ohne Anmeldung.');
            $this->assertSame(401, $application->handle($this->request('GET', '/api/gibt-es-nicht'))->status, 'Unbekannte API ohne Anmeldung.');
            $this->assertSame(303, $application->handle($this->request('GET', '/orders'))->status, 'Seite ohne Anmeldung leitet zur Anmeldung.');
            foreach (['technician', 'office', 'admin'] as $role) {
                $id = 'u-' . $role;
                $pdo->prepare("INSERT INTO users(id,username,password_hash,display_name,role,active,created_at,updated_at) VALUES(:id,:name,'hash',:name,:role,1,'2026-11-01T00:00:00Z','2026-11-01T00:00:00Z')")
                    ->execute(['id' => $id, 'name' => $role . '-test', 'role' => $role]);
            }
            $login = function (string $role): void {
                $_SESSION = ['user' => ['id' => 'u-' . $role, 'username' => $role . '-test', 'displayName' => $role, 'role' => $role, 'sessionVersion' => 1], 'csrf_token' => 'token'];
            };
            $login('technician');
            foreach (['/api/users', '/api/system/purge', '/api/purchase-orders', '/api/operational-settings', '/api/modules'] as $path) {
                $this->assertSame(403, $application->handle($this->request('GET', $path))->status, 'Monteur: ' . $path);
            }
            $login('office');
            $this->assertSame(403, $application->handle($this->request('GET', '/api/users'))->status, 'Büro: Benutzerverwaltung.');
            $this->assertSame(403, $application->handle($this->request('GET', '/api/system/purge'))->status, 'Büro: Datenbereinigung.');
            $login('admin');
            $this->assertSame(403, $application->handle($this->request('GET', '/api/gibt-es-nicht'))->status, 'Auch Administratoren: ungeregelte Route gesperrt.');
            $_SESSION = [];
        });
    }

    /** @param list<string> $required */
    private function allowed(string $role, array $required): bool
    {
        foreach ($required as $permission) {
            if (!RolePermissions::allows($role, $permission)) {
                return false;
            }
        }
        return true;
    }

    private function knownPermission(string $permission): bool
    {
        foreach (RolePermissions::DESCRIPTIONS as $group) {
            if (isset($group[$permission])) {
                return true;
            }
        }
        return RolePermissions::allows('office', $permission) || RolePermissions::allows('technician', $permission);
    }

    private function request(string $method, string $pattern): Request
    {
        return new Request($method, (string) preg_replace('/\{[^}]+\}/', 'test-id', $pattern), [], [], [], '');
    }

    private function withApplication(callable $test): void
    {
        $tmp = sys_get_temp_dir() . '/calight-routes-' . bin2hex(random_bytes(6));
        foreach (['database', 'offers', 'documents', 'templates', 'backups'] as $directory) {
            mkdir($tmp . '/' . $directory, 0770, true);
        }
        try {
            $config = new Config($tmp . '/database/app.sqlite', $tmp . '/offers', $tmp . '/documents', $tmp . '/templates', 'calight_test', false, 'Europe/Berlin', '', $tmp . '/backups');
            $pdo = ConnectionFactory::create($config->databasePath);
            (new Migrator($pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
            $test((new Bootstrap($config, $pdo))->application(dirname(__DIR__, 2)), $pdo);
        } finally {
            $_SESSION = [];
            $pdo = null;
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($tmp, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($tmp);
        }
    }
}
