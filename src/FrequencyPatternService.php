<?php

declare(strict_types=1);

namespace CostSavings;

/**
 * Detects payment frequency from payments-per-month rate (±10%),
 * splits mixed-cadence vendors into numbered rows, and flags irregular amounts.
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
     * Group raw transactions by payee, split mixed cadences, retag raw vendor names.
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
        foreach ($rawRows as $idx => $row) {
            $vendor = trim((string) ($row['vendor_name'] ?? ''));
            if ($vendor === '') {
                continue;
            }
            if (!isset($byPayee[$vendor])) {
                $byPayee[$vendor] = [];
            }
            $byPayee[$vendor][] = [
                '_idx' => (int) $idx,
                'date' => (string) ($row['transaction_date'] ?? ''),
                'amount' => (float) ($row['amount'] ?? 0),
                'account' => (string) ($row['account'] ?? ''),
                'transaction_type' => (string) ($row['transaction_type'] ?? ''),
                'memo' => (string) ($row['memo'] ?? ''),
            ];
        }

        $summary = [];
        $retag = []; // original raw index => new vendor_name

        foreach ($byPayee as $payee => $txs) {
            $seriesList = self::extractSeries($txs);
            $n = count($seriesList);
            foreach ($seriesList as $i => $seriesTxs) {
                $name = $n === 1 ? $payee : ($payee . ' (' . ($i + 1) . ')');
                $summary[] = self::summarizeSeries($name, $seriesTxs);
                foreach ($seriesTxs as $tx) {
                    $retag[(int) $tx['_idx']] = $name;
                }
            }
        }

        $outRaw = [];
        foreach ($rawRows as $idx => $row) {
            $copy = [
                'vendor_name' => $retag[(int) $idx] ?? (string) ($row['vendor_name'] ?? ''),
                'transaction_date' => (string) ($row['transaction_date'] ?? ''),
                'amount' => (float) ($row['amount'] ?? 0),
                'transaction_type' => (string) ($row['transaction_type'] ?? ''),
                'account' => (string) ($row['account'] ?? ''),
                'memo' => (string) ($row['memo'] ?? ''),
            ];
            $outRaw[] = $copy;
        }

        return ['summary' => $summary, 'raw' => $outRaw];
    }

    /**
     * Summarize a single vendor series (no further splitting).
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

    public static function normalizeMemo(string $memo): string
    {
        $m = strtolower(trim($memo));
        $m = preg_replace('/\d{4}[-\/]\d{1,2}[-\/]\d{1,2}/', ' ', $m) ?? $m;
        $m = preg_replace('/\d{1,2}[-\/]\d{1,2}[-\/]\d{2,4}/', ' ', $m) ?? $m;
        $m = preg_replace('/\d{4,}/', ' ', $m) ?? $m;
        $m = preg_replace('/[^a-z\s]/', ' ', $m) ?? $m;
        $m = preg_replace('/\s+/', ' ', $m) ?? $m;

        return trim($m);
    }

    /**
     * @param array<int, array{_idx:int,date:string,amount:float,account:string,transaction_type:string,memo:string}> $txs
     * @return array<int, array<int, array{_idx:int,date:string,amount:float,account:string,transaction_type:string,memo:string}>>
     */
    private static function extractSeries(array $txs): array
    {
        usort($txs, static function ($a, $b) {
            $c = strcmp($a['date'], $b['date']);
            if ($c !== 0) {
                return $c;
            }

            return $a['_idx'] <=> $b['_idx'];
        });

        $spend = [];
        $deposits = [];
        foreach ($txs as $tx) {
            if (CsvImport::isDepositType($tx['transaction_type'])) {
                $deposits[] = $tx;
            } else {
                $spend[] = $tx;
            }
        }
        $pool = count($spend) > 0 ? $spend : $txs;

        $remaining = $pool;
        $series = [];

        while (count($remaining) >= 2) {
            $best = self::findBestCadenceSubsequence($remaining);
            if ($best === null) {
                break;
            }
            $series[] = $best['txs'];
            $used = [];
            foreach ($best['txs'] as $tx) {
                $used[$tx['_idx']] = true;
            }
            $remaining = array_values(array_filter($remaining, static function ($tx) use ($used) {
                return !isset($used[$tx['_idx']]);
            }));
        }

        if (count($remaining) > 0) {
            // Group leftovers by memo cluster when helpful
            $byMemo = [];
            foreach ($remaining as $tx) {
                $key = self::normalizeMemo($tx['memo']);
                if ($key === '') {
                    $key = '__empty__';
                }
                if (!isset($byMemo[$key])) {
                    $byMemo[$key] = [];
                }
                $byMemo[$key][] = $tx;
            }
            if (count($byMemo) > 1 && count($series) > 0) {
                foreach ($byMemo as $group) {
                    $series[] = $group;
                }
            } else {
                $series[] = $remaining;
            }
        }

        // Include deposit-only leftover only when no spend existed
        if (count($spend) === 0 && count($deposits) > 0 && count($series) === 0) {
            $series[] = $deposits;
        }

        usort($series, static function ($a, $b) {
            $da = $a[0]['date'] ?? '';
            $db = $b[0]['date'] ?? '';
            $c = strcmp($da, $db);
            if ($c !== 0) {
                return $c;
            }

            return count($b) <=> count($a);
        });

        return $series;
    }

    /**
     * @param array<int, array{_idx:int,date:string,amount:float,account:string,transaction_type:string,memo:string}> $remaining
     * @return array{txs: array<int, array>, frequency: string, score: float}|null
     */
    private static function findBestCadenceSubsequence(array $remaining): ?array
    {
        $best = null;
        $n = count($remaining);
        if ($n < 2) {
            return null;
        }

        foreach (self::PERIOD_DAYS as $freq => $periodDays) {
            for ($start = 0; $start < $n - 1; $start++) {
                $chain = [$remaining[$start]];
                $memoKey = self::normalizeMemo($remaining[$start]['memo']);
                $anchorAmt = round((float) $remaining[$start]['amount'], 2);

                try {
                    $lastDate = new \DateTimeImmutable($remaining[$start]['date']);
                } catch (\Exception $e) {
                    continue;
                }

                for ($j = $start + 1; $j < $n; $j++) {
                    try {
                        $d = new \DateTimeImmutable($remaining[$j]['date']);
                    } catch (\Exception $e) {
                        continue;
                    }
                    $gap = (int) $d->diff($lastDate)->format('%a');
                    if ($gap <= 0) {
                        continue;
                    }
                    $rel = abs($gap - $periodDays) / $periodDays;
                    if ($rel > self::TOLERANCE) {
                        // Allow skipping if too soon (overlapping other cadence)
                        if ($gap < $periodDays * (1 - self::TOLERANCE)) {
                            continue;
                        }
                        // Too late — stop extending from this anchor for contiguous fit
                        break;
                    }

                    $chain[] = $remaining[$j];
                    $lastDate = $d;
                }

                if (count($chain) < 2) {
                    continue;
                }

                $dates = array_column($chain, 'date');
                $inferred = self::inferFrequencyFromDates($dates);
                if ($inferred !== $freq && $inferred !== 'irregular') {
                    // Rate-based check may differ slightly; still accept if gap chain matched this period
                    // but prefer rate confirmation when possible
                }
                if ($inferred === 'irregular') {
                    // Re-check rate for this chain alone
                    $inferred = self::inferFrequencyFromDates($dates);
                }
                if ($inferred !== $freq) {
                    // Gap chain matched period; allow if rate is within tolerance of this freq
                    $rateOk = false;
                    try {
                        $count = count($dates);
                        $first = new \DateTimeImmutable($dates[0]);
                        $last = new \DateTimeImmutable($dates[$count - 1]);
                        $days = (int) $last->diff($first)->format('%a');
                        if ($days > 0 && $count >= 2) {
                            $months = max($days / self::DAYS_PER_MONTH, 1 / self::DAYS_PER_MONTH);
                            $rate = ($count - 1) / $months;
                            $target = self::TARGET_RATES[$freq];
                            if (abs($rate - $target) / $target <= self::TOLERANCE) {
                                $rateOk = true;
                            }
                        }
                    } catch (\Exception $e) {
                        $rateOk = false;
                    }
                    if (!$rateOk) {
                        continue;
                    }
                }

                $sameMemo = 0;
                $sameAmt = 0;
                foreach ($chain as $tx) {
                    if (self::normalizeMemo($tx['memo']) === $memoKey && $memoKey !== '') {
                        $sameMemo++;
                    }
                    if (round((float) $tx['amount'], 2) === $anchorAmt) {
                        $sameAmt++;
                    }
                }
                $len = count($chain);
                $score = $len * 10.0
                    + ($sameMemo / $len) * 3.0
                    + ($sameAmt / $len) * 2.0;

                if ($best === null || $score > $best['score']) {
                    $best = [
                        'txs' => $chain,
                        'frequency' => $freq,
                        'score' => $score,
                    ];
                }
            }
        }

        return $best;
    }
}
