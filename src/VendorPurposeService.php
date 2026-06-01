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

    /** Prepended to purpose text when auto-populated from AI or shared vendor cache (U+2728 + space). */
    public const AI_PURPOSE_UI_PREFIX = "✨ ";

    public const SOURCE_VENDOR_DETAIL = 'vendor_detail';

    public const SOURCE_LIVE_LOOKUP = 'live_lookup';

    public const SOURCE_FALLBACK_UNKNOWN = 'fallback_unknown';

    public static function stripAiPurposeUiPrefix(string $s): string
    {
        $p = self::AI_PURPOSE_UI_PREFIX;
        if ($p !== '' && str_starts_with($s, $p)) {
            return substr($s, strlen($p));
        }

        return $s;
    }

    /**
     * True when AI/cache returned a placeholder instead of a real purpose (field should stay blank).
     */
    public static function isUnusablePurposeText(string $purpose): bool
    {
        $base = trim(self::stripAiPurposeUiPrefix($purpose));
        if ($base === '') {
            return true;
        }
        $lower = strtolower($base);

        if (preg_match('/\binsufficient\b/i', $base)) {
            return true;
        }
        if (preg_match('/\bnot\s+enough\s+(?:data|information|info|search|results?)\b/i', $base)) {
            return true;
        }
        if (preg_match('/\b(?:unable|could\s+not|cannot|can\'t|did\s+not)\s+to\s+(?:determine|find|identify|locate|verify|confirm|establish)\b/i', $base)) {
            return true;
        }
        if (preg_match('/\bno\s+(?:reliable\s+)?(?:information|info|data)\b/i', $base)) {
            return true;
        }
        if (preg_match('/\b(?:information|data)\s+(?:is\s+)?(?:not\s+)?unavailable\b/i', $base)) {
            return true;
        }
        if (preg_match('/\black\s+of\s+(?:information|data|evidence|results?)\b/i', $base)) {
            return true;
        }
        if (preg_match('/\b(?:limited|insufficient)\s+(?:search\s+)?results?\b/i', $base)) {
            return true;
        }

        // "No … found/identified/matched" templates (e.g. no clear vendor identified in search results).
        if (preg_match('/\bno\s+(?:[\w\/-]+\s+){0,28}?(?:found|identified|matched|located|determined|available)\b/i', $base)) {
            return true;
        }
        if (preg_match('/\bno\s+(?:clear|specific|relevant|credible|active|definitive|conclusive|direct|reliable|public|actionable|verifiable)\b/i', $base)) {
            return true;
        }
        if (preg_match('/\bnot\s+(?:found|identified|available|determined|matched|located|verified)\b/i', $base)) {
            return true;
        }

        // Search-result disclaimers ("… in search results", "from search results").
        if (preg_match('/\bsearch\s+results?\b/i', $base)
            && preg_match('/\b(?:no|not|without|unable|could\s+not|cannot|can\'t|did\s+not|unidentified|unverified)\b/i', $base)) {
            return true;
        }
        if (preg_match('/\b(?:found|identified|matched|located)\s+(?:in|from|within)\s+(?:the\s+)?(?:provided\s+)?search\s+results?\b/i', $base)) {
            return true;
        }
        if (preg_match('/\b(?:purpose|vendor|service|business|company)\s+(?:was\s+)?not\s+(?:found|identified)\b/i', $base)) {
            return true;
        }

        // Generic non-answers ("Unknown publishing company", "Unknown vendor").
        if (preg_match('/^unknown\b/i', $base)) {
            return true;
        }

        return in_array($lower, [
            'n/a',
            'na',
            'none',
            'not found',
            'no data',
            'no information',
            'unknown',
        ], true);
    }

    /**
     * Format purpose for `cost_calculator_items.purpose_of_subscription` after auto-populate.
     */
    public static function formatAutoPopulatedPurposeForStorage(string $purpose, string $source): string
    {
        $trimmed = trim($purpose);
        if ($trimmed === '') {
            return '';
        }
        if ($source === self::SOURCE_FALLBACK_UNKNOWN) {
            return $trimmed;
        }
        if ($source !== self::SOURCE_VENDOR_DETAIL && $source !== self::SOURCE_LIVE_LOOKUP) {
            return $trimmed;
        }
        $base = self::stripAiPurposeUiPrefix($trimmed);

        return self::AI_PURPOSE_UI_PREFIX . $base;
    }

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
            if ($purpose !== '' && !self::isUnusablePurposeText($purpose)) {
                $resolved[] = [
                    'id' => $t['id'],
                    'vendor_name' => $t['vendor_name'],
                    'purpose' => $purpose,
                    'source' => self::SOURCE_VENDOR_DETAIL,
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
        $insufficientCanons = [];
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
                if ($vendor === '') {
                    continue;
                }
                $canon = self::canon($vendor);
                if ($purpose === '' || self::isUnusablePurposeText($purpose)) {
                    if ($canon !== '') {
                        $insufficientCanons[$canon] = true;
                    }
                    continue;
                }
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
                    'source' => self::SOURCE_LIVE_LOOKUP,
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
                $canon = self::canon((string) ($u['vendor_name'] ?? ''));
                if ($rid > 0 && ($existing[$rid] ?? '') === '' && !isset($insufficientCanons[$canon])) {
                    $resolved[] = [
                        'id' => $rid,
                        'vendor_name' => (string) ($u['vendor_name'] ?? ''),
                        'purpose' => 'Unknown',
                        'source' => self::SOURCE_FALLBACK_UNKNOWN,
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
                if ($purpose === '' || self::isUnusablePurposeText($purpose)) {
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
        if (self::isUnusablePurposeText($purpose)) {
            return;
        }
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
