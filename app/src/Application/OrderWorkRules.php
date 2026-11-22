<?php

declare(strict_types=1);

namespace App\Application;

use PDO;

/**
 * Zentrale fachliche Regeln „Was darf an einem Auftrag in welchem Status geschehen?“
 * (Systemaudit F-01/F-02). Alle Module verwenden ausschließlich diese Klasse.
 *
 * 1. Neue Leistungserfassung (neue manuelle Zeitbuchung auf den Auftrag, neuer Rapport bzw.
 *    Zuordnung, neuer Tagesbericht bzw. Zuordnung, neues Aufmaß, neue Materialanforderung):
 *    nur „Offen“ und „In Bearbeitung“ (acceptsNewWork).
 * 2. Bearbeitung bestehender Entwürfe und Korrektur historischer Nachweise (Einreichen eines
 *    bereits zugeordneten Rapportentwurfs, Korrekturrapport, Aufmaßkorrektur): zusätzlich
 *    „Abgeschlossen“, nie „Abgerechnet“ (acceptsChanges). Bestehende Daten werden nie verändert,
 *    weil sich der Auftragsstatus ändert.
 * 3. Kaufmännische Nachbearbeitung (Lieferantenrechnungen, Gutschriften, Lieferungen, Zahlungen,
 *    Kostenkorrekturen, Bestellungen): unabhängig vom Auftragsstatus zulässig.
 *
 * Statuswechsel: vorwärts Offen → In Bearbeitung → Abgeschlossen → Abgerechnet (Büro mit
 * orders.write); vor „Abgeschlossen“/„Abgerechnet“ werden offene Vorgänge angezeigt und müssen
 * ausdrücklich bestätigt werden. Rücknahme nur einen Schritt (Abgerechnet → Abgeschlossen,
 * Abgeschlossen → In Bearbeitung), nur Administratoren, mit Begründung. „In Bearbeitung → Offen“
 * ist ausgeschlossen, weil Monteure bereits auf freigegebenen Aufträgen arbeiten.
 */
final class OrderWorkRules
{
    public const NEW_WORK_STATUSES = ['open', 'in_progress'];
    public const CHANGE_STATUSES = ['open', 'in_progress', 'completed'];
    public const FORWARD = ['open' => 'in_progress', 'in_progress' => 'completed', 'completed' => 'invoiced'];
    public const REVERT = ['invoiced' => 'completed', 'completed' => 'in_progress'];
    /** Vor diesen Zielstatus werden offene Vorgänge geprüft. */
    public const CHECK_OPEN_ITEMS = ['completed', 'invoiced'];

    public static function acceptsNewWork(string $status): bool
    {
        return in_array($status, self::NEW_WORK_STATUSES, true);
    }

    public static function acceptsChanges(string $status): bool
    {
        return in_array($status, self::CHANGE_STATUSES, true);
    }

    /** @param array<string,mixed> $order */
    public static function newWorkMessage(array $order): string
    {
        return sprintf('Der Auftrag %s ist %s und nimmt keine neuen Leistungen auf.', (string) ($order['order_number'] ?? $order['orderNumber'] ?? ''),
            ($order['status'] ?? '') === 'invoiced' ? 'abgerechnet' : 'abgeschlossen');
    }

    /** @param array<string,mixed> $order */
    public static function changesMessage(array $order): string
    {
        return sprintf('Der Auftrag %s ist abgerechnet. Leistungsnachweise können nicht mehr eingereicht oder korrigiert werden.', (string) ($order['order_number'] ?? $order['orderNumber'] ?? ''));
    }

    /**
     * Offene Vorgänge eines Auftrags (nur Anzeige und Bestätigung; es wird nichts abgeschlossen
     * oder storniert).
     *
     * @return list<array{key:string,label:string,count:int}>
     */
    public static function openItems(PDO $pdo, string $orderId): array
    {
        $queries = [
            'work_reports_draft' => ["SELECT COUNT(*) FROM work_reports WHERE order_id = :id AND status = 'draft'", 'Rapportentwürfe'],
            'work_reports_submitted' => ["SELECT COUNT(*) FROM work_reports WHERE order_id = :id AND status = 'submitted' AND superseded_by_id IS NULL", 'Eingereichte, noch nicht abgeschlossene Rapporte'],
            'daily_reports' => ["SELECT COUNT(*) FROM daily_reports WHERE order_id = :id AND status IN ('draft', 'submitted', 'reopened')", 'Tagesberichte (Entwurf, eingereicht oder in Korrektur)'],
            'measurements' => ["SELECT COUNT(*) FROM measurements WHERE order_id = :id AND status = 'draft'", 'Aufmaßentwürfe'],
            'material_requests' => ["SELECT COUNT(*) FROM material_requests WHERE order_id = :id AND status = 'open'", 'Offene Materialanforderungen'],
            'purchase_orders' => ["SELECT COUNT(*) FROM purchase_orders WHERE order_id = :id AND status = 'draft'", 'Bestellentwürfe'],
        ];
        $items = [];
        foreach ($queries as $key => [$sql, $label]) {
            $statement = $pdo->prepare($sql);
            $statement->execute(['id' => $orderId]);
            $count = (int) $statement->fetchColumn();
            if ($count > 0) {
                $items[] = ['key' => $key, 'label' => $label, 'count' => $count];
            }
        }
        return $items;
    }
}
