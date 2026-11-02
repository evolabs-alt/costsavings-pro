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

        $rawRows = [];

        foreach ($ctx['transactions'] as $txn) {
            if ($filter !== null && !isset($filter[$txn['account']])) {
                continue;
            }
            $rawRows[] = [
                'vendor_name' => $txn['payee'],
                'transaction_date' => $txn['date'],
                'amount' => $txn['amount'],
                'transaction_type' => $txn['transaction_type'],
                'account' => $txn['account'],
                'memo' => $txn['memo'],
            ];
        }

        return FrequencyPatternService::buildSplitImportPayload($rawRows);
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

        $transactionType = self::csvField($parsed, $headerMap, ['transaction type', 'type']);

        return [
            'account' => $currentAccount,
            'payee' => $payee,
            'date' => $dt,
            'amount' => self::normalizeAmountByType($amt, $transactionType),
            'transaction_type' => $transactionType,
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

        $rawRows = [];
        $currentVendor = null;

        for ($i = $headerIdx + 1; $i < count($lines); $i++) {
            $line = $lines[$i];
            if (trim($line) === '') {
                continue;
            }
            $parsed = str_getcsv($line);
            $first = isset($parsed[0]) ? trim($parsed[0], " \t\n\r\0\x0B\"") : '';
            $isTotal = stripos($first, 'Total for ') === 0;

            if ($first !== '' && !$isTotal) {
                $currentVendor = $first;
                continue;
            }

            if ($isTotal) {
                $currentVendor = null;
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
            $transactionType = self::csvField(
                $parsed,
                $headerMap,
                ['transaction type', 'type']
            );
            $normalizedAmount = self::normalizeAmountByType($amt, $transactionType);
            $rawRows[] = [
                'vendor_name' => $currentVendor,
                'transaction_date' => $dt,
                'amount' => $normalizedAmount,
                'transaction_type' => $transactionType,
                'account' => $account,
                'memo' => self::csvField(
                    $parsed,
                    $headerMap,
                    ['memo/description', 'memo', 'description', 'memo desc', 'memo/desc']
                ),
            ];
        }

        return FrequencyPatternService::buildSplitImportPayload($rawRows);
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
     * @param array<int, array{date:string,amount:float,account?:string,transaction_type?:string,memo?:string}> $rows
     * @return array{vendor_name:string,cost_per_period:float,frequency:string,annual_cost:float,last_payment_date:?string,account:string,amount_min:?float,amount_max:?float,amount_irregular:bool}
     */
    public static function buildVendorSummary(string $vendorName, array $rows): array
    {
        return FrequencyPatternService::summarizeSeries($vendorName, $rows);
    }

    public static function isDepositType(?string $type): bool
    {
        return preg_match('/^deposit$/i', trim((string) $type)) === 1;
    }

    public static function normalizeAmountByType(float $amount, ?string $type): float
    {
        if (self::isDepositType($type)) {
            return -abs($amount);
        }

        return abs($amount);
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
