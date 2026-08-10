<?php

require_once __DIR__ . '/pro_log.php';
require_once __DIR__ . '/ghl.php';

/**
 * Strip HTML to a plain-text fallback.
 */
function sendEmailHtmlToPlain(string $html): string
{
    $plain = html_entity_decode(
        strip_tags(str_ireplace(['<br>', '<br/>', '<br />'], "\n", $html)),
        ENT_QUOTES | ENT_HTML5,
        'UTF-8'
    );
    $plain = trim($plain);

    return $plain !== '' ? $plain : 'Please view this message in an HTML-capable email client.';
}

/**
 * Invitation email via Gmail API OAuth.
 *
 * @return true|array{success:false,error_message:string,error_info:string}
 */
function sendInviteEmail($to, $subject, $body)
{
    $to = trim((string) $to);
    $subject = (string) $subject;
    $body = (string) $body;

    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        proLog('sendInviteEmail_fail', [
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
    if ($fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        proLog('sendInviteEmail_fail', [
            'to' => $to,
            'subject' => $subject,
            'error_message' => 'Invalid SMTP_FROM_EMAIL',
        ]);

        return [
            'success' => false,
            'error_message' => 'Invalid From email in configuration',
            'error_info' => 'Set SMTP_FROM_EMAIL to the Gmail OAuth mailbox address.',
        ];
    }

    try {
        if (!class_exists('GmailService', false)) {
            require_once __DIR__ . '/GmailService.php';
        }
        proLog('sendInviteEmail_start', ['to' => $to, 'subject' => $subject]);
        $gmail = new GmailService();
        $gmail->sendEmail($to, $subject, $body, $fromEmail);
        proLog('sendInviteEmail_ok', ['to' => $to, 'subject' => $subject]);
        return true;
    } catch (Throwable $e) {
        error_log('Gmail invite send failed: ' . $e->getMessage());
        proLog('sendInviteEmail_fail', [
            'to' => $to,
            'subject' => $subject,
            'error_message' => $e->getMessage(),
        ]);

        return [
            'success' => false,
            'error_message' => 'Gmail send failed',
            'error_info' => $e->getMessage(),
        ];
    }
}

/**
 * Reminder email via GoHighLevel Conversations API.
 *
 * @return true|array{success:false,error_message:string,error_info:string}
 */
function sendReminderEmail($to, $subject, $body)
{
    $to = trim((string) $to);
    $subject = (string) $subject;
    $body = (string) $body;

    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        proLog('sendReminderEmail_fail', [
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

    return csSendGhlEmail($to, $subject, $body);
}

/**
 * @deprecated Use sendInviteEmail or sendReminderEmail. Kept for any legacy callers; routes to invite (Gmail).
 * @return true|array{success:false,error_message:string,error_info:string}
 */
function sendEmail($to, $subject, $body)
{
    return sendInviteEmail($to, $subject, $body);
}
