<?php

$roleLabels = [
    'admin' => 'Super Admin',
    'admin_cabang' => 'Admin Cabang',
    'kepala_cabang' => 'Kepala Cabang',
    'accounting' => 'Accounting',
    'gudang' => 'Gudang',
    'auditor' => 'Auditor',
    'kasir' => 'Kasir',
];

$rolePermissions = [
    'admin' => [
        'access_admin', 'manage_users', 'manage_cabang', 'manage_produk', 'manage_stok',
        'view_dashboard', 'view_reports', 'export_reports', 'access_pos',
    ],
    'admin_cabang' => [
        'access_admin', 'manage_users', 'manage_produk', 'manage_stok',
        'view_dashboard', 'view_reports', 'export_reports', 'access_pos',
    ],
    'kepala_cabang' => [
        'access_admin', 'manage_stok', 'view_dashboard', 'view_reports', 'export_reports', 'access_pos',
    ],
    'accounting' => [
        'access_admin', 'view_dashboard', 'view_reports', 'export_reports',
    ],
    'gudang' => [
        'access_admin', 'manage_stok', 'view_dashboard',
    ],
    'auditor' => [
        'access_admin', 'view_dashboard', 'view_reports',
    ],
    'kasir' => [
        'access_pos',
    ],
];

$roleAssignments = [
    'admin' => array_keys($roleLabels),
    'admin_cabang' => ['kasir', 'gudang'],
];

function hasPermission(string $permission): bool
{
    global $rolePermissions;
    $role = $_SESSION['role'] ?? '';
    return in_array($permission, $rolePermissions[$role] ?? [], true);
}

function canAccessAdmin(): bool
{
    return hasPermission('access_admin');
}

function roleLabel(?string $role): string
{
    global $roleLabels;
    return $roleLabels[$role ?? ''] ?? ucfirst((string)$role);
}

function canAssignRole(string $role): bool
{
    global $roleAssignments;
    $currentRole = $_SESSION['role'] ?? '';
    return in_array($role, $roleAssignments[$currentRole] ?? [], true);
}

function requirePermission(string $permission): void
{
    if (!hasPermission($permission)) {
        http_response_code(403);
        exit('Akses ditolak. Anda tidak memiliki hak akses untuk halaman ini.');
    }
}
