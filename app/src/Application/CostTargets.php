<?php

declare(strict_types=1);

namespace App\Application;

use App\Infrastructure\Persistence\CostCenterRepository;
use PDO;

/**
 * Gemeinsames Kostenziel „Auftrag / Kostenstelle“: eine Auswahlliste für die vorhandene
 * Suchkomponente und eine einheitliche Prüfung. Gespeichert wird immer genau ein Ziel mit Typ
 * und ID (order_id ODER cost_center_id) – nie anhand des Nummernpräfixes.
 *
 * Die Aufträge liefert das jeweilige Fachmodul mit seinen bestehenden Freigaberegeln; hier
 * kommen nur aktive Kostenstellen hinzu (inaktive nur, wenn sie bereits zugeordnet sind).
 * Kostenstellen enthalten keine Beträge oder Kostensätze.
 */
final readonly class CostTargets
{
    private CostCenterRepository $costCenters;

    public function __construct(PDO $pdo)
    {
        $this->costCenters = new CostCenterRepository($pdo);
    }

    /**
     * @param list<array{id:string,orderNumber:string,title:string}> $orders bereits berechtigungsgeprüft
     * @return list<array{type:string,id:string,number:string,label:string,active:bool}>
     */
    public function options(array $orders): array
    {
        $targets = array_map(static fn (array $order): array => ['type' => 'order', 'id' => (string) $order['id'], 'number' => (string) $order['orderNumber'],
            'label' => (string) $order['title'], 'active' => true], $orders);
        foreach ($this->costCenters->all() as $center) {
            $targets[] = ['type' => 'cost_center', 'id' => (string) $center['id'], 'number' => (string) $center['number'], 'label' => (string) $center['name'],
                'active' => (bool) $center['active']];
        }
        return $targets;
    }

    /**
     * Prüft eine Kostenstellenauswahl: vorhanden; neu nur aktive (eine bestehende Zuordnung zu
     * einer inzwischen deaktivierten Kostenstelle bleibt erlaubt). Auftrag und Kostenstelle
     * gleichzeitig ist unzulässig.
     *
     * @param callable(string):\Throwable $fail erzeugt die Fehlermeldung des Fachmoduls
     */
    public function costCenter(mixed $costCenterId, ?string $orderId, ?string $current, callable $fail): ?string
    {
        $id = trim((string) $costCenterId);
        if ($id === '') {
            return null;
        }
        if ($orderId !== null) {
            throw $fail('Bitte entweder einen Auftrag oder eine Kostenstelle wählen, nicht beides.');
        }
        $center = $this->costCenters->find($id) ?? throw $fail('Die Kostenstelle wurde nicht gefunden.');
        if (!(bool) $center['active'] && $id !== $current) {
            throw $fail('Die Kostenstelle ' . $center['number'] . ' ist deaktiviert.');
        }
        return $id;
    }
}
