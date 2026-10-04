<?php
namespace App\Services;
final class StoreWorkspaceService
{
    public static function isCurrent(string $link, string $path, array $query): bool
    {
        if (trim((string) parse_url($link, PHP_URL_PATH), '/') !== trim($path, '/')) return false;
        parse_str((string) parse_url($link, PHP_URL_QUERY), $linkQuery);
        if (isset($linkQuery['section']) && ($query['section'] ?? null) !== $linkQuery['section']) return false;
        if (isset($query['store_id']) && (string) $query['store_id'] !== (string) ($linkQuery['store_id'] ?? '')) return false;
        return true;
    }

    public static function links(int $id, array $caps, bool $outstanding = false): array
    {
        $links = ['store-admin/stores/' . $id => 'Overview', 'store/inventory' => 'Inventory & catalog'];
        if (in_array('food_service', $caps, true)) $links['store/offerings?section=food'] = 'Daily food';
        if (in_array('rental', $caps, true) || $outstanding) $links['store/offerings?section=rental'] = in_array('rental', $caps, true) ? 'Rentals' : 'Outstanding returns';
        foreach (['reports'=>'Reports','history'=>'History','staff-records'=>'Employee accounts','settings'=>'Store settings'] as $path=>$label) $links['store/'.$path] = $label;
        $links['store-admin/daily-reports'] = 'Daily report handoff';
        $result = [];
        foreach ($links as $path=>$label) $result[] = ['path'=>$path.(str_contains($path,'?')?'&':'?').'store_id='.$id,'label'=>$label];
        return $result;
    }
}
