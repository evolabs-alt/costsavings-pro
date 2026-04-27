<?php

namespace CostSavings;

/**
 * Parses QuickBooks-style "Cost Savings - Transaction List by Vendor" CSV exports.
 */
class CsvImport
{
    private const HEADER_NEEDLE = ',Date,Transaction type';

    /**
     * @return array{
     *   summary: array<int, array{vendor_name:string,cost_per_period:float,frequency:string,annual_cost:float,last_payment_date:?string}>,
     *   raw: array<int, array{vendor_name:string,transaction_date:string,amount:float,transaction_type:string,account:string,memo:string}>
     * }
     */
    public static function parse(string $csvText): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $csvText);
        $headerIdx = -1;
        foreach ($lines as $i => $line) {
            if (stripos($line, self::HEADER_NEEDLE) !== false) {
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
                    $vendors[] = self::buildVendorRow($currentVendor, $rows);
                }
                $currentVendor = $first;
                $rows = [];
                continue;
            }

            if ($isTotal) {
                if ($currentVendor !== null && count($rows) > 0) {
                    $vendors[] = self::buildVendorRow($currentVendor, $rows);
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
            $rows[] = ['date' => $dt, 'amount' => abs($amt)];
            $rawRows[] = [
                'vendor_name' => $currentVendor,
                'transaction_date' => $dt,
                'amount' => abs($amt),
                'transaction_type' => self::csvField(
                    $parsed,
                    $headerMap,
                    ['transaction type', 'type']
                ),
                'account' => self::csvField(
                    $parsed,
                    $headerMap,
                    ['account', 'account name']
                ),
                'memo' => self::csvField(
                    $parsed,
                    $headerMap,
                    ['memo/description', 'memo', 'description', 'memo desc', 'memo/desc']
                ),
            ];
        }

        if ($currentVendor !== null && count($rows) > 0) {
            $vendors[] = self::buildVendorRow($currentVendor, $rows);
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
     * @param array<int, array{date:string, amount:float}> $rows
     * @return array{vendor_name:string,cost_per_period:float,frequency:string,annual_cost:float,last_payment_date:?string}
     */
    private static function buildVendorRow(string $vendorName, array $rows): array
    {
        usort($rows, function ($a, $b) {
            return strcmp($a['date'], $b['date']);
        });

        $amounts = array_column($rows, 'amount');
        $typical = self::median($amounts);

        $dates = array_column($rows, 'date');
        $gaps = [];
        for ($i = 1; $i < count($dates); $i++) {
            $d1 = new \DateTimeImmutable($dates[$i - 1]);
            $d2 = new \DateTimeImmutable($dates[$i]);
            $gaps[] = (int) $d2->diff($d1)->format('%a');
        }

        $frequency = self::inferFrequency($gaps);
        $mult = self::annualMultiplier($frequency);
        $annual = $typical * $mult;
        $last = $dates[count($dates) - 1] ?? null;

        return [
            'vendor_name' => $vendorName,
            'cost_per_period' => round($typical, 2),
            'frequency' => $frequency,
            'annual_cost' => round($annual, 2),
            'last_payment_date' => $last,
        ];
    }

    /**
     * @param array<int, float> $gaps
     */
    private static function inferFrequency(array $gaps): string
    {
        // Vendors with only a single observed transaction are treated as
        // one-off purchases rather than guessing a recurring cadence.
        if (count($gaps) === 0) {
            return 'one_off';
        }
        sort($gaps);
        $med = self::median($gaps);
        if ($med <= 10) {
            return 'weekly';
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

    private static function parseDate(string $s): ?string
    {
        $s = trim($s);
        $dt = \DateTimeImmutable::createFromFormat('m/d/Y', $s);
        if ($dt === false) {
            return null;
        }

        return $dt->format('Y-m-d');
    }

    private static function parseAmount(string $s): ?float
    {
        $s = preg_replace('/[^\d.\-]/', '', $s);
        if ($s === '' || $s === '-' || $s === '.') {
            return null;
        }

        return (float) $s;
    }
}
