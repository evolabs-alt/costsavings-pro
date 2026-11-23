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
     * @param string $mode One of: duplicates | overlaps | all
     * @return array{
     *   success:bool,
     *   duplicates:array<int, array<string, mixed>>,
     *   overlaps:array<int, array<string, mixed>>,
     *   duplicate_savings:float,
     *   overlap_savings:float,
     *   error?:string,
     *   overlap_warning?:string
     * }
     */
    public static function analyze(PDO $pdo, int $userId, array $items, string $mode = 'all'): array
    {
        $mode = strtolower(trim($mode));
        if (!in_array($mode, ['duplicates', 'overlaps', 'all'], true)) {
            $mode = 'all';
        }

        $duplicates = [];
        $duplicateSavings = 0.0;
        if ($mode === 'duplicates' || $mode === 'all') {
            $duplicates = self::findDuplicates($items);
            foreach ($duplicates as $row) {
                $duplicateSavings += (float) ($row['extra_annual_cost'] ?? 0);
            }
        }

        if ($mode === 'duplicates') {
            return [
                'success' => true,
                'duplicates' => $duplicates,
                'overlaps' => [],
                'duplicate_savings' => round($duplicateSavings, 2),
                'overlap_savings' => 0.0,
            ];
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

        $costByNorm = self::effectiveCostByNormalizedName($items);
        $rawOverlaps = $overlapResult['overlaps'] ?? [];
        $overlaps = [];
        $overlapSavings = 0.0;
        $claimed = [];
        foreach ($rawOverlaps as $pair) {
            if (!is_array($pair)) {
                continue;
            }
            $a = (string) ($pair['vendor_a'] ?? '');
            $b = (string) ($pair['vendor_b'] ?? '');
            $keyA = VendorService::normalizeVendorName($a);
            $keyB = VendorService::normalizeVendorName($b);
            if ($keyA !== '' && isset($claimed[$keyA])) {
                continue;
            }
            if ($keyB !== '' && isset($claimed[$keyB])) {
                continue;
            }

            $costA = $keyA !== '' ? (float) ($costByNorm[$keyA] ?? 0) : 0.0;
            $costB = $keyB !== '' ? (float) ($costByNorm[$keyB] ?? 0) : 0.0;
            // Conservative consolidate estimate: cancel the cheaper vendor (range avg included).
            $conservative = ($costA > 0 && $costB > 0) ? min($costA, $costB) : 0.0;

            $savings = max(0.0, (float) ($pair['suggested_annual_savings'] ?? 0));
            if ($savings <= 0 && $conservative > 0) {
                $savings = $conservative;
            } elseif ($savings > 0 && $conservative > 0 && $savings > $conservative) {
                // Cap AI estimate at the smaller effective spend (range averages included).
                $savings = $conservative;
            }
            if ($savings <= 0) {
                continue;
            }

            if ($keyA !== '') {
                $claimed[$keyA] = true;
            }
            if ($keyB !== '') {
                $claimed[$keyB] = true;
            }
            $pair['suggested_annual_savings'] = round($savings, 2);
            $overlaps[] = $pair;
            $overlapSavings += $savings;
        }

        return [
            'success' => true,
            'duplicates' => $duplicates,
            'overlaps' => $overlaps,
            'duplicate_savings' => round($duplicateSavings, 2),
            'overlap_savings' => round($overlapSavings, 2),
        ];
    }

    /**
     * Annual spend for redundancy math. When annual_cost is $0 (typical for
     * irregular amount ranges), estimate from the average of amount_min/max.
     *
     * @param array<string, mixed> $item
     */
    private static function effectiveAnnualCost(array $item): float
    {
        $annual = (float) ($item['annual_cost'] ?? 0);
        if ($annual > 0) {
            return $annual;
        }

        $min = isset($item['amount_min']) && $item['amount_min'] !== null && $item['amount_min'] !== ''
            ? (float) $item['amount_min']
            : null;
        $max = isset($item['amount_max']) && $item['amount_max'] !== null && $item['amount_max'] !== ''
            ? (float) $item['amount_max']
            : null;
        if ($min === null && $max === null) {
            return 0.0;
        }
        if ($min === null) {
            $avg = (float) $max;
        } elseif ($max === null) {
            $avg = (float) $min;
        } else {
            $avg = ($min + $max) / 2.0;
        }
        if ($avg <= 0) {
            return 0.0;
        }

        $frequency = VendorService::normalizeFrequencyValue($item['frequency'] ?? '');
        $multiplier = FrequencyPatternService::annualMultiplier($frequency);
        if ($multiplier <= 0) {
            // Irregular / unknown cadence: treat as quarterly for savings estimates.
            $multiplier = FrequencyPatternService::annualMultiplier('quarterly');
        }

        return $avg * $multiplier;
    }

    /**
     * Highest effective annual cost per normalized vendor name (range averages included).
     *
     * @param array<int, array<string, mixed>> $items
     * @return array<string, float>
     */
    private static function effectiveCostByNormalizedName(array $items): array
    {
        $out = [];
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
            $cost = self::effectiveAnnualCost($item);
            if (!isset($out[$norm]) || $cost > $out[$norm]) {
                $out[$norm] = $cost;
            }
        }

        return $out;
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
            $annual = (float) ($item['annual_cost'] ?? 0);
            $effective = self::effectiveAnnualCost($item);
            $groups[$norm][] = [
                'id' => (int) ($item['id'] ?? 0),
                'vendor_name' => $name,
                'annual_cost' => $annual,
                'effective_annual_cost' => round($effective, 2),
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
                return ($b['effective_annual_cost'] <=> $a['effective_annual_cost']);
            });
            $keep = $rows[0];
            $extra = 0.0;
            $extras = [];
            for ($i = 1, $n = count($rows); $i < $n; $i++) {
                $extra += (float) $rows[$i]['effective_annual_cost'];
                $extras[] = $rows[$i];
            }
            // Only report duplicates that imply real savings (>$0).
            if ($extra <= 0) {
                continue;
            }
            $out[] = [
                'kind' => 'duplicate',
                'normalized_name' => $norm,
                'vendor_name' => $keep['vendor_name'],
                'keep_id' => $keep['id'],
                'row_ids' => array_map(static fn ($r) => (int) $r['id'], $rows),
                'row_count' => count($rows),
                'extra_annual_cost' => round($extra, 2),
                'total_annual_cost' => round(
                    array_sum(array_map(static fn ($r) => (float) $r['effective_annual_cost'], $rows)),
                    2
                ),
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
                'annual_cost' => round(self::effectiveAnnualCost($item), 2),
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
