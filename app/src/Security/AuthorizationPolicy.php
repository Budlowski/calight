<?php

declare(strict_types=1);

namespace App\Security;

use App\Http\Request;
use App\Http\Response;

final class AuthorizationPolicy
{
    /** @return list<string> */
    public static function required(Request $request): array
    {
        $path = $request->path;
        $write = !in_array($request->method, ['GET', 'HEAD'], true);
        if ($path === '/' || $path === '/login' || $path === '/logout') return [];
        if ($path === '/dashboard') return ['dashboard.read'];
        // Mobiles Frontend: Manifest und Service Worker enthalten keine Daten und werden vom Browser
        // ohne Anmeldedaten geladen. Alle übrigen mobilen Seiten verlangen dieselben Rechte wie die
        // Büroansicht; die Datensatzrechte prüfen die Fachdienste.
        if ($path === '/mobile/manifest.webmanifest' || $path === '/mobile/sw.js') return [];
        if ($path === '/interface') return ['interface.switch'];
        if ($path === '/mobile/orders' || str_starts_with($path, '/mobile/orders/')) return ['orders.read'];
        if ($path === '/mobile/times') return ['timesheet.own'];
        if ($path === '/mobile' || str_starts_with($path, '/mobile/')) return ['dashboard.read'];
        if (str_starts_with($path, '/api/users')) return ['users.manage'];
        if ($path === '/settings/employees' || str_starts_with($path, '/api/employees')) return ['employees.manage', 'costs.read'];
        // Betriebseinstellungen (Einstellungen → Betrieb): nur Administratoren.
        if ($path === '/settings/operations' || str_starts_with($path, '/api/operational-settings')) return ['operations.manage'];
        // Modulverwaltung (Einstellungen → Module): global für den Betrieb, nur Administratoren.
        if ($path === '/settings/modules' || $path === '/api/modules' || str_starts_with($path, '/api/modules/')) return ['operations.manage'];
        if (str_starts_with($path, '/daily-reports') || str_starts_with($path, '/api/daily-reports')) return [$write ? 'reports.write' : 'reports.read'];
        // Rapporte: Leistungsnachweis erfassen (workreports.write); Preise, Abrechnung und Abschluss nur mit workreports.process.
        if (preg_match('#^/api/work-reports/([^/]+/(billing|process|reopen|assign-order|confirmation-document|confirmation-request)|catalog)$#', $path) === 1) return ['workreports.read', 'workreports.process'];
        if (str_starts_with($path, '/work-reports') || str_starts_with($path, '/api/work-reports')) return [$write ? 'workreports.write' : 'workreports.read'];
        // Aufmaße: Entwürfe erfassen (measurements.write), verbindlich abschließen nur mit measurements.complete.
        if (preg_match('#^/api/measurements/[^/]+/complete$#', $path) === 1) return ['measurements.read', 'measurements.complete'];
        if (str_starts_with($path, '/measurements') || str_starts_with($path, '/api/measurements')) return [$write ? 'measurements.write' : 'measurements.read'];
        // Materialanforderungen: Erfassen wie Berichte, Erledigen/Wiedereröffnen nur durch Büro/Admin.
        if (preg_match('#^/api/material-requests/[^/]+/status$#', $path) === 1) return ['reports.read', 'materials.manage'];
        if (str_starts_with($path, '/material-requests') || str_starts_with($path, '/api/material-requests')) return [$write ? 'reports.write' : 'reports.read'];
        // Zeiterfassung: Grundrecht timesheet.own (Büro hat zusätzlich timesheet.manage); Zuordnung zum Mitarbeiter prüft der Service.
        if ($path === '/time-tracking' || str_starts_with($path, '/api/time-tracking')) return ['timesheet.own'];
        // Dokumente und Anhänge: Grundrecht documents.read; Schreib- und Objektrechte prüft DocumentService.
        if ($path === '/documents' || str_starts_with($path, '/api/documents') || str_starts_with($path, '/api/attachments')) return ['documents.read'];
        // Bestellungen: Verwaltung durch Büro/Admin; Belege, Zahlungen, Ist-Kosten und Kostenverteilung zusätzlich nur mit costs.read.
        if (preg_match('#^/api/purchase-orders/[^/]+/(invoices|payments|actual-cost|allocations)$#', $path) === 1) return ['purchases.write', 'costs.read'];
        if (str_starts_with($path, '/purchase-orders') || str_starts_with($path, '/api/purchase-orders')) return [$write ? 'purchases.write' : 'purchases.read'];
        if (preg_match('#^/api/offers/[^/]+/order$#', $path) === 1) return ['offers.read', 'orders.write'];
        if (preg_match('#^/api/change-orders/[^/]+/accept$#', $path) === 1) return ['offers.write', 'orders.write'];
        if (str_starts_with($path, '/settings') || str_starts_with($path, '/api/settings')) return ['settings.manage'];
        // Kostenstellen: Übersicht mit master_data.read (Beträge nur mit costs.read, Prüfung im Service), Verwaltung nur Administratoren.
        if (str_starts_with($path, '/api/cost-centers')) return [$write ? 'cost_centers.manage' : 'master_data.read'];
        if ($path === '/master-data/cost-centers') return ['master_data.read'];
        if (str_starts_with($path, '/master-data') || str_starts_with($path, '/api/master-data')) return [$write ? 'master_data.write' : 'master_data.read', 'costs.read'];
        if (str_starts_with($path, '/customers') || str_starts_with($path, '/api/customers')) return [$write ? 'customers.write' : 'customers.read'];
        if (str_starts_with($path, '/projects') || str_starts_with($path, '/api/projects') || $path === '/api/customer-projects') return [$write ? 'projects.write' : 'projects.read'];
        if (str_starts_with($path, '/api/offer-calculation/save-')) return ['offers.write','master_data.write','costs.read'];
        if (str_starts_with($path, '/api/offer-calculation')) return [$write ? 'offers.write' : 'offers.read', 'costs.read'];
        if (preg_match('#^/api/offers/[^/]+/calculation-pdf$#', $path) === 1) return ['offers.read', 'costs.read'];
        if (preg_match('#^/api/offers/[^/]+/(pdf|submitted-pdf)$#', $path) === 1) return ['offers.read'];
        if (str_starts_with($path, '/offers') || str_starts_with($path, '/api/offers') || str_starts_with($path, '/api/change-orders')) return [$write ? 'offers.write' : 'offers.read'];
        if (str_starts_with($path, '/orders') || str_starts_with($path, '/api/orders')) return [$write ? 'orders.write' : 'orders.read'];
        if (str_starts_with($path, '/api/document-locks/offers/')) return ['offers.write'];
        return [];
    }

    public static function deniedResponse(Request $request):Response
    {
        return str_starts_with($request->path,'/api/')
            ?Response::json(['error'=>'Keine Berechtigung'],403)
            :new Response('<h1>Keine Berechtigung</h1><p>Sie dürfen diese Funktion nicht verwenden.</p>',403,['Content-Type'=>'text/html; charset=utf-8']);
    }
}
