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
 * Public Savvy CFO logo URL for email headers.
 */
function csEmailLogoUrl(): string
{
    return 'https://savvycfo.com/wp-content/uploads/2023/06/SavvyCFO_logo_mainfinal-bluewhite_23Jun23.png';
}

/**
 * Members Area login URL for email CTAs.
 */
function csEmailLoginUrl(): string
{
    if (function_exists('membersAppUrl')) {
        return rtrim((string) membersAppUrl(), '/') . '/login';
    }
    $base = 'https://members.savvycfo.com';
    if (defined('MEMBERS_APP_URL')) {
        $u = trim((string) MEMBERS_APP_URL);
        if ($u !== '') {
            $base = rtrim($u, '/');
        }
    }

    return $base . '/login';
}

/**
 * Branded HTML email shell with Savvy CFO logo and optional CTA button.
 */
function csEmailHtmlWrap(
    string $heading,
    string $bodyHtml,
    string $ctaLabel,
    ?string $ctaUrl = null,
    ?string $footerNote = null,
    ?string $previewText = null
): string {
    $logo = htmlspecialchars(csEmailLogoUrl(), ENT_QUOTES, 'UTF-8');
    $headingSafe = htmlspecialchars($heading, ENT_QUOTES, 'UTF-8');
    $ctaLabelSafe = htmlspecialchars($ctaLabel, ENT_QUOTES, 'UTF-8');
    $ctaUrlSafe = htmlspecialchars($ctaUrl !== null && $ctaUrl !== '' ? $ctaUrl : csEmailLoginUrl(), ENT_QUOTES, 'UTF-8');
    $preview = htmlspecialchars($previewText ?? $heading, ENT_QUOTES, 'UTF-8');
    $year = date('Y');
    $footerExtra = $footerNote !== null && $footerNote !== ''
        ? '<p style="margin:8px 0 0 0;font-size:12px;line-height:1.5;color:#78716c;">'
            . htmlspecialchars($footerNote, ENT_QUOTES, 'UTF-8')
            . '</p>'
        : '';

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{$headingSafe}</title>
<!--[if mso]><noscript><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript><![endif]-->
</head>
<body style="margin:0;padding:0;background-color:#f3f6f9;font-family:Arial,Helvetica,sans-serif;color:#1F2937;">
<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;">{$preview}</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f3f6f9;padding:24px 12px;">
<tr>
<td align="center">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;background-color:#ffffff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;">
<tr>
<td style="background:linear-gradient(180deg,#0B58A3 0%,#0078d4 100%);background-color:#0B58A3;padding:20px 28px;text-align:left;">
<img src="{$logo}" alt="Savvy CFO" width="140" style="display:block;border:0;max-width:140px;height:auto;">
</td>
</tr>
<tr>
<td style="padding:28px 28px 8px 28px;">
<h1 style="margin:0 0 16px 0;font-family:Arial,Helvetica,sans-serif;font-size:22px;line-height:1.35;font-weight:700;color:#1F2937;">{$headingSafe}</h1>
<div style="font-size:15px;line-height:1.6;color:#1F2937;">
{$bodyHtml}
</div>
</td>
</tr>
<tr>
<td style="padding:8px 28px 28px 28px;">
<table role="presentation" cellpadding="0" cellspacing="0" border="0">
<tr>
<td align="center" bgcolor="#0B58A3" style="border-radius:8px;background-color:#0B58A3;">
<a href="{$ctaUrlSafe}" style="display:inline-block;padding:12px 22px;font-family:Arial,Helvetica,sans-serif;font-size:15px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:8px;">{$ctaLabelSafe}</a>
</td>
</tr>
</table>
<p style="margin:16px 0 0 0;font-size:12px;line-height:1.5;color:#6b7280;">
If the button does not work, copy and paste this link into your browser:<br>
<a href="{$ctaUrlSafe}" style="color:#0B58A3;word-break:break-all;">{$ctaUrlSafe}</a>
</p>
</td>
</tr>
<tr>
<td style="padding:18px 28px;border-top:1px solid #e5e7eb;background-color:#fafbfc;">
<p style="margin:0 0 6px 0;font-size:12px;line-height:1.5;color:#6b7280;">
The Savvy CFO Team<br>
Savvy Saver
</p>
{$footerExtra}
<p style="margin:8px 0 0 0;font-size:11px;line-height:1.4;color:#9ca3af;">
&copy; {$year} Savvy CFO. Savvy Saver helps you review potential charges — it does not cancel vendor services for you.
</p>
</td>
</tr>
</table>
</td>
</tr>
</table>
</body>
</html>
HTML;
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
