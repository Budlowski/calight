<?php

declare(strict_types=1);

namespace App\Security;

use App\Http\Request;
use PDO;

/**
 * Schutz gegen Passwort-Raten (Systemaudit F-04, Migration 0052).
 *
 * - Je Benutzerkennung: höchstens USERNAME_LIMIT Fehlversuche im Zeitfenster seit der letzten
 *   erfolgreichen Anmeldung; je Quell-IP höchstens IP_LIMIT Fehlversuche im Zeitfenster.
 * - Sperren enden automatisch, sobald die Fehlversuche aus dem Zeitfenster fallen; abgewiesene
 *   Versuche verlängern die Sperre nicht (keine dauerhafte Blockade fremder Konten).
 * - Der Versuch wird vor der Passwortprüfung in einer IMMEDIATE-Transaktion erfasst, damit auch
 *   parallele Versuche zählen.
 * - Gleiche Meldung für vorhandene und unbekannte Benutzer; gespeichert wird nur ein Hash des
 *   normalisierten Benutzernamens, nie ein Passwort.
 * - Proxy-Header (X-Forwarded-For) nur, wenn REMOTE_ADDR in APP_TRUSTED_PROXIES steht.
 */
final readonly class LoginThrottle
{
    public const USERNAME_LIMIT = 5;
    public const IP_LIMIT = 20;
    public const WINDOW_MINUTES = 15;
    private const RETENTION_DAYS = 30;

    /** @param list<string> $trustedProxies IP-Adressen oder IPv4-CIDR-Bereiche */
    public function __construct(private PDO $pdo, private array $trustedProxies = [])
    {
    }

    /**
     * Prüft die Sperre und erfasst den Versuch. Ergebnis: id des erfassten Versuchs oder null bei Sperre.
     */
    public function begin(string $username, string $ip, ?\DateTimeImmutable $now = null): ?int
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $at = $now->format('Y-m-d\TH:i:s\Z');
        $since = $now->modify('-' . self::WINDOW_MINUTES . ' minutes')->format('Y-m-d\TH:i:s\Z');
        $user = self::usernameHash($username);
        $own = !$this->pdo->inTransaction();
        if ($own) {
            $this->pdo->exec('BEGIN IMMEDIATE');
        }
        try {
            $this->pdo->prepare('DELETE FROM login_attempts WHERE attempted_at < :limit')
                ->execute(['limit' => $now->modify('-' . self::RETENTION_DAYS . ' days')->format('Y-m-d\TH:i:s\Z')]);
            $byUser = $this->pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE username_hash = :user AND outcome IN ('pending', 'failed') AND attempted_at >= :since
                AND attempted_at > COALESCE((SELECT MAX(attempted_at) FROM login_attempts WHERE username_hash = :user AND outcome = 'succeeded'), '')");
            $byUser->execute(['user' => $user, 'since' => $since]);
            $byIp = $this->pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip_address = :ip AND outcome IN ('pending', 'failed') AND attempted_at >= :since");
            $byIp->execute(['ip' => $ip, 'since' => $since]);
            $blocked = (int) $byUser->fetchColumn() >= self::USERNAME_LIMIT || (int) $byIp->fetchColumn() >= self::IP_LIMIT;
            $this->pdo->prepare('INSERT INTO login_attempts (username_hash, ip_address, outcome, attempted_at) VALUES (:user, :ip, :outcome, :at)')
                ->execute(['user' => $user, 'ip' => $ip, 'outcome' => $blocked ? 'blocked' : 'pending', 'at' => $at]);
            $id = (int) $this->pdo->lastInsertId();
            if ($own) {
                $this->pdo->commit();
            }
            return $blocked ? null : $id;
        } catch (\Throwable $exception) {
            if ($own && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function finish(int $attemptId, bool $success): void
    {
        $this->pdo->prepare("UPDATE login_attempts SET outcome = :outcome WHERE id = :id AND outcome = 'pending'")
            ->execute(['outcome' => $success ? 'succeeded' : 'failed', 'id' => $attemptId]);
    }

    public static function usernameHash(string $username): string
    {
        return hash('sha256', mb_strtolower(trim($username)));
    }

    /** Quell-IP; X-Forwarded-For nur von ausdrücklich vertrauenswürdigen Proxys (letzter nicht vertrauenswürdiger Eintrag). */
    public function clientIp(Request $request): string
    {
        $remote = $request->remoteAddress !== '' ? $request->remoteAddress : 'unbekannt';
        if (!$this->trusted($remote)) {
            return $remote;
        }
        $chain = array_values(array_filter(array_map('trim', explode(',', (string) ($request->headers['x-forwarded-for'] ?? ''))), static fn (string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP) !== false));
        for ($i = count($chain) - 1; $i >= 0; $i--) {
            if (!$this->trusted($chain[$i])) {
                return $chain[$i];
            }
        }
        return $chain[0] ?? $remote;
    }

    private function trusted(string $ip): bool
    {
        foreach ($this->trustedProxies as $entry) {
            $entry = trim($entry);
            if ($entry === $ip) {
                return true;
            }
            if (str_contains($entry, '/') && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                [$network, $bits] = explode('/', $entry, 2);
                $bits = (int) $bits;
                if (filter_var($network, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false || $bits < 0 || $bits > 32) {
                    continue;
                }
                $mask = $bits === 0 ? 0 : (-1 << (32 - $bits)) & 0xFFFFFFFF;
                if ((ip2long($ip) & $mask) === (ip2long($network) & $mask)) {
                    return true;
                }
            }
        }
        return false;
    }
}
