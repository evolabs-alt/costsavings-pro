<?php

namespace CostSavings;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use PDO;
use PDOException;

class AiService
{
    public const PRESETS = [
        'overlap' => 'Identify any overlap in services between vendors on the keep list.',
        'alternatives' => 'Suggest alternative vendors that might cost less for similar services on the keep list.',
        'lower_tiers' => 'Are there lower tiers of the same service that might fit based on how the vendor is being used (keep list)?',
        'duplicates' => 'Could the user be subscribed to more than one account from the same vendor (duplicate purchases)?',
        'executive' => 'Give a concise executive summary of cost optimization suggestions a savvy CFO would consider for the vendor data provided.',
        'cancel_steps' => 'Provide a practical cancellation playbook for this vendor: exact preparation checklist, account artifacts to save, cancellation path options, negotiation fallback, and post-cancellation validation steps.',
    ];

    /**
     * @param array<int, array<string, mixed>> $vendorContext
     */
    public static function buildPrompt(string $question, ?string $presetKey, array $vendorContext): string
    {
        $lines = [];
        foreach ($vendorContext as $v) {
            $vArr = is_array($v) ? $v : [];
            $statusToken = isset($vArr['status']) && $vArr['status'] !== ''
                ? VendorService::normalizeStatus($vArr['status'])
                : VendorService::resolveStatusFromItem($vArr);
            $lines[] = sprintf(
                '- %s | annual ~$%s | %s | status: %s | purpose: %s',
                $vArr['vendor_name'] ?? '',
                number_format((float) ($vArr['annual_cost'] ?? 0), 2),
                $vArr['frequency'] ?? '',
                VendorService::statusLabel($statusToken),
                substr((string) ($vArr['purpose_of_subscription'] ?? $vArr['notes'] ?? ''), 0, 500)
            );
        }
        $ctx = implode("\n", $lines);
        $preset = $presetKey && isset(self::PRESETS[$presetKey]) ? self::PRESETS[$presetKey] : '';

        return "You are Savvy CFO assistant. Be concise and actionable.\n\n"
            . "Output format: Respond using HTML only. Use tags such as <p>, <ul>, <li>, <strong>, <em>, <h3> for structure. "
            . "Do not use Markdown (no asterisks for bold, no # headings, no backticks, no horizontal rules with ---).\n\n"
            . ($preset !== '' ? "Focus: {$preset}\n\n" : '')
            . "User question:\n{$question}\n\nVendor data (visible to this user):\n{$ctx}\n";
    }

    /**
     * Current calendar-month usage for the Ask AI quota (same month key as `ask()`).
     *
     * @return array{limit:int, used:int, remaining:int, reset_hint:string}
     */
    public static function getMonthlyUsageStats(PDO $pdo, int $userId): array
    {
        \ensureAiUsageTable($pdo);
        $ym = date('Y-m');
        $limit = defined('AI_MONTHLY_LIMIT') ? (int) AI_MONTHLY_LIMIT : 50;
        $used = 0;
        try {
            $st = $pdo->prepare(
                'SELECT `usage_count` FROM `ai_usage` WHERE `user_id` = ? AND `year_month` = ?'
            );
            $st->execute([$userId, $ym]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            $used = (int) (is_array($row) ? ($row['usage_count'] ?? 0) : 0);
        } catch (PDOException $e) {
            error_log('AiService::getMonthlyUsageStats: ' . $e->getMessage());
        }

        return self::usageFields($limit, $used);
    }

    /**
     * @return array{limit:int, used:int, remaining:int, reset_hint:string}
     */
    private static function usageFields(int $limit, int $used): array
    {
        return [
            'limit' => $limit,
            'used' => $used,
            'remaining' => max(0, $limit - $used),
            'reset_hint' => self::monthlyResetHint(),
        ];
    }

    private static function monthlyResetHint(): string
    {
        $tzName = @date_default_timezone_get() ?: 'UTC';
        try {
            $tz = new DateTimeZone($tzName);
        } catch (Exception $e) {
            $tz = new DateTimeZone('UTC');
            $tzName = 'UTC';
        }
        $next = new DateTimeImmutable('first day of next month 00:00:00', $tz);

        return sprintf(
            'Quota resets at the start of each calendar month (next reset: %s, %s).',
            $next->format('F j, Y \a\t g:i A'),
            $tzName
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function ask(PDO $pdo, int $userId, string $prompt): array
    {
        $hasPerplexity = defined('PERPLEXITY_API_KEY') && PERPLEXITY_API_KEY !== '';
        $hasOpenAI = defined('OPENAI_API_KEY') && OPENAI_API_KEY !== '';
        if (!$hasPerplexity && !$hasOpenAI) {
            $u = self::getMonthlyUsageStats($pdo, $userId);

            return array_merge(
                ['success' => false, 'error' => 'AI is not configured (set PERPLEXITY_API_KEY or OPENAI_API_KEY).'],
                $u
            );
        }

        \ensureAiUsageTable($pdo);

        $ym = date('Y-m');
        $limit = defined('AI_MONTHLY_LIMIT') ? (int) AI_MONTHLY_LIMIT : 50;

        try {
            $st0 = $pdo->prepare(
                'SELECT `usage_count` FROM `ai_usage` WHERE `user_id` = ? AND `year_month` = ?'
            );
            $st0->execute([$userId, $ym]);
            $row0 = $st0->fetch(PDO::FETCH_ASSOC);
            $current = (int) (is_array($row0) ? ($row0['usage_count'] ?? 0) : 0);
        } catch (PDOException $e) {
            error_log('AiService::ask usage read: ' . $e->getMessage());

            return ['success' => false, 'error' => 'Could not check AI usage.'];
        }
        if ($current >= $limit) {
            return array_merge(
                [
                    'success' => false,
                    'error' => 'Monthly AI limit reached (' . $limit . ').',
                    'remaining' => 0,
                ],
                self::usageFields($limit, $current)
            );
        }

        try {
            $st = $pdo->prepare(
                'INSERT INTO `ai_usage` (`user_id`, `year_month`, `usage_count`) VALUES (?,?,1)
                 ON DUPLICATE KEY UPDATE `usage_count` = `usage_count` + 1'
            );
            $st->execute([$userId, $ym]);
        } catch (PDOException $e) {
            error_log('AiService::ask db: ' . $e->getMessage());

            return array_merge(
                ['success' => false, 'error' => 'Could not record usage.'],
                self::usageFields($limit, $current)
            );
        }

        $cnt = $current + 1;
        $remaining = max(0, $limit - $cnt);

        $maxTokens = defined('AI_MAX_TOKENS') ? (int) AI_MAX_TOKENS : 1200;
        $systemHtml = 'You are a concise CFO-style advisor. Always format your reply as HTML only: use <p>, <ul>, <ol>, <li>, <strong>, <em>, <h3>, <h4> as appropriate. Never use Markdown syntax: no * or ** for emphasis, no # for headings, no ``` code fences, no --- dividers. Do not wrap the entire answer in a markdown code block.';
        $messages = [
            ['role' => 'system', 'content' => $systemHtml],
            ['role' => 'user', 'content' => $prompt],
        ];

        if ($hasPerplexity) {
            $model = defined('AI_MODEL') ? AI_MODEL : 'sonar';
            $temperature = defined('AI_TEMPERATURE') ? (float) AI_TEMPERATURE : 0.7;
            $url = defined('PERPLEXITY_API_URL') ? PERPLEXITY_API_URL : 'https://api.perplexity.ai/chat/completions';
            $payload = [
                'model' => $model,
                'messages' => $messages,
                'max_tokens' => $maxTokens,
                'temperature' => $temperature,
            ];
            $out = self::postChatCompletion($url, PERPLEXITY_API_KEY, $payload);
        } else {
            $payload = [
                'model' => 'gpt-4o-mini',
                'messages' => $messages,
                'max_tokens' => $maxTokens,
            ];
            $out = self::postChatCompletion('https://api.openai.com/v1/chat/completions', OPENAI_API_KEY, $payload);
        }

        if (!$out['ok']) {
            return array_merge(
                ['success' => false, 'error' => 'AI request failed.', 'remaining' => $remaining],
                self::usageFields($limit, $cnt)
            );
        }

        $replyHtml = self::sanitizeAiHtml((string) ($out['text'] ?? ''));

        return array_merge(
            ['success' => true, 'reply' => $replyHtml, 'remaining' => $remaining],
            self::usageFields($limit, $cnt)
        );
    }

    /**
     * @param array<int, string> $vendors
     * @return array{success:bool, results?:array<int, array{vendor:string, aliases:array<int, string>, purpose:string}>, error?:string}
     */
    public static function lookupVendorPurposesLive(array $vendors): array
    {
        if (!defined('PERPLEXITY_API_KEY') || PERPLEXITY_API_KEY === '') {
            return ['success' => false, 'error' => 'Live web lookup requires PERPLEXITY_API_KEY.'];
        }
        $vendors = array_values(array_filter(array_map(static function ($v) {
            return trim((string) $v);
        }, $vendors), static function ($v) {
            return $v !== '';
        }));
        if (count($vendors) === 0) {
            return ['success' => true, 'results' => []];
        }

        $payload = [
            'model' => defined('AI_MODEL') ? AI_MODEL : 'sonar',
            'temperature' => 0.2,
            'max_tokens' => defined('AI_MAX_TOKENS') ? max(1200, (int) AI_MAX_TOKENS) : 1800,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You perform live web research to identify what vendors do. Return strict JSON only.',
                ],
                [
                    'role' => 'user',
                    'content' => self::buildVendorPurposeLookupPrompt($vendors),
                ],
            ],
        ];
        $url = defined('PERPLEXITY_API_URL') ? PERPLEXITY_API_URL : 'https://api.perplexity.ai/chat/completions';
        $out = self::postChatCompletion($url, PERPLEXITY_API_KEY, $payload);
        if (!$out['ok']) {
            return ['success' => false, 'error' => 'Live vendor purpose lookup failed.'];
        }

        $parsed = self::parseVendorPurposeLookupJson((string) ($out['text'] ?? ''));
        if (!$parsed['success']) {
            return $parsed;
        }

        return ['success' => true, 'results' => $parsed['results']];
    }

    /**
     * @param array<int, string> $vendors
     */
    private static function buildVendorPurposeLookupPrompt(array $vendors): string
    {
        return "For each vendor below, do a live web lookup and determine the vendor's top purpose/service.\n"
            . "Also provide 5 name variants (legal name, product/brand, alternate spellings/case/punctuation variants).\n"
            . "Respond with STRICT JSON only. No markdown, no prose.\n"
            . "Output schema:\n"
            . "{ \"results\": [ { \"vendor\": \"input vendor\", \"aliases\": [\"a1\",\"a2\",\"a3\",\"a4\",\"a5\"], \"purpose\": \"short purpose\" } ] }\n"
            . "Rules: aliases must be exactly 5 non-empty strings. purpose must be <= 220 chars.\n"
            . "Vendors:\n- " . implode("\n- ", $vendors);
    }

    /**
     * @return array{success:bool, results?:array<int, array{vendor:string, aliases:array<int, string>, purpose:string}>, error?:string}
     */
    private static function parseVendorPurposeLookupJson(string $raw): array
    {
        $json = trim($raw);
        if (str_starts_with($json, '```')) {
            $json = preg_replace('/^```(?:json)?\s*/i', '', $json) ?? $json;
            $json = preg_replace('/\s*```$/', '', $json) ?? $json;
            $json = trim($json);
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            // Some models wrap JSON in prose/citations; recover the first JSON object block.
            $start = strpos($json, '{');
            $end = strrpos($json, '}');
            if ($start !== false && $end !== false && $end > $start) {
                $candidate = substr($json, $start, ($end - $start + 1));
                $data = json_decode($candidate, true);
            }
        }
        if (is_array($data) && !isset($data['results']) && array_is_list($data)) {
            $data = ['results' => $data];
        }
        if (!is_array($data) || !isset($data['results']) || !is_array($data['results'])) {
            return ['success' => false, 'error' => 'Live lookup returned invalid JSON structure.'];
        }
        $results = [];
        foreach ($data['results'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $vendor = trim((string) ($row['vendor'] ?? ''));
            $purpose = trim((string) ($row['purpose'] ?? ''));
            $aliasesRaw = isset($row['aliases']) && is_array($row['aliases']) ? $row['aliases'] : [];
            $aliases = [];
            foreach ($aliasesRaw as $a) {
                $s = trim((string) $a);
                if ($s !== '') {
                    $aliases[] = $s;
                }
            }
            if ($vendor === '' || $purpose === '') {
                continue;
            }
            while (count($aliases) < 5) {
                $aliases[] = $vendor;
            }
            $aliases = array_slice($aliases, 0, 5);
            $results[] = [
                'vendor' => $vendor,
                'aliases' => $aliases,
                'purpose' => substr($purpose, 0, 220),
            ];
        }
        if (count($results) === 0) {
            return ['success' => false, 'error' => 'Live lookup returned no usable vendor purposes.'];
        }

        return ['success' => true, 'results' => $results];
    }

    /**
     * Allow safe HTML from the model; strip scripts and unknown tags. Plain text is wrapped in <p>.
     */
    private static function sanitizeAiHtml(string $html): string
    {
        $s = trim($html);
        if ($s === '') {
            return '';
        }
        $s = self::normalizeAiReplyWhitespace($s);
        $allowed = '<p><br><ul><ol><li><strong><em><b><i><h3><h4><h5><div><span>';
        $s = strip_tags($s, $allowed);
        if (!preg_match('/<[a-z][^>]*>/i', $s)) {
            return '<p>' . htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</p>';
        }

        return $s;
    }

    /**
     * Strip redundant CR/LF and extra blank lines from model output before sanitizing.
     */
    private static function normalizeAiReplyWhitespace(string $html): string
    {
        $s = str_replace(["\r\n", "\r"], "\n", $html);
        $s = preg_replace('/>\s+</s', '><', $s) ?? $s;
        $s = preg_replace('/(?:<br\s*\/?>\s*){2,}/i', '<br>', $s) ?? $s;
        $s = preg_replace('/\n{2,}/', "\n", $s) ?? $s;
        $s = preg_replace("/[ \t]+\n/", "\n", $s) ?? $s;

        return trim($s);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{ok: bool, text?: string}
     */
    private static function postChatCompletion(string $url, string $bearer, array $payload): array
    {
        $body = json_encode($payload);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $bearer,
            ],
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false || $code >= 400) {
            return ['ok' => false];
        }
        $data = json_decode($resp, true);
        $text = $data['choices'][0]['message']['content'] ?? '';

        return ['ok' => true, 'text' => $text];
    }
}
