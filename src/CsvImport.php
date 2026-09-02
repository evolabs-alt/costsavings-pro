<?php

namespace CostSavings;

/**
 * Parses QuickBooks-style CSV exports:
 * - Transaction Detail by Account (primary)
 * - Transaction List by Vendor (legacy)
 */
class CsvImport
{
    private const VENDOR_HEADER_NEEDLE = ',Date,Transaction type';
    private const ACCOUNT_HEADER_DATE = 'transaction date';
    private const ACCOUNT_HEADER_TYPE = 'transaction type';

    /**
     * @return 'account'|'vendor'|'unknown'
     */
    public static function detectFormat(string $csvText): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $csvText);
        foreach ($lines as $line) {
            if (stripos($line, self::VENDOR_HEADER_NEEDLE) !== false) {
                return 'vendor';
            }
            $lower = strtolower($line);
            if (strpos($lower, self::ACCOUNT_HEADER_DATE) !== false
                && strpos($lower, self::ACCOUNT_HEADER_TYPE) !== false) {
                return 'account';
            }
        }

        return 'unknown';
    }

    /**
     * @return array<int, array{name:string, transaction_count:int}>
     */
    public static function listAccounts(string $csvText): array
    {
        $ctx = self::resolveAccountContext($csvText);
        if ($ctx === null) {
            return [];
        }

        $accounts = [];
        $seen = [];
        foreach ($ctx['account_counts'] as $name => $count) {
            if (!isset($seen[$name])) {
                $seen[$name] = true;
                $accounts[] = [
                    'name' => $name,
                    'transaction_count' => $count,
                ];
            }
        }

        return $accounts;
    }

    /**
     * Summary vendor rows use the chronologically latest transaction amount as cost_per_period.
     *
     * @param array<int, string>|null $selectedAccounts When set (account format), only these GL accounts are included.
     * @return array{
     *   summary: array<int, array{vendor_name:string,cost_per_period:float,frequency:string,annual_cost:float,last_payment_date:?string}>,
     *   raw: array<int, array{vendor_name:string,transaction_date:string,amount:float,transaction_type:string,account:string,memo:string}>
     * }
     */
    public static function parse(string $csvText, ?array $selectedAccounts = null): array
    {
        $format = self::detectFormat($csvText);
        if ($format === 'account') {
            return self::parseAccountFormat($csvText, $selectedAccounts);
        }
        if ($format === 'vendor') {
            return self::parseVendorFormat($csvText);
        }

        return ['summary' => [], 'raw' => []];
    }

    /**
     * @param array<int, string>|null $selectedAccounts
     * @return array{summary: array, raw: array}
     */
    private static function parseAccountFormat(string $csvText, ?array $selectedAccounts): array
    {
        $ctx = self::resolveAccountContext($csvText);
        if ($ctx === null) {
            return ['summary' => [], 'raw' => []];
        }

        $filter = null;
        if ($selectedAccounts !== null) {
            $filter = [];
            foreach ($selectedAccounts as $a) {
                $key = trim((string) $a);
                if ($key !== '') {
                    $filter[$key] = true;
                }
            }
        }

        $payeeRows = [];
        $rawRows = [];

        foreach ($ctx['transactions'] as $txn) {
            if ($filter !== null && !isset($filter[$txn['account']])) {
                continue;
            }
            $payee = $txn['payee'];
            if (!isset($payeeRows[$payee])) {
                $payeeRows[$payee] = [];
            }
            $payeeRows[$payee][] = [
                'date' => $txn['date'],
                'amount' => $txn['amount'],
                'account' => $txn['account'],
            ];
            $rawRows[] = [
                'vendor_name' => $payee,
                'transaction_date' => $txn['date'],
                'amount' => $txn['amount'],
                'transaction_type' => $txn['transaction_type'],
                'account' => $txn['account'],
                'memo' => $txn['memo'],
            ];
        }

        $summary = [];
        foreach ($payeeRows as $payee => $rows) {
            if (count($rows) > 0) {
                $summary[] = self::buildVendorSummary($payee, $rows);
            }
        }

        return ['summary' => $summary, 'raw' => $rawRows];
    }

    /**
     * @return array{
     *   header_map: array<string, int>,
     *   account_counts: array<string, int>,
     *   account_order: array<int, string>,
     *   transactions: array<int, array{account:string, payee:string, date:string, amount:float, transaction_type:string, memo:string}>
     * }|null
     */
    private static function resolveAccountContext(string $csvText): ?array
    {
        $lines = preg_split('/\r\n|\r|\n/', $csvText);
        $headerIdx = -1;
        foreach ($lines as $i => $line) {
            $lower = strtolower($line);
            if (strpos($lower, self::ACCOUNT_HEADER_DATE) !== false
                && strpos($lower, self::ACCOUNT_HEADER_TYPE) !== false) {
                $headerIdx = $i;
                break;
            }
        }
        if ($headerIdx < 0) {
            return null;
        }

        $headers = str_getcsv($lines[$headerIdx] ?? '');
        $headerMap = self::buildHeaderMap($headers);

        $accountCounts = [];
        $accountOrder = [];
        $transactions = [];
        $currentAccount = null;

        for ($i = $headerIdx + 1; $i < count($lines); $i++) {
            $line = $lines[$i];
            if (trim($line) === '') {
                continue;
            }
            $parsed = str_getcsv($line);
            $first = isset($parsed[0]) ? trim($parsed[0], " \t\n\r\0\x0B\"") : '';
            $dateStr = isset($parsed[1]) ? trim($parsed[1]) : '';
            $isTotal = stripos($first, 'Total for ') === 0;

            if ($isTotal) {
                $currentAccount = null;
                continue;
            }

            if ($first !== '' && $dateStr === '') {
                $currentAccount = $first;
                if (!array_key_exists($currentAccount, $accountCounts)) {
                    $accountCounts[$currentAccount] = 0;
                    $accountOrder[] = $currentAccount;
                }
                continue;
            }

            if ($dateStr === '' || $currentAccount === null) {
                continue;
            }

            $txn = self::parseAccountTransactionRow($parsed, $headerMap, $currentAccount);
            if ($txn === null) {
                continue;
            }

            $accountCounts[$currentAccount]++;
            $transactions[] = $txn;
        }

        $orderedCounts = [];
        foreach ($accountOrder as $name) {
            $orderedCounts[$name] = $accountCounts[$name] ?? 0;
        }

        return [
            'header_map' => $headerMap,
            'account_counts' => $orderedCounts,
            'account_order' => $accountOrder,
            'transactions' => $transactions,
        ];
    }

    /**
     * @param array<int, string> $parsed
     * @param array<string, int> $headerMap
     * @return array{account:string, payee:string, date:string, amount:float, transaction_type:string, memo:string}|null
     */
    private static function parseAccountTransactionRow(array $parsed, array $headerMap, string $currentAccount): ?array
    {
        $dateStr = self::csvField($parsed, $headerMap, ['transaction date', 'date']);
        $amtRaw = self::csvField($parsed, $headerMap, ['amount']);
        if ($dateStr === '' || $amtRaw === '') {
            return null;
        }
        $dt = self::parseDate($dateStr);
        if ($dt === null) {
            return null;
        }
        $amt = self::parseAmount($amtRaw);
        if ($amt === null) {
            return null;
        }

        $payee = self::csvField($parsed, $headerMap, ['name']);
        if ($payee === '') {
            return null;
        }

        return [
            'account' => $currentAccount,
            'payee' => $payee,
            'date' => $dt,
            'amount' => abs($amt),
            'transaction_type' => self::csvField($parsed, $headerMap, ['transaction type', 'type']),
            'memo' => self::csvField($parsed, $headerMap, ['description', 'memo/description', 'memo', 'memo desc', 'memo/desc']),
        ];
    }

    /**
     * @return array{summary: array, raw: array}
     */
    private static function parseVendorFormat(string $csvText): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $csvText);
        $headerIdx = -1;
        foreach ($lines as $i => $line) {
            if (stripos($line, self::VENDOR_HEADER_NEEDLE) !== false) {
                $headerIdx = $i;
                break;
            }
        }
        if ($headerIdx < 0) {
            return ['summary' => [], 'raw' => []];
        }
        $headers = str_getcsv($lines[$headerIdx] ?? '');
        $headerMap = self::buildHeaderMap($headers);

        $vendors = [];
        $rawRows = [];
        $currentVendor = null;
        $rows = [];

        for ($i = $headerIdx + 1; $i < count($lines); $i++) {
            $line = $lines[$i];
            if (trim($line) === '') {
                continue;
            }
            $parsed = str_getcsv($line);
            $first = isset($parsed[0]) ? trim($parsed[0], " \t\n\r\0\x0B\"") : '';
            $isTotal = stripos($first, 'Total for ') === 0;

            if ($first !== '' && !$isTotal) {
                if ($currentVendor !== null && count($rows) > 0) {
                    $vendors[] = self::buildVendorSummary($currentVendor, $rows);
                }
                $currentVendor = $first;
                $rows = [];
                continue;
            }

            if ($isTotal) {
                if ($currentVendor !== null && count($rows) > 0) {
                    $vendors[] = self::buildVendorSummary($currentVendor, $rows);
                }
                $currentVendor = null;
                $rows = [];
                continue;
            }

            if ($currentVendor === null) {
                continue;
            }

            $dateStr = isset($parsed[1]) ? trim($parsed[1]) : '';
            $amtRaw = isset($parsed[7]) ? trim($parsed[7]) : '';
            if ($dateStr === '' || $amtRaw === '') {
                continue;
            }
            $dt = self::parseDate($dateStr);
            if ($dt === null) {
                continue;
            }
            $amt = self::parseAmount($amtRaw);
            if ($amt === null) {
                continue;
            }
            $account = self::csvField(
                $parsed,
                $headerMap,
                ['account', 'account name']
            );
            $rows[] = ['date' => $dt, 'amount' => abs($amt), 'account' => $account];
            $rawRows[] = [
                'vendor_name' => $currentVendor,
                'transaction_date' => $dt,
                'amount' => abs($amt),
                'transaction_type' => self::csvField(
                    $parsed,
                    $headerMap,
                    ['transaction type', 'type']
                ),
                'account' => $account,
                'memo' => self::csvField(
                    $parsed,
                    $headerMap,
                    ['memo/description', 'memo', 'description', 'memo desc', 'memo/desc']
                ),
            ];
        }

        if ($currentVendor !== null && count($rows) > 0) {
            $vendors[] = self::buildVendorSummary($currentVendor, $rows);
        }

        return ['summary' => $vendors, 'raw' => $rawRows];
    }

    /**
     * @param array<int, string> $headers
     * @return array<string, int>
     */
    private static function buildHeaderMap(array $headers): array
    {
        $out = [];
        foreach ($headers as $idx => $h) {
            $key = strtolower(trim((string) $h));
            if ($key !== '') {
                $out[$key] = $idx;
            }
        }

        return $out;
    }

    /**
     * @param array<int, string> $parsed
     * @param array<string, int> $headerMap
     * @param array<int, string> $aliases
     */
    private static function csvField(array $parsed, array $headerMap, array $aliases): string
    {
        foreach ($aliases as $name) {
            $key = strtolower(trim($name));
            if (array_key_exists($key, $headerMap)) {
                $idx = $headerMap[$key];
                $val = isset($parsed[$idx]) ? trim((string) $parsed[$idx]) : '';
                if ($val !== '') {
                    return $val;
                }
            }
        }

        return '';
    }

    /**
     * Unique non-empty account names, sorted alphabetically, joined with ", ".
     *
     * @param array<int, string> $accounts
     */
    public static function uniqueAccountsJoined(array $accounts): string
    {
        $unique = [];
        foreach ($accounts as $a) {
            $key = trim((string) $a);
            if ($key === '' || $key === '(No account)') {
                continue;
            }
            $unique[$key] = true;
        }
        if (count($unique) === 0) {
            return '';
        }
        $names = array_keys($unique);
        natcasesort($names);

        return implode(', ', array_values($names));
    }

    /**
     * @param array<int, array{date:string,amount:float,account?:string}> $rows
     * @return array{vendor_name:string,cost_per_period:float,frequency:string,annual_cost:float,last_payment_date:?string,account:string}
     */
    public static function buildVendorSummary(string $vendorName, array $rows): array
    {
        usort($rows, function ($a, $b) {
            return strcmp($a['date'], $b['date']);
        });

        $lastRow = $rows[count($rows) - 1];
        $latestAmount = (float) $lastRow['amount'];

        $dates = array_column($rows, 'date');
        $gaps = [];
        for ($i = 1; $i < count($dates); $i++) {
            $d1 = new \DateTimeImmutable($dates[$i - 1]);
            $d2 = new \DateTimeImmutable($dates[$i]);
            $gaps[] = (int) $d2->diff($d1)->format('%a');
        }

        $frequency = self::inferFrequency($gaps);
        $mult = self::annualMultiplier($frequency);
        $annual = $latestAmount * $mult;
        $last = $dates[count($dates) - 1] ?? null;

        $accountNames = [];
        foreach ($rows as $r) {
            if (isset($r['account'])) {
                $accountNames[] = (string) $r['account'];
            }
        }

        return [
            'vendor_name' => $vendorName,
            'cost_per_period' => round($latestAmount, 2),
            'frequency' => $frequency,
            'annual_cost' => round($annual, 2),
            'last_payment_date' => $last,
            'account' => self::uniqueAccountsJoined($accountNames),
        ];
    }

    /**
     * @param array<int, float> $gaps
     */
    private static function inferFrequency(array $gaps): string
    {
        if (count($gaps) === 0) {
            return 'one_off';
        }
        sort($gaps);
        $med = self::median($gaps);
        if ($med <= 10) {
            return 'weekly';
        }
        if ($med <= 16) {
            return 'bi_weekly';
        }
        if ($med <= 40) {
            return 'monthly';
        }
        if ($med <= 70) {
            return 'quarterly';
        }
        if ($med <= 200) {
            return 'semi_annual';
        }

        return 'annually';
    }

    private static function annualMultiplier(string $frequency): float
    {
        switch ($frequency) {
            case 'weekly':
                return 52;
            case 'bi_weekly':
                return 26;
            case 'semi_monthly':
                return 24;
            case 'monthly':
                return 12;
            case 'quarterly':
                return 4;
            case 'semi_annual':
                return 2;
            case 'annually':
                return 1;
            case 'one_off':
                return 1;
            default:
                return 12;
        }
    }

    /**
     * @param array<int, float> $nums
     */
    private static function median(array $nums): float
    {
        $c = count($nums);
        if ($c === 0) {
            return 0.0;
        }
        sort($nums);
        $mid = (int) floor(($c - 1) / 2);
        if ($c % 2 === 1) {
            return (float) $nums[$mid];
        }

        return ((float) $nums[$mid] + (float) $nums[$mid + 1]) / 2.0;
    }

    public static function parseDate(string $s): ?string
    {
        $s = trim($s);
        foreach (['m/d/Y', 'Y-m-d', 'n/j/Y', 'd/m/Y'] as $fmt) {
            $dt = \DateTimeImmutable::createFromFormat($fmt, $s);
            if ($dt !== false) {
                return $dt->format('Y-m-d');
            }
        }

        return null;
    }

    public static function parseAmount(string $s): ?float
    {
        $s = preg_replace('/[^\d.\-]/', '', $s);
        if ($s === '' || $s === '-' || $s === '.') {
            return null;
        }

        return (float) $s;
    }
}
