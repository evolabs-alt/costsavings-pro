<?php

namespace CostSavings;

use PDO;
use PDOException;

class VendorPurposeService
{
    /**
     * Number of vendor names sent to the live AI lookup per request. Smaller
     * chunks improve resilience (per-call latency, partial failure blast
     * radius) at the cost of more AI calls overall.
     */
    private const LIVE_LOOKUP_CHUNK_SIZE = 10;

    /**
     * @param array<int, array{id:int, vendor_name:string}> $rows
     * @return array{success:bool, resolved:array<int, array{id:int, vendor_name:string, purpose:string, source:string}>, unresolved:array<int, array{id:int, vendor_name:string}>, error?:string}
     *
     * Vendor purpose cache in `vendor_detail` is global (shared across all orgs). $orgId is
     * still used for calculator row updates and org-scoped fallback queries only.
     */
    public static function resolveForVisibleRows(PDO $pdo, int $orgId, array $rows): array
    {
        $targets = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int) ($row['id'] ?? 0);
            $vendor = trim((string) ($row['vendor_name'] ?? ''));
            if ($id <= 0 || $vendor === '') {
                continue;
            }
            $targets[] = ['id' => $id, 'vendor_name' => $vendor];
        }
        if (count($targets) === 0) {
            return ['success' => true, 'resolved' => [], 'unresolved' => []];
        }

        $detailByCanon = self::vendorDetailPurposeByCanon($pdo);

        $resolved = [];
        $unresolved = [];
        foreach ($targets as $t) {
            $canon = self::canon($t['vendor_name']);
            $purpose = ($canon !== '' && isset($detailByCanon[$canon])) ? $detailByCanon[$canon] : '';
            if ($purpose !== '') {
                $resolved[] = [
                    'id' => $t['id'],
                    'vendor_name' => $t['vendor_name'],
                    'purpose' => $purpose,
                    'source' => 'vendor_detail',
                ];
            } else {
                $unresolved[] = $t;
            }
        }
        if (count($unresolved) === 0) {
            return ['success' => true, 'resolved' => $resolved, 'unresolved' => []];
        }

        $lookupNames = [];
        foreach ($unresolved as $u) {
            $lookupNames[] = $u['vendor_name'];
        }
        $lookupNames = array_values(array_unique($lookupNames));
        $byCanonical = [];
        $lookupErrors = [];
        foreach (array_chunk($lookupNames, self::LIVE_LOOKUP_CHUNK_SIZE) as $chunk) {
            $ai = AiService::lookupVendorPurposesLive($chunk);
            if (!$ai['success']) {
                $lookupErrors[] = (string) ($ai['error'] ?? 'Purpose lookup failed.');
                continue;
            }
            foreach (($ai['results'] ?? []) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $vendor = trim((string) ($item['vendor'] ?? ''));
                $purpose = trim((string) ($item['purpose'] ?? ''));
                $aliases = isset($item['aliases']) && is_array($item['aliases']) ? $item['aliases'] : [];
                if ($vendor === '' || $purpose === '') {
                    continue;
                }
                $canon = self::canon($vendor);
                $names = [];
                foreach ($aliases as $a) {
                    $s = trim((string) $a);
                    if ($s !== '') {
                        $names[] = $s;
                    }
                }
                if (!in_array($vendor, $names, true)) {
                    array_unshift($names, $vendor);
                }
                $names = array_values(array_unique($names));
                while (count($names) < 5) {
                    $names[] = $vendor;
                }
                $byCanonical[$canon] = [
                    'purpose' => substr($purpose, 0, 220),
                    'names' => array_slice($names, 0, 5),
                ];
                self::upsertVendorDetail($pdo, array_slice($names, 0, 5), substr($purpose, 0, 220));
            }
        }

        foreach ($unresolved as $u) {
            $canon = self::canon($u['vendor_name']);
            if (isset($byCanonical[$canon])) {
                $resolved[] = [
                    'id' => $u['id'],
                    'vendor_name' => $u['vendor_name'],
                    'purpose' => $byCanonical[$canon]['purpose'],
                    'source' => 'live_lookup',
                ];
            }
        }

        $resolvedKeys = [];
        foreach ($resolved as $r) {
            $resolvedKeys[(int) $r['id']] = true;
        }
        $left = [];
        foreach ($unresolved as $u) {
            if (!isset($resolvedKeys[(int) $u['id']])) {
                $left[] = $u;
            }
        }

        // Backstop: rows the AI couldn't resolve and that have no manually
        // entered purpose get a synthetic "Unknown" purpose so the row isn't
        // left blank. Rows with an existing purpose are preserved untouched
        // and remain in the unresolved list. We deliberately do NOT cache
        // "Unknown" in vendor_detail so subsequent runs re-attempt the AI
        // lookup.
        if (count($left) > 0) {
            $idList = [];
            foreach ($left as $u) {
                $rid = (int) ($u['id'] ?? 0);
                if ($rid > 0) {
                    $idList[] = $rid;
                }
            }
            $existing = [];
            if (count($idList) > 0) {
                $placeholders = implode(',', array_fill(0, count($idList), '?'));
                $sql = "SELECT id, COALESCE(purpose_of_subscription, '') AS p
                        FROM cost_calculator_items
                        WHERE org_id = ? AND id IN ($placeholders)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute(array_merge([$orgId], $idList));
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $existing[(int) $row['id']] = trim((string) ($row['p'] ?? ''));
                }
            }
            $stillLeft = [];
            foreach ($left as $u) {
                $rid = (int) ($u['id'] ?? 0);
                if ($rid > 0 && ($existing[$rid] ?? '') === '') {
                    $resolved[] = [
                        'id' => $rid,
                        'vendor_name' => (string) ($u['vendor_name'] ?? ''),
                        'purpose' => 'Unknown',
                        'source' => 'fallback_unknown',
                    ];
                } else {
                    $stillLeft[] = $u;
                }
            }
            $left = $stillLeft;
        }

        if (count($left) > 0 && count($lookupErrors) > 0) {
            return [
                'success' => true,
                'resolved' => $resolved,
                'unresolved' => $left,
                'error' => 'Some live-lookup batches failed and were skipped.',
            ];
        }

        return ['success' => true, 'resolved' => $resolved, 'unresolved' => $left];
    }

    /**
     * Canonical vendor name → purpose from global `vendor_detail` (shared across organizations).
     * First alias match per canonical form wins row order from the database.
     *
     * @return array<string, string>
     */
    private static function vendorDetailPurposeByCanon(PDO $pdo): array
    {
        $map = [];
        try {
            $st = $pdo->query(
                'SELECT purpose, name_1, name_2, name_3, name_4, name_5 FROM vendor_detail'
            );
            if ($st === false) {
                return $map;
            }
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $purpose = trim((string) ($row['purpose'] ?? ''));
                if ($purpose === '') {
                    continue;
                }
                foreach (['name_1', 'name_2', 'name_3', 'name_4', 'name_5'] as $col) {
                    $cn = self::canon((string) ($row[$col] ?? ''));
                    if ($cn !== '' && !isset($map[$cn])) {
                        $map[$cn] = $purpose;
                    }
                }
            }
        } catch (PDOException $e) {
            error_log('VendorPurposeService::vendorDetailPurposeByCanon: ' . $e->getMessage());
        }

        return $map;
    }

    /**
     * Merge into global vendor_detail cache (org-agnostic).
     *
     * @param array<int, string> $names
     */
    private static function upsertVendorDetail(PDO $pdo, array $names, string $purpose): void
    {
        $canonNames = array_map([self::class, 'canon'], $names);
        $sql = 'SELECT id, name_1, name_2, name_3, name_4, name_5 FROM vendor_detail';
        $st = $pdo->query($sql);
        if ($st === false) {
            return;
        }
        $matchId = 0;
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $existing = [
                self::canon((string) ($row['name_1'] ?? '')),
                self::canon((string) ($row['name_2'] ?? '')),
                self::canon((string) ($row['name_3'] ?? '')),
                self::canon((string) ($row['name_4'] ?? '')),
                self::canon((string) ($row['name_5'] ?? '')),
            ];
            foreach ($canonNames as $cn) {
                if ($cn !== '' && in_array($cn, $existing, true)) {
                    $matchId = (int) ($row['id'] ?? 0);
                    break 2;
                }
            }
        }
        $p = substr(trim($purpose), 0, 220);
        if ($matchId > 0) {
            $upd = $pdo->prepare(
                'UPDATE vendor_detail
                 SET name_1 = ?, name_2 = ?, name_3 = ?, name_4 = ?, name_5 = ?, purpose = ?
                 WHERE id = ?'
            );
            $upd->execute([$names[0], $names[1], $names[2], $names[3], $names[4], $p, $matchId]);

            return;
        }
        try {
            $ins = $pdo->prepare(
                'INSERT INTO vendor_detail (org_id, name_1, name_2, name_3, name_4, name_5, purpose)
                 VALUES (?,?,?,?,?,?,?)'
            );
            $ins->execute([null, $names[0], $names[1], $names[2], $names[3], $names[4], $p]);
        } catch (PDOException $e) {
            error_log('VendorPurposeService::upsertVendorDetail: ' . $e->getMessage());
        }
    }

    private static function canon(string $value): string
    {
        $s = strtolower(trim($value));
        if ($s === '') {
            return '';
        }
        $s = preg_replace('/[^\p{L}\p{N}]+/u', '', $s) ?? $s;

        return $s;
    }
}
