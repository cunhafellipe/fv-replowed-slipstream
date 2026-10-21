<?php

namespace App\Support;

/** The same per-part construction rules used by the Flash storage panel. */
final class ConstructionMaterials
{
    /**
     * @return array{complete: bool, cashCost: int, remaining: array<string, int>}|null
     */
    public static function status(array $constructionItem, array $contents): ?array
    {
        $storageType = $constructionItem['storageType'] ?? null;
        $storageClass = is_array($storageType)
            ? ($storageType['itemClass'] ?? null)
            : (is_object($storageType) ? ($storageType->itemClass ?? null) : null);
        $dynamic = (int) config('experiments.fv_dynamic_part_requirements_refactor', 0) > 0;
        $requirements = StorageConfig::constructionRequirements($storageClass, $dynamic);

        // A declared storage class must have a readable part definition. Do
        // not treat its defaultItem as the only material if the asset is lost.
        if ($storageClass !== null && $storageClass !== '' && $requirements === []) {
            return null;
        }

        if ($requirements === []) {
            $defaultPart = $constructionItem['defaultItem'] ?? null;
            if (is_object($defaultPart)) {
                $defaultPart = get_object_vars($defaultPart);
            }
            $name = is_array($defaultPart) ? ($defaultPart['name'] ?? null) : null;
            $needed = (int) ($constructionItem['matsNeeded'] ?? 0)
                * max(1, (int) (is_array($defaultPart) ? ($defaultPart['amount'] ?? 1) : 1));
            if (!is_string($name) || $name === '' || $needed <= 0) {
                return null;
            }
            $requirements = [$name => $needed];
        } elseif ($dynamic
            && (int) config('experiments.fv_smartquest', 0) >= 2
            && (int) config('experiments.fv_smartbuildable', 0) >= 2
            && array_key_exists('smartSystemTuning', $constructionItem)) {
            // StorageBuilding.getTunedPartRequirement applies ceil(need*1.2)
            // when the smart-buildable experiments and item tuning are active.
            foreach ($requirements as $name => $needed) {
                $requirements[$name] = (int) ceil($needed * 1.2);
            }
        }

        $onHand = [];
        foreach ($contents as $content) {
            if (!is_array($content) && !is_object($content)) {
                continue;
            }
            $code = is_object($content) ? ($content->itemCode ?? null) : ($content['itemCode'] ?? null);
            $quantity = is_object($content) ? ($content->numItem ?? 0) : ($content['numItem'] ?? 0);
            if (is_string($code) && $code !== '') {
                $onHand[$code] = ($onHand[$code] ?? 0) + max(0, (int) $quantity);
            }
        }

        $remaining = [];
        $cashCost = 0;
        foreach ($requirements as $name => $needed) {
            $item = getItemByName((string) $name, 'db');
            $code = is_array($item) ? ($item['code'] ?? null) : null;
            if (!is_string($code) || $code === '' || (int) $needed <= 0) {
                return null;
            }
            $shortfall = max(0, (int) $needed - ($onHand[$code] ?? 0));
            $remaining[$name] = $shortfall;
            $partCash = (int) ($item['cash'] ?? 0);
            if ($shortfall > 0 && $partCash <= 0) {
                // A missing price must never turn a paid completion into a
                // free one. Fully supplied construction needs no price.
                return null;
            }
            $cashCost += $shortfall * $partCash;
        }

        return [
            'complete' => array_sum($remaining) === 0,
            'cashCost' => $cashCost,
            'remaining' => $remaining,
        ];
    }
}
