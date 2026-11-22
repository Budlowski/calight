<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use PDO;

/**
 * Zentrale Regel „Auftrag für Monteure sichtbar“ – einzige Stelle, an der sie formuliert ist.
 *
 * Monteure (Rolle technician) sehen einen Auftrag nur, wenn er freigegeben ist (nicht „Offen“,
 * bisherige Regel) UND entweder die Betriebseinstellung `orders.technician_visibility` alle
 * freigegebenen Aufträge zulässt oder ihr Mitarbeiter (employees.user_id) dem Auftrag zugeordnet
 * ist (order_employees). Büro und Administratoren sind nicht eingeschränkt. Die Regel wird als
 * SQL-Ausdruck in Listen und als Einzelprüfung in den Fachdiensten verwendet, damit Listen,
 * Detailseiten, Suchen und API-Aufrufe dieselbe Entscheidung treffen.
 */
final class OrderVisibility
{
    public const SETTING = 'orders.technician_visibility';
    public const ASSIGNED = 'assigned';
    public const RELEASED = 'released';

    /** Unterliegt der Benutzer der Auftragssichtbarkeit (Monteur)? @param array<string,mixed> $actor */
    public static function restricted(array $actor): bool
    {
        return ($actor['role'] ?? '') === 'technician';
    }

    /**
     * SQL-Bedingung für einen Auftrag mit Alias $alias und dem Benutzer im gebundenen Parameter
     * :$parameter (Wert: Benutzer-ID).
     */
    public static function condition(string $alias, string $parameter): string
    {
        return sprintf(
            "(%1\$s.status <> 'open' AND (COALESCE((SELECT vs.setting_value FROM operational_settings vs WHERE vs.setting_key = '%2\$s'), '%3\$s') = '%4\$s'"
            . " OR EXISTS (SELECT 1 FROM order_employees voe JOIN employees ve ON ve.id = voe.employee_id WHERE voe.order_id = %1\$s.id AND ve.user_id = :%5\$s)))",
            $alias, self::SETTING, self::ASSIGNED, self::RELEASED, $parameter,
        );
    }

    /** Einzelprüfung für einen Auftrag. @param array<string,mixed> $actor */
    public static function visible(PDO $pdo, string $orderId, array $actor): bool
    {
        if (!self::restricted($actor)) {
            return true;
        }
        $statement = $pdo->prepare('SELECT 1 FROM orders o WHERE o.id = :visibility_order AND ' . self::condition('o', 'visibility_user'));
        $statement->execute(['visibility_order' => $orderId, 'visibility_user' => (string) ($actor['id'] ?? '')]);
        return $statement->fetchColumn() !== false;
    }

    /**
     * IDs aller für den Benutzer sichtbaren Aufträge aus einer Kandidatenliste (für Auswahllisten,
     * die bereits geladen sind). Nicht eingeschränkte Benutzer erhalten die Liste unverändert.
     *
     * @param array<string,mixed> $actor
     * @param list<string> $orderIds
     * @return array<string,true>
     */
    public static function visibleIds(PDO $pdo, array $actor, array $orderIds): array
    {
        if (!self::restricted($actor)) {
            return array_fill_keys($orderIds, true);
        }
        $statement = $pdo->prepare('SELECT o.id FROM orders o WHERE ' . self::condition('o', 'visibility_user'));
        $statement->execute(['visibility_user' => (string) ($actor['id'] ?? '')]);
        $visible = array_fill_keys(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN)), true);
        return array_intersect_key($visible, array_fill_keys($orderIds, true));
    }
}
