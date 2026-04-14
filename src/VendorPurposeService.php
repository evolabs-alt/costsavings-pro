<?php

namespace CostSavings;

use PDO;
use PDOException;

class VendorPurposeService
{
    /**
     * @param array<int, array{id:int, vendor_name:string}> $rows
     * @return array{success:bool, resolved:array<int, array{id:int, vendor_name:string, purpose:string, source:string}>, unresolved:array<int, array{id:int, vendor_name:string}>, error?:string}
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

        $resolved = [];
        $unresolved = [];
        foreach ($targets as $t) {
            $hit = self::findByAnyAlias($pdo, $orgId, $t['vendor_name']);
            if ($hit !== null && $hit['purpose'] !== '') {
                $resolved[] = [
                    'id' => $t['id'],
                    'vendor_name' => $t['vendor_name'],
                    'purpose' => $hit['purpose'],
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
        foreach (array_chunk($lookupNames, 25) as $chunk) {
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
                self::upsertVendorDetail($pdo, $orgId, array_slice($names, 0, 5), substr($purpose, 0, 220));
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
     * @return array{purpose:string}|null
     */
    private static function findByAnyAlias(PDO $pdo, int $orgId, string $vendorName): ?array
    {
        $canon = self::canon($vendorName);
        if ($canon === '') {
            return null;
        }
        $st = $pdo->prepare(
            'SELECT purpose, name_1, name_2, name_3, name_4, name_5
             FROM vendor_detail
             WHERE org_id = ?'
        );
        $st->execute([$orgId]);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $names = [
                (string) ($row['name_1'] ?? ''),
                (string) ($row['name_2'] ?? ''),
                (string) ($row['name_3'] ?? ''),
                (string) ($row['name_4'] ?? ''),
                (string) ($row['name_5'] ?? ''),
            ];
            foreach ($names as $n) {
                if ($n !== '' && self::canon($n) === $canon) {
                    return ['purpose' => trim((string) ($row['purpose'] ?? ''))];
                }
            }
        }

        return null;
    }

    /**
     * @param array<int, string> $names
     */
    private static function upsertVendorDetail(PDO $pdo, int $orgId, array $names, string $purpose): void
    {
        $canonNames = array_map([self::class, 'canon'], $names);
        $sql = 'SELECT id, name_1, name_2, name_3, name_4, name_5 FROM vendor_detail WHERE org_id = ?';
        $st = $pdo->prepare($sql);
        $st->execute([$orgId]);
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
                 WHERE id = ? AND org_id = ?'
            );
            $upd->execute([$names[0], $names[1], $names[2], $names[3], $names[4], $p, $matchId, $orgId]);

            return;
        }
        try {
            $ins = $pdo->prepare(
                'INSERT INTO vendor_detail (org_id, name_1, name_2, name_3, name_4, name_5, purpose)
                 VALUES (?,?,?,?,?,?,?)'
            );
            $ins->execute([$orgId, $names[0], $names[1], $names[2], $names[3], $names[4], $p]);
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
