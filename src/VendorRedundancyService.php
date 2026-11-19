<?php

namespace CostSavings;

use PDO;

/**
 * Structured duplicate / overlap analysis for the new-project wizard.
 */
class VendorRedundancyService
{
    /**
     * @param array<int, array<string, mixed>> $items Visible vendor rows
     * @return array{
     *   success:bool,
     *   duplicates:array<int, array<string, mixed>>,
     *   overlaps:array<int, array<string, mixed>>,
     *   duplicate_savings:float,
     *   overlap_savings:float,
     *   error?:string
     * }
     */
    public static function analyze(PDO $pdo, int $userId, array $items): array
    {
        $duplicates = self::findDuplicates($items);
        $duplicateSavings = 0.0;
        foreach ($duplicates as $row) {
            $duplicateSavings += (float) ($row['extra_annual_cost'] ?? 0);
        }

        $overlapResult = self::findOverlapsViaAi($pdo, $userId, $items);
        if (!$overlapResult['success']) {
            return [
                'success' => true,
                'duplicates' => $duplicates,
                'overlaps' => [],
                'duplicate_savings' => round($duplicateSavings, 2),
                'overlap_savings' => 0.0,
                'overlap_warning' => (string) ($overlapResult['error'] ?? 'Overlap analysis unavailable.'),
            ];
        }

        $overlaps = $overlapResult['overlaps'] ?? [];
        $overlapSavings = 0.0;
        $claimed = [];
        foreach ($overlaps as &$pair) {
            $a = (string) ($pair['vendor_a'] ?? '');
            $b = (string) ($pair['vendor_b'] ?? '');
            $keyA = VendorService::normalizeVendorName($a);
            $keyB = VendorService::normalizeVendorName($b);
            $savings = max(0.0, (float) ($pair['suggested_annual_savings'] ?? 0));
            if ($keyA !== '' && isset($claimed[$keyA])) {
                $savings = 0.0;
            }
            if ($keyB !== '' && isset($claimed[$keyB])) {
                $savings = 0.0;
            }
            if ($savings > 0) {
                if ($keyA !== '') {
                    $claimed[$keyA] = true;
                }
                if ($keyB !== '') {
                    $claimed[$keyB] = true;
                }
            }
            $pair['suggested_annual_savings'] = round($savings, 2);
            $overlapSavings += $savings;
        }
        unset($pair);

        return [
            'success' => true,
            'duplicates' => $duplicates,
            'overlaps' => $overlaps,
            'duplicate_savings' => round($duplicateSavings, 2),
            'overlap_savings' => round($overlapSavings, 2),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private static function findDuplicates(array $items): array
    {
        $groups = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $name = trim((string) ($item['vendor_name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $norm = VendorService::normalizeVendorName($name);
            if ($norm === '') {
                continue;
            }
            if (!isset($groups[$norm])) {
                $groups[$norm] = [];
            }
            $groups[$norm][] = [
                'id' => (int) ($item['id'] ?? 0),
                'vendor_name' => $name,
                'annual_cost' => (float) ($item['annual_cost'] ?? 0),
                'purpose' => VendorPurposeService::stripAiPurposeUiPrefix(
                    (string) ($item['purpose_of_subscription'] ?? $item['notes'] ?? '')
                ),
            ];
        }

        $out = [];
        foreach ($groups as $norm => $rows) {
            if (count($rows) < 2) {
                continue;
            }
            usort($rows, static function ($a, $b) {
                return ($b['annual_cost'] <=> $a['annual_cost']);
            });
            $keep = $rows[0];
            $extra = 0.0;
            $extras = [];
            for ($i = 1, $n = count($rows); $i < $n; $i++) {
                $extra += (float) $rows[$i]['annual_cost'];
                $extras[] = $rows[$i];
            }
            $out[] = [
                'kind' => 'duplicate',
                'normalized_name' => $norm,
                'vendor_name' => $keep['vendor_name'],
                'keep_id' => $keep['id'],
                'row_ids' => array_map(static fn ($r) => (int) $r['id'], $rows),
                'row_count' => count($rows),
                'extra_annual_cost' => round($extra, 2),
                'total_annual_cost' => round(array_sum(array_map(static fn ($r) => (float) $r['annual_cost'], $rows)), 2),
                'rows' => $rows,
                'extras' => $extras,
            ];
        }

        usort($out, static function ($a, $b) {
            return ($b['extra_annual_cost'] <=> $a['extra_annual_cost']);
        });

        return $out;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array{success:bool, overlaps?:array<int, array<string, mixed>>, error?:string}
     */
    private static function findOverlapsViaAi(PDO $pdo, int $userId, array $items): array
    {
        $context = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $name = trim((string) ($item['vendor_name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $context[] = [
                'id' => (int) ($item['id'] ?? 0),
                'vendor_name' => $name,
                'annual_cost' => round((float) ($item['annual_cost'] ?? 0), 2),
                'purpose' => substr(
                    VendorPurposeService::stripAiPurposeUiPrefix(
                        (string) ($item['purpose_of_subscription'] ?? $item['notes'] ?? '')
                    ),
                    0,
                    220
                ),
            ];
        }
        if (count($context) < 2) {
            return ['success' => true, 'overlaps' => []];
        }

        // Cap prompt size for large imports.
        usort($context, static function ($a, $b) {
            return ($b['annual_cost'] <=> $a['annual_cost']);
        });
        $context = array_slice($context, 0, 80);

        return AiService::analyzeVendorOverlapsJson($pdo, $userId, $context);
    }
}
