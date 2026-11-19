<?php

declare(strict_types=1);

namespace CostSavings;

/**
 * Detects payment frequency from payments-per-month rate (±10%)
 * and flags irregular amounts. One payee maps to one vendor row.
 */
class FrequencyPatternService
{
    private const DAYS_PER_MONTH = 30.4375;
    private const TOLERANCE = 0.10;

    /** @var array<string, float> payments per month */
    private const TARGET_RATES = [
        'weekly' => 52 / 12,
        'bi_weekly' => 26 / 12,
        'monthly' => 1.0,
        'quarterly' => 1 / 3,
        'semi_annual' => 1 / 6,
        'annually' => 1 / 12,
    ];

    /** @var array<string, float> expected gap in days */
    private const PERIOD_DAYS = [
        'weekly' => 7.0,
        'bi_weekly' => 14.0,
        'monthly' => 30.4375,
        'quarterly' => 91.3125,
        'semi_annual' => 182.625,
        'annually' => 365.25,
    ];

    /**
     * Group raw transactions by payee and build one summary row per payee.
     *
     * @param array<int, array{vendor_name:string,transaction_date:string,amount:float,transaction_type?:string,account?:string,memo?:string}> $rawRows
     * @return array{
     *   summary: array<int, array{vendor_name:string,cost_per_period:float,frequency:string,annual_cost:float,last_payment_date:?string,account:string,amount_min:?float,amount_max:?float,amount_irregular:bool}>,
     *   raw: array<int, array{vendor_name:string,transaction_date:string,amount:float,transaction_type:string,account:string,memo:string}>
     * }
     */
    public static function buildSplitImportPayload(array $rawRows): array
    {
        $byPayee = [];
        foreach ($rawRows as $row) {
            $vendor = trim((string) ($row['vendor_name'] ?? ''));
            if ($vendor === '') {
                continue;
            }
            if (!isset($byPayee[$vendor])) {
                $byPayee[$vendor] = [];
            }
            $byPayee[$vendor][] = [
                'date' => (string) ($row['transaction_date'] ?? ''),
                'amount' => (float) ($row['amount'] ?? 0),
                'account' => (string) ($row['account'] ?? ''),
                'transaction_type' => (string) ($row['transaction_type'] ?? ''),
                'memo' => (string) ($row['memo'] ?? ''),
            ];
        }

        $summary = [];
        foreach ($byPayee as $payee => $txs) {
            $summary[] = self::summarizeSeries($payee, $txs);
        }

        $outRaw = [];
        foreach ($rawRows as $row) {
            $outRaw[] = [
                'vendor_name' => (string) ($row['vendor_name'] ?? ''),
                'transaction_date' => (string) ($row['transaction_date'] ?? ''),
                'amount' => (float) ($row['amount'] ?? 0),
                'transaction_type' => (string) ($row['transaction_type'] ?? ''),
                'account' => (string) ($row['account'] ?? ''),
                'memo' => (string) ($row['memo'] ?? ''),
            ];
        }

        return ['summary' => $summary, 'raw' => $outRaw];
    }

    /**
     * Summarize a single vendor series.
     *
     * @param array<int, array{date:string,amount:float,account?:string,transaction_type?:string,memo?:string}> $rows
     * @return array{vendor_name:string,cost_per_period:float,frequency:string,annual_cost:float,last_payment_date:?string,account:string,amount_min:?float,amount_max:?float,amount_irregular:bool}
     */
    public static function summarizeSeries(string $vendorName, array $rows): array
    {
        usort($rows, static function ($a, $b) {
            return strcmp((string) $a['date'], (string) $b['date']);
        });

        $spendRows = [];
        foreach ($rows as $r) {
            $type = isset($r['transaction_type']) ? (string) $r['transaction_type'] : '';
            if (CsvImport::isDepositType($type)) {
                continue;
            }
            $spendRows[] = $r;
        }
        $costRows = count($spendRows) > 0 ? $spendRows : $rows;

        $accountNames = [];
        foreach ($rows as $r) {
            if (isset($r['account'])) {
                $accountNames[] = (string) $r['account'];
            }
        }
        $accountJoined = CsvImport::uniqueAccountsJoined($accountNames);

        if (count($costRows) === 0) {
            return [
                'vendor_name' => $vendorName,
                'cost_per_period' => 0.0,
                'frequency' => 'irregular',
                'annual_cost' => 0.0,
                'last_payment_date' => null,
                'account' => $accountJoined,
                'amount_min' => null,
                'amount_max' => null,
                'amount_irregular' => false,
            ];
        }

        $dates = [];
        $amounts = [];
        foreach ($costRows as $r) {
            $dates[] = (string) $r['date'];
            $amounts[] = round((float) $r['amount'], 2);
        }

        $frequency = self::inferFrequencyFromDates($dates);
        $amountMin = min($amounts);
        $amountMax = max($amounts);
        $amountIrregular = $amountMin !== $amountMax;

        $costPerPeriod = 0.0;
        $annual = 0.0;
        if ($frequency !== 'irregular' && !$amountIrregular) {
            $costPerPeriod = $amountMax;
            $annual = $costPerPeriod * self::annualMultiplier($frequency);
        }

        $last = $dates[count($dates) - 1] ?? null;

        return [
            'vendor_name' => $vendorName,
            'cost_per_period' => round($costPerPeriod, 2),
            'frequency' => $frequency,
            'annual_cost' => round($annual, 2),
            'last_payment_date' => $last,
            'account' => $accountJoined,
            'amount_min' => $amountIrregular ? $amountMin : null,
            'amount_max' => $amountIrregular ? $amountMax : null,
            'amount_irregular' => $amountIrregular,
        ];
    }

    /**
     * @param array<int, string> $dates Y-m-d sorted
     */
    public static function inferFrequencyFromDates(array $dates): string
    {
        $n = count($dates);
        if ($n <= 1) {
            return 'irregular';
        }

        try {
            $first = new \DateTimeImmutable($dates[0]);
            $last = new \DateTimeImmutable($dates[$n - 1]);
        } catch (\Exception $e) {
            return 'irregular';
        }

        $days = (int) $last->diff($first)->format('%a');
        if ($days <= 0) {
            return 'irregular';
        }
        // Span covers (n - 1) intervals; rate = intervals per month.
        $months = max($days / self::DAYS_PER_MONTH, 1 / self::DAYS_PER_MONTH);
        $rate = ($n - 1) / $months;
        $matched = self::matchRateToFrequency($rate);
        if ($matched === 'irregular') {
            return 'irregular';
        }

        // Require most consecutive gaps to fit the matched cadence (±10%).
        $period = self::PERIOD_DAYS[$matched] ?? 0.0;
        if ($period <= 0) {
            return 'irregular';
        }
        $gaps = [];
        for ($i = 1; $i < $n; $i++) {
            try {
                $d1 = new \DateTimeImmutable($dates[$i - 1]);
                $d2 = new \DateTimeImmutable($dates[$i]);
            } catch (\Exception $e) {
                return 'irregular';
            }
            $gaps[] = (int) $d2->diff($d1)->format('%a');
        }
        $fit = 0;
        foreach ($gaps as $gap) {
            if ($gap > 0 && abs($gap - $period) / $period <= self::TOLERANCE) {
                $fit++;
            }
        }
        $need = max(1, (int) ceil(count($gaps) * 0.6));
        if ($fit < $need) {
            return 'irregular';
        }

        return $matched;
    }

    public static function matchRateToFrequency(float $rate): string
    {
        $bestFreq = 'irregular';
        $bestRel = PHP_FLOAT_MAX;

        foreach (self::TARGET_RATES as $freq => $target) {
            if ($target <= 0) {
                continue;
            }
            $rel = abs($rate - $target) / $target;
            if ($rel <= self::TOLERANCE && $rel < $bestRel) {
                $bestRel = $rel;
                $bestFreq = $freq;
            }
        }

        return $bestFreq;
    }

    public static function annualMultiplier(string $frequency): float
    {
        switch ($frequency) {
            case 'weekly':
                return 52;
            case 'bi_weekly':
                return 26;
            case 'monthly':
                return 12;
            case 'quarterly':
                return 4;
            case 'semi_annual':
                return 2;
            case 'annually':
                return 1;
            case 'irregular':
            case 'one_off':
            case 'semi_monthly':
                return 0;
            default:
                return 0;
        }
    }
}
