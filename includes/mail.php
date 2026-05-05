<?php

require_once __DIR__ . '/pro_log.php';

/**
 * Strip HTML to a plain-text fallback for multipart/alternative-style APIs.
 */
function sendEmailHtmlToPlain(string $html): string {
    $plain = html_entity_decode(
        strip_tags(str_ireplace(['<br>', '<br/>', '<br />'], "\n", $html)),
        ENT_QUOTES | ENT_HTML5,
        'UTF-8'
    );
    $plain = trim($plain);

    return $plain !== '' ? $plain : 'Please view this message in an HTML-capable email client.';
}

/**
 * @return true|array{success:false,error_message:string,error_info:string,smtp_debug?:string}
 */
function sendEmail($to, $subject, $body) {
    $to = trim((string) $to);
    $subject = (string) $subject;
    $body = (string) $body;

    $token = defined('POSTMARK_SERVER_TOKEN') ? trim((string) POSTMARK_SERVER_TOKEN) : '';
    if ($token === '') {
        proLog('sendEmail_postmark_fail', [
            'to' => $to,
            'subject' => $subject,
            'error_message' => 'POSTMARK_SERVER_TOKEN is not set',
        ]);

        return [
            'success' => false,
            'error_message' => 'Postmark is not configured',
            'error_info' => 'Set POSTMARK_SERVER_TOKEN in config.php or the POSTMARK_SERVER_TOKEN environment variable.',
        ];
    }

    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        proLog('sendEmail_postmark_fail', [
            'to' => $to,
            'subject' => $subject,
            'error_message' => 'Invalid recipient address',
        ]);

        return [
            'success' => false,
            'error_message' => 'Invalid recipient email address',
            'error_info' => $to,
        ];
    }

    $fromEmail = defined('SMTP_FROM_EMAIL') ? trim((string) SMTP_FROM_EMAIL) : '';
    $fromName = defined('SMTP_FROM_NAME') ? trim((string) SMTP_FROM_NAME) : '';
    if ($fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        proLog('sendEmail_postmark_fail', [
            'to' => $to,
            'subject' => $subject,
            'error_message' => 'Invalid SMTP_FROM_EMAIL',
        ]);

        return [
            'success' => false,
            'error_message' => 'Invalid From email in configuration',
            'error_info' => 'Set SMTP_FROM_EMAIL to a Postmark-verified address.',
        ];
    }

    $fromHeader = $fromName !== '' ? $fromName . ' <' . $fromEmail . '>' : $fromEmail;
    $payload = [
        'From' => $fromHeader,
        'To' => $to,
        'Subject' => $subject,
        'HtmlBody' => $body,
        'TextBody' => sendEmailHtmlToPlain($body),
        'ReplyTo' => $fromEmail,
    ];

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        proLog('sendEmail_postmark_fail', ['to' => $to, 'subject' => $subject, 'error_message' => 'json_encode failed']);

        return [
            'success' => false,
            'error_message' => 'Could not build email payload',
            'error_info' => 'json_encode failed',
        ];
    }

    $debug = defined('POSTMARK_DEBUG') && POSTMARK_DEBUG;
    proLog('sendEmail_postmark_start', [
        'to' => $to,
        'subject' => $subject,
        'from' => $fromEmail,
    ]);

    $url = 'https://api.postmarkapp.com/email';
    $responseBody = '';
    $http = 0;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            proLog('sendEmail_postmark_fail', ['to' => $to, 'error_message' => 'curl_init failed']);

            return [
                'success' => false,
                'error_message' => 'Could not initialize HTTP client',
                'error_info' => 'curl_init failed',
            ];
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
                'X-Postmark-Server-Token: ' . $token,
            ],
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 30,
        ]);
        $responseBody = (string) curl_exec($ch);
        $errNo = curl_errno($ch);
        $err = curl_error($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($errNo !== 0) {
            proLog('sendEmail_postmark_fail', [
                'to' => $to,
                'subject' => $subject,
                'error_message' => $err,
                'curl_errno' => $errNo,
            ]);

            return [
                'success' => false,
                'error_message' => 'Postmark request failed: ' . $err,
                'error_info' => 'HTTP client error',
                'smtp_debug' => $debug ? trim($responseBody) : '',
            ];
        }
    } else {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", [
                    'Accept: application/json',
                    'Content-Type: application/json',
                    'X-Postmark-Server-Token: ' . $token,
                ]),
                'content' => $json,
                'timeout' => 30,
                'ignore_errors' => true,
            ],
        ]);
        $responseBody = @file_get_contents($url, false, $ctx);
        if ($responseBody === false) {
            proLog('sendEmail_postmark_fail', ['to' => $to, 'subject' => $subject, 'error_message' => 'file_get_contents failed']);

            return [
                'success' => false,
                'error_message' => 'Postmark request failed (no cURL)',
                'error_info' => 'file_get_contents failed',
            ];
        }
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $line) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                    $http = (int) $m[1];
                    break;
                }
            }
        }
    }

    $decoded = json_decode($responseBody, true);
    $msg = is_array($decoded) && isset($decoded['Message']) ? (string) $decoded['Message'] : '';
    $hasErrCode = is_array($decoded) && array_key_exists('ErrorCode', $decoded);
    $errCode = $hasErrCode ? $decoded['ErrorCode'] : null;

    if (is_array($decoded) && isset($decoded['MessageID'])) {
        $okCtx = [
            'to' => $to,
            'subject' => $subject,
            'message_id' => (string) $decoded['MessageID'],
            'from' => $fromEmail,
        ];
        if (isset($decoded['SubmittedAt'])) {
            $okCtx['submitted_at'] = (string) $decoded['SubmittedAt'];
        }
        proLog('sendEmail_postmark_ok', $okCtx);

        return true;
    }

    $errorInfo = $msg !== '' ? $msg : trim($responseBody);
    if ($hasErrCode) {
        $errorInfo = 'ErrorCode ' . $errCode . ($msg !== '' ? ': ' . $msg : '');
    }

    proLog('sendEmail_postmark_fail', [
        'to' => $to,
        'subject' => $subject,
        'http' => $http,
        'error_message' => $msg !== '' ? $msg : 'Postmark error',
        'error_info' => $errorInfo,
    ]);

    $out = [
        'success' => false,
        'error_message' => $msg !== '' ? $msg : ('Postmark HTTP ' . ($http > 0 ? $http : 'error')),
        'error_info' => $errorInfo,
    ];
    if ($debug && $responseBody !== '') {
        $out['smtp_debug'] = trim($responseBody);
    }

    return $out;
}
