<?php

namespace CostSavings;

/**
 * Format-agnostic CSV import with user-defined column mapping.
 */
class MappedCsvImport
{
    /** @var array<string, array<int, string>> */
    private const FIELD_ALIASES = [
        'vendor_name' => ['name', 'payee', 'vendor', 'vendor name', 'supplier'],
        'transaction_date' => ['transaction date', 'date', 'txn date', 'payment date'],
        'amount' => ['amount', 'cost', 'total', 'payment'],
        'transaction_type' => ['transaction type', 'type', 'txn type'],
        'memo' => ['description', 'memo/description', 'memo', 'memo desc', 'memo/desc', 'notes'],
        'account' => ['account', 'account name', 'gl account'],
    ];

    /**
     * @return array<int, array{key:string, label:string, required:bool}>
     */
    public static function targetFields(): array
    {
        return [
            ['key' => 'vendor_name', 'label' => 'Vendor / Payee', 'required' => true],
            ['key' => 'transaction_date', 'label' => 'Transaction date', 'required' => true],
            ['key' => 'amount', 'label' => 'Amount', 'required' => true],
            ['key' => 'transaction_type', 'label' => 'Transaction type', 'required' => false],
            ['key' => 'memo', 'label' => 'Memo / Description', 'required' => false],
            ['key' => 'account', 'label' => 'Account', 'required' => false],
        ];
    }

    /**
     * @param array<string, string|null> $columnMapping
     */
    public static function isAccountMapped(array $columnMapping): bool
    {
        $mapped = array_key_exists('account', $columnMapping) ? $columnMapping['account'] : null;

        return is_string($mapped) && trim($mapped) !== '';
    }

    /**
     * @return array{
     *   success: bool,
     *   columns?: array<int, string>,
     *   sample_rows?: array<int, array<int, string>>,
     *   row_count_estimate?: int,
     *   suggested_mapping?: array<string, string|null>,
     *   target_fields?: array<int, array{key:string, label:string, required:bool}>,
     *   error?: string
     * }
     */
    public static function readPreview(string $csvText): array
    {
        $ctx = self::resolveHeaderContext($csvText);
        if ($ctx === null) {
            return ['success' => false, 'error' => 'No header row found'];
        }

        $columns = $ctx['columns'];
        if (count($columns) === 0) {
            return ['success' => false, 'error' => 'No columns detected in header row'];
        }

        return [
            'success' => true,
            'columns' => $columns,
            'sample_rows' => array_slice($ctx['data_rows'], 0, 3),
            'row_count_estimate' => count($ctx['data_rows']),
            'suggested_mapping' => self::suggestMapping($columns),
            'target_fields' => self::targetFields(),
        ];
    }

    /**
     * @param array<string, string|null> $columnMapping
     * @return array<int, array{name:string, transaction_count:int}>
     */
    public static function listAccounts(string $csvText, array $columnMapping): array
    {
        if (!self::isAccountMapped($columnMapping)) {
            return [];
        }

        $ctx = self::resolveHeaderContext($csvText);
        if ($ctx === null) {
            return [];
        }

        $indexMap = self::buildColumnIndexMap($ctx['columns'], $columnMapping);
        if (($indexMap['account'] ?? null) === null) {
            return [];
        }

        $counts = [];
        $order = [];
        $lastAccount = '';

        foreach ($ctx['data_rows'] as $parsed) {
            $prevAccount = $lastAccount;
            $row = self::parseMappedRow($parsed, $indexMap, $lastAccount);
            if ($row === null) {
                if ($lastAccount !== '' && $lastAccount !== $prevAccount && !array_key_exists($lastAccount, $counts)) {
                    $counts[$lastAccount] = 0;
                    $order[] = $lastAccount;
                }
                continue;
            }
            if ($row['account'] === '') {
                continue;
            }
            $name = $row['account'];
            if (!array_key_exists($name, $counts)) {
                $counts[$name] = 0;
                $order[] = $name;
            }
            $counts[$name]++;
        }

        $accounts = [];
        foreach ($order as $name) {
            $accounts[] = [
                'name' => $name,
                'transaction_count' => $counts[$name],
            ];
        }

        return $accounts;
    }

    /**
     * @param array<string, string|null> $columnMapping
     * @param array<int, string>|null $selectedAccounts
     * @return array{
     *   summary: array<int, array{vendor_name:string,cost_per_period:float,frequency:string,annual_cost:float,last_payment_date:?string}>,
     *   raw: array<int, array{vendor_name:string,transaction_date:string,amount:float,transaction_type:string,account:string,memo:string}>,
     *   skipped_rows: int
     * }
     */
    public static function parse(string $csvText, array $columnMapping, ?array $selectedAccounts = null): array
    {
        $ctx = self::resolveHeaderContext($csvText);
        if ($ctx === null) {
            return ['summary' => [], 'raw' => [], 'skipped_rows' => 0];
        }

        $indexMap = self::buildColumnIndexMap($ctx['columns'], $columnMapping);
        $accountMapped = self::isAccountMapped($columnMapping);
        $filter = self::buildAccountFilter($accountMapped, $selectedAccounts);
        $rawRows = [];
        $skipped = 0;
        $lastAccount = '';

        foreach ($ctx['data_rows'] as $parsed) {
            $row = self::parseMappedRow($parsed, $indexMap, $lastAccount);
            if ($row === null) {
                $skipped++;
                continue;
            }
            if ($filter !== null) {
                if ($row['account'] === '' || !isset($filter[$row['account']])) {
                    $skipped++;
                    continue;
                }
            }
            $rawRows[] = $row;
        }

        return [
            'summary' => self::buildSummaryFromRaw($rawRows),
            'raw' => $rawRows,
            'skipped_rows' => $skipped,
        ];
    }

    /**
     * @param array<int, string> $columns
     * @param array<string, string|null> $mapping
     */
    public static function validateMapping(array $columns, array $mapping): ?string
    {
        $columnSet = [];
        foreach ($columns as $col) {
            $columnSet[$col] = true;
        }

        $usedColumns = [];
        foreach (self::targetFields() as $field) {
            $key = $field['key'];
            $required = (bool) $field['required'];
            $mapped = array_key_exists($key, $mapping) ? $mapping[$key] : null;
            $mappedCol = is_string($mapped) ? trim($mapped) : '';

            if ($required && $mappedCol === '') {
                return 'Missing required mapping: ' . $field['label'];
            }
            if ($mappedCol === '') {
                continue;
            }
            if (!isset($columnSet[$mappedCol])) {
                return 'Invalid column mapping for ' . $field['label'];
            }
            if (isset($usedColumns[$mappedCol])) {
                return 'Column "' . $mappedCol . '" is mapped more than once';
            }
            $usedColumns[$mappedCol] = true;
        }

        return null;
    }

    /**
     * @param array<int, string> $columns
     * @return array<string, string|null>
     */
    private static function suggestMapping(array $columns): array
    {
        $lowerToOriginal = [];
        foreach ($columns as $col) {
            $lowerToOriginal[strtolower(trim($col))] = $col;
        }

        $suggested = [];
        foreach (self::targetFields() as $field) {
            $key = $field['key'];
            $suggested[$key] = null;
            $aliases = self::FIELD_ALIASES[$key] ?? [];
            foreach ($aliases as $alias) {
                $aliasKey = strtolower(trim($alias));
                if (isset($lowerToOriginal[$aliasKey])) {
                    $suggested[$key] = $lowerToOriginal[$aliasKey];
                    break;
                }
            }
        }

        return $suggested;
    }

    /**
     * @return array{columns: array<int, string>, data_rows: array<int, array<int, string>>}|null
     */
    private static function resolveHeaderContext(string $csvText): ?array
    {
        $lines = preg_split('/\r\n|\r|\n/', $csvText);
        $headerIdx = -1;
        foreach ($lines as $i => $line) {
            if (trim($line) !== '') {
                $headerIdx = $i;
                break;
            }
        }
        if ($headerIdx < 0) {
            return null;
        }

        $headers = str_getcsv($lines[$headerIdx] ?? '');
        $columns = [];
        foreach ($headers as $h) {
            $columns[] = trim((string) $h);
        }

        $dataRows = [];
        for ($i = $headerIdx + 1; $i < count($lines); $i++) {
            $line = $lines[$i];
            if (trim($line) === '') {
                continue;
            }
            $dataRows[] = str_getcsv($line);
        }

        return [
            'columns' => $columns,
            'data_rows' => $dataRows,
        ];
    }

    /**
     * @param array<int, string> $columns
     * @param array<string, string|null> $columnMapping
     * @return array<string, int|null>
     */
    private static function buildColumnIndexMap(array $columns, array $columnMapping): array
    {
        $colIndex = [];
        foreach ($columns as $idx => $col) {
            $colIndex[$col] = $idx;
        }

        $indexMap = [];
        foreach (self::targetFields() as $field) {
            $key = $field['key'];
            $mapped = array_key_exists($key, $columnMapping) ? $columnMapping[$key] : null;
            $mappedCol = is_string($mapped) ? trim($mapped) : '';
            $indexMap[$key] = ($mappedCol !== '' && isset($colIndex[$mappedCol]))
                ? $colIndex[$mappedCol]
                : null;
        }

        return $indexMap;
    }

    /**
     * @param array<int, string>|null $selectedAccounts
     * @return array<string, true>|null
     */
    private static function buildAccountFilter(bool $accountMapped, ?array $selectedAccounts): ?array
    {
        if (!$accountMapped || $selectedAccounts === null) {
            return null;
        }

        $filter = [];
        foreach ($selectedAccounts as $account) {
            $key = trim((string) $account);
            if ($key !== '') {
                $filter[$key] = true;
            }
        }

        return $filter;
    }

    /**
     * @param array<int, string> $parsed
     * @param array<string, int|null> $indexMap
     * @return array{vendor_name:string,transaction_date:string,amount:float,transaction_type:string,account:string,memo:string}|null
     */
    private static function parseMappedRow(array $parsed, array $indexMap, string &$lastAccount): ?array
    {
        $accountIdx = $indexMap['account'] ?? null;
        if ($accountIdx !== null) {
            self::resolveAccountValue($parsed, $accountIdx, $lastAccount);
        }

        $vendorName = self::cellValue($parsed, $indexMap['vendor_name'] ?? null);
        $dateRaw = self::cellValue($parsed, $indexMap['transaction_date'] ?? null);
        $amountRaw = self::cellValue($parsed, $indexMap['amount'] ?? null);
        $transactionType = self::cellValue($parsed, $indexMap['transaction_type'] ?? null);
        $memo = self::cellValue($parsed, $indexMap['memo'] ?? null);

        if ($vendorName === '' || $dateRaw === '' || $amountRaw === '') {
            return null;
        }

        $date = CsvImport::parseDate($dateRaw);
        if ($date === null) {
            return null;
        }

        $amount = CsvImport::parseAmount($amountRaw);
        if ($amount === null) {
            return null;
        }

        $account = $accountIdx !== null ? $lastAccount : '';

        return [
            'vendor_name' => $vendorName,
            'transaction_date' => $date,
            'amount' => abs($amount),
            'transaction_type' => $transactionType,
            'account' => $account,
            'memo' => $memo,
        ];
    }

    /**
     * @param array<int, string> $parsed
     */
    private static function resolveAccountValue(array $parsed, ?int $accountIdx, string &$lastAccount): string
    {
        if ($accountIdx === null) {
            return '';
        }

        $accountRaw = self::cellValue($parsed, $accountIdx);
        if ($accountRaw !== '') {
            $lastAccount = $accountRaw;
        }

        return $lastAccount;
    }

    /**
     * @param array<int, string> $parsed
     */
    private static function cellValue(array $parsed, ?int $idx): string
    {
        if ($idx === null || !isset($parsed[$idx])) {
            return '';
        }

        return trim((string) $parsed[$idx]);
    }

    /**
     * @param array<int, array{vendor_name:string,transaction_date:string,amount:float,transaction_type:string,account:string,memo:string}> $rawRows
     * @return array<int, array{vendor_name:string,cost_per_period:float,frequency:string,annual_cost:float,last_payment_date:?string}>
     */
    private static function buildSummaryFromRaw(array $rawRows): array
    {
        $payeeRows = [];
        foreach ($rawRows as $row) {
            $vendor = $row['vendor_name'];
            if (!isset($payeeRows[$vendor])) {
                $payeeRows[$vendor] = [];
            }
            $payeeRows[$vendor][] = [
                'date' => $row['transaction_date'],
                'amount' => $row['amount'],
            ];
        }

        $summary = [];
        foreach ($payeeRows as $payee => $rows) {
            if (count($rows) > 0) {
                $summary[] = CsvImport::buildVendorSummary($payee, $rows);
            }
        }

        return $summary;
    }
}
