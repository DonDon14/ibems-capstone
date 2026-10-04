<?php
// Shared by both supervisor layouts; never carry a store from another session.
$scopeStores = (new \App\Models\StoreModel())->getAccessibleStores((int) session()->get('user_id'), $role);
$scopeId = (int) (service('request')->getGet('store_id') ?? ($storeId ?? 0));
if ($role !== 'STORE_SUPERVISOR' && $scopeId <= 0) $scopeId = (int) ($scopeStores[0]['id'] ?? 0);
$storeWorkspaceLinks = [];
if ($role === 'STORE_SUPERVISOR') {
    $navigation = array_values(array_filter($navigation, static fn ($link) => str_starts_with($link['path'], 'store-admin/')));
    $portalTitle = 'Store Supervisor';
    $portalContext = ['stores' => [], 'default_store_id' => 0];
}
$scopeStore = null;
foreach ($scopeStores as $candidate) if ((int) $candidate['id'] === $scopeId) $scopeStore = $candidate;
if ($scopeStore) {
    $portalTitle = (string) $scopeStore['store_name'];
    $portalContext = ['stores' => $scopeStores, 'default_store_id' => $scopeId];
    if ($role === 'STORE_SUPERVISOR') {
        $caps = (new \App\Models\StoreCapabilityModel())->getForStore($scopeId);
        $db = \Config\Database::connect();
        $outstanding = $db->tableExists('rental_bookings') && $db->table('rental_bookings')->where('store_id', $scopeId)->groupStart()->whereIn('status', ['reserved', 'issued'])->orWhere('collected_cents > refunded_cents', null, false)->groupEnd()->countAllResults() > 0;
        $storeWorkspaceLinks = \App\Services\StoreWorkspaceService::links($scopeId, $caps, $outstanding);
        $portalNavigationKey = $role . '-store-' . $scopeId;
    }
    foreach ($navigation as &$scopeLink) {
        if (str_starts_with($scopeLink['path'], 'store/') && $scopeLink['path'] !== 'store/pos') {
            $scopeLink['activePath'] = $scopeLink['path'];
            $scopeLink['path'] .= '?store_id=' . $scopeId;
        }
    }
    unset($scopeLink);
}
