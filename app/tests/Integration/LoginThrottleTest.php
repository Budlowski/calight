<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Http\Request;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Database\Migrator;
use App\Security\LoginThrottle;
use App\Tests\TestCase;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/** Schutz gegen Passwort-Raten (Systemaudit F-04). Nur temporäre Datenbanken. */
final class LoginThrottleTest extends TestCase
{
    public function testUsernameLimitWindowSuccessAndNoPermanentLock(): void
    {
        $this->withDatabase(function (PDO $pdo): void {
            $throttle = new LoginThrottle($pdo);
            $now = new DateTimeImmutable('2026-11-22T10:00:00', new DateTimeZone('UTC'));
            for ($i = 0; $i < LoginThrottle::USERNAME_LIMIT; $i++) {
                $attempt = $throttle->begin('Max', '10.0.0.1', $now->modify("+{$i} seconds"));
                $this->assertTrue($attempt !== null, 'Versuch ' . ($i + 1) . ' erlaubt.');
                $throttle->finish((int) $attempt, false);
            }
            $this->assertSame(null, $throttle->begin(' max ', '10.0.0.2', $now->modify('+1 minute')), 'Gesperrt je Benutzerkennung (normalisiert), auch von anderer IP.');
            $this->assertTrue($throttle->begin('eva', '10.0.0.1', $now->modify('+1 minute')) !== null, 'Andere Benutzer derselben IP bleiben möglich.');
            // Abgewiesene Versuche verlängern die Sperre nicht; nach dem Zeitfenster ist sie aufgehoben.
            for ($i = 0; $i < 10; $i++) {
                $this->assertSame(null, $throttle->begin('max', '10.0.0.3', $now->modify('+' . (2 + $i) . ' minutes')));
            }
            $later = $now->modify('+' . (LoginThrottle::WINDOW_MINUTES + 1) . ' minutes');
            $attempt = $throttle->begin('max', '10.0.0.1', $later);
            $this->assertTrue($attempt !== null, 'Sperre endet automatisch.');
            $throttle->finish((int) $attempt, true);
            // Nach erfolgreicher Anmeldung zählen frühere Fehlversuche nicht mehr.
            $failed = $throttle->begin('max', '10.0.0.1', $later->modify('+1 second'));
            $throttle->finish((int) $failed, false);
            $this->assertTrue($throttle->begin('max', '10.0.0.1', $later->modify('+2 seconds')) !== null);
            // Gespeichert wird nur ein Hash des Benutzernamens, nie ein Passwort.
            $columns = array_column($pdo->query('PRAGMA table_info(login_attempts)')->fetchAll(), 'name');
            $this->assertSame(['id', 'username_hash', 'ip_address', 'outcome', 'attempted_at'], $columns);
            $this->assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM login_attempts WHERE username_hash LIKE '%max%'")->fetchColumn());
            $this->assertTrue((int) $pdo->query("SELECT COUNT(*) FROM login_attempts WHERE outcome = 'blocked'")->fetchColumn() >= 11, 'Abgewiesene Versuche werden protokolliert.');
        });
    }

    public function testIpLimitCountsPendingAttemptsForParallelRequests(): void
    {
        $this->withDatabase(function (PDO $pdo): void {
            $throttle = new LoginThrottle($pdo);
            $now = new DateTimeImmutable('2026-11-22T10:00:00', new DateTimeZone('UTC'));
            // Noch nicht abgeschlossene (parallele) Versuche zählen bereits mit.
            for ($i = 0; $i < LoginThrottle::IP_LIMIT; $i++) {
                $this->assertTrue($throttle->begin('user' . $i, '192.0.2.10', $now) !== null);
            }
            $this->assertSame(null, $throttle->begin('neu', '192.0.2.10', $now), 'IP-Grenze erreicht.');
            $this->assertTrue($throttle->begin('neu', '192.0.2.11', $now) !== null, 'Andere IP unberührt.');
        });
    }

    public function testProxyHeadersOnlyFromTrustedProxies(): void
    {
        $this->withDatabase(function (PDO $pdo): void {
            $request = static fn (string $remote, string $forwarded): Request => new Request('POST', '/login', [], [], ['x-forwarded-for' => $forwarded], '', [], $remote);
            $untrusted = new LoginThrottle($pdo);
            $this->assertSame('203.0.113.5', $untrusted->clientIp($request('203.0.113.5', '198.51.100.1')), 'Ohne vertrauenswürdigen Proxy zählt REMOTE_ADDR.');
            $trusted = new LoginThrottle($pdo, ['172.18.0.0/16', '10.1.1.1']);
            $this->assertSame('198.51.100.1', $trusted->clientIp($request('172.18.0.2', '198.51.100.1')), 'Vertrauenswürdiger Proxy (CIDR).');
            $this->assertSame('198.51.100.1', $trusted->clientIp($request('10.1.1.1', 'gefälscht, 198.51.100.1, 172.18.0.3')), 'Letzter nicht vertrauenswürdiger Eintrag; ungültige Werte ignoriert.');
            $this->assertSame('172.19.0.2', $trusted->clientIp($request('172.19.0.2', '198.51.100.1')), 'Außerhalb des Bereichs kein Vertrauen.');
        });
    }

    private function withDatabase(callable $test): void
    {
        $tmp = sys_get_temp_dir() . '/calight-login-' . bin2hex(random_bytes(6));
        mkdir($tmp, 0770, true);
        try {
            $pdo = ConnectionFactory::create($tmp . '/db.sqlite');
            (new Migrator($pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
            $test($pdo);
        } finally {
            $pdo = null;
            foreach (glob($tmp . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($tmp);
        }
    }
}
