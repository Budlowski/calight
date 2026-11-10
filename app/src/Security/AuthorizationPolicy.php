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
        if (str_starts_with($path, '/api/users')) return ['users.manage'];
        if (preg_match('#^/projects/[^/]+/offers$#', $path) === 1) return ['projects.read', 'offers.write'];
        if (preg_match('#^/api/offers/[^/]+/order$#', $path) === 1) return ['offers.read', 'orders.write'];
        if (preg_match('#^/api/change-orders/[^/]+/accept$#', $path) === 1) return ['offers.write', 'orders.write'];
        if (str_starts_with($path, '/settings') || str_starts_with($path, '/api/settings')) return ['settings.manage'];
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
