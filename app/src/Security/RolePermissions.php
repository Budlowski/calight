<?php

declare(strict_types=1);

namespace App\Security;

final class RolePermissions
{
    /** @var array<string,list<string>> */
    private const PERMISSIONS = [
        'admin' => ['*'],
        // Nur fuer bereits vor der Migration bestehende PHP-Sitzungen. In der
        // Datenbank wird die alte Rolle beim Migrieren zu admin uebernommen.
        'employee' => ['*'],
        'office' => [
            'dashboard.read', 'customers.read', 'customers.write', 'projects.read', 'projects.write',
            'offers.read', 'offers.write', 'orders.read', 'orders.write', 'master_data.read',
            'master_data.write', 'costs.read', 'settings.manage',
        ],
        'technician' => ['dashboard.read', 'orders.read', 'reports.write'],
    ];

    public static function allows(string $role, string $permission): bool
    {
        $permissions = self::PERMISSIONS[$role] ?? [];
        return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
    }

    /** @return list<string> */
    public static function forRole(string $role): array
    {
        return self::PERMISSIONS[$role] ?? [];
    }
}
