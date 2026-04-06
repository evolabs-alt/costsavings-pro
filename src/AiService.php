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
    ];

    /**
     * @param array<int, array<string, mixed>> $vendorContext
     */
    public static function buildPrompt(string $question, ?string $presetKey, array $vendorContext): string
    {
        $lines = [];
        foreach ($vendorContext as $v) {
            $lines[] = sprintf(
                '- %s | annual ~$%s | %s | %s | purpose: %s',
                $v['vendor_name'] ?? '',
                number_format((float) ($v['annual_cost'] ?? 0), 2),
                $v['frequency'] ?? '',
                $v['cancel_keep'] ?? '',
                substr((string) ($v['purpose_of_subscription'] ?? $v['notes'] ?? ''), 0, 500)
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
     * Allow safe HTML from the model; strip scripts and unknown tags. Plain text is wrapped in <p>.
     */
    private static function sanitizeAiHtml(string $html): string
    {
        $s = trim($html);
        if ($s === '') {
            return '';
        }
        $allowed = '<p><br><ul><ol><li><strong><em><b><i><h3><h4><h5><div><span>';
        $s = strip_tags($s, $allowed);
        if (!preg_match('/<[a-z][^>]*>/i', $s)) {
            return '<p>' . htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</p>';
        }

        return $s;
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
