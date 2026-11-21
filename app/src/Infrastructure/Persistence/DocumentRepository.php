<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use PDO;

/**
 * Lesende Abfragen auf bereits vorhandene Fachdokumente (Phase 3.4.2). Es werden keine
 * Dateien kopiert oder verschoben; jede Quelle liefert nur Metadaten und den Kontext
 * (Kunde, Projekt, Auftrag), aus dem die Übersicht Links auf die Download-Routen der
 * Fachmodule bildet. Eine weitere Quelle (z. B. Lieferscheine) ist eine weitere Methode.
 */
final readonly class DocumentRepository
{
    private const CUSTOMER_NAME = "COALESCE(NULLIF(c.company_name, ''), trim(c.first_name || ' ' || c.last_name))";

    public function __construct(private PDO $pdo)
    {
    }

    /** Abgegebene Angebots- und Nachtragsfassungen mit archiviertem PDF. @return list<array<string,mixed>> */
    public function offerPdfs(): array
    {
        return $this->pdo->query('SELECT o.id, o.offer_number, o.revision_number, o.document_type, o.list_title, o.submitted_at, o.customer_id, o.project_id, o.order_id, '
            . self::CUSTOMER_NAME . ' AS customer_name
            FROM offers o LEFT JOIN customers c ON c.id = o.customer_id
            WHERE o.submitted_pdf_sha256 IS NOT NULL')->fetchAll();
    }

    /** Eigene Bestell-PDFs und Belege der Lieferanten. @return list<array<string,mixed>> */
    public function purchaseDocuments(): array
    {
        return $this->pdo->query('SELECT d.id, d.purchase_order_id, d.kind, d.revision, d.original_name, d.mime_type, d.storage_path, d.created_at,
                p.order_number, p.order_id, s.name AS supplier_name, o.customer_id, o.project_id
            FROM purchase_order_documents d JOIN purchase_orders p ON p.id = d.purchase_order_id JOIN suppliers s ON s.id = p.supplier_id
            LEFT JOIN orders o ON o.id = p.order_id')->fetchAll();
    }

    /** Eingereichte Rapporte (PDF) einschließlich Bestätigungsnachweis. @return list<array<string,mixed>> */
    public function workReports(): array
    {
        return $this->pdo->query("SELECT w.id, w.report_number, w.work_date, w.submitted_at, w.customer_id, w.order_id, o.project_id,
                w.confirmation_evidence_path, w.confirmation_evidence_name, w.confirmation_evidence_mime, w.confirmed_at, "
            . self::CUSTOMER_NAME . " AS customer_name
            FROM work_reports w JOIN customers c ON c.id = w.customer_id LEFT JOIN orders o ON o.id = w.order_id
            WHERE w.status <> 'draft'")->fetchAll();
    }

    /** Abgeschlossene Aufmaße (PDF). @return list<array<string,mixed>> */
    public function measurements(): array
    {
        return $this->pdo->query("SELECT m.id, m.measurement_number, m.title, m.completed_at, m.order_id, o.order_number, o.customer_id, o.project_id
            FROM measurements m JOIN orders o ON o.id = m.order_id WHERE m.status = 'completed'")->fetchAll();
    }

    /** Tagesberichte (PDF wird vom Fachmodul erzeugt). @return list<array<string,mixed>> */
    public function dailyReports(): array
    {
        return $this->pdo->query('SELECT r.id, r.report_date, r.title, r.updated_at, r.order_id, o.order_number, r.customer_id, r.project_id
            FROM daily_reports r LEFT JOIN orders o ON o.id = r.order_id')->fetchAll();
    }

    /** Anhänge zu Tagesberichten. @return list<array<string,mixed>> */
    public function dailyReportAttachments(): array
    {
        return $this->pdo->query('SELECT a.id, a.report_id, a.original_name, a.mime_type, a.storage_path, a.created_at, r.report_date, r.order_id, o.order_number, r.customer_id, r.project_id
            FROM daily_report_attachments a JOIN daily_reports r ON r.id = a.report_id LEFT JOIN orders o ON o.id = r.order_id')->fetchAll();
    }

    /** Auswahlliste Kunden (aktive und archivierte, damit alte Dokumente auffindbar bleiben). @return list<array{id:string,label:string}> */
    public function customers(): array
    {
        return $this->pdo->query("SELECT id, trim(COALESCE(customer_number || ' · ', '') || COALESCE(NULLIF(company_name, ''), trim(first_name || ' ' || last_name))) AS label
            FROM customers ORDER BY label COLLATE NOCASE")->fetchAll();
    }

    /** @return list<array{id:string,label:string}> */
    public function projects(): array
    {
        return $this->pdo->query("SELECT id, trim(COALESCE(project_number || ' · ', '') || name) AS label FROM projects ORDER BY created_at DESC LIMIT 500")->fetchAll();
    }

    /** @return list<array{id:string,label:string}> */
    public function orders(): array
    {
        return $this->pdo->query("SELECT id, order_number || ' · ' || title AS label FROM orders ORDER BY created_at DESC LIMIT 500")->fetchAll();
    }
}
