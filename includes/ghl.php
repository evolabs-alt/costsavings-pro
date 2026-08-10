<?php
/**
 * GoHighLevel Conversations email (reminders) + contact upsert.
 */

/**
 * @return array{ok:bool, status:int, body:string, json:?array}
 */
function csGhlRequest(string $method, string $path, ?array $payload = null): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'status' => 0, 'body' => 'curl not available', 'json' => null];
    }

    $token = '';
    if (defined('GHL_API_KEY') && trim((string) GHL_API_KEY) !== '') {
        $token = trim((string) GHL_API_KEY);
    } elseif (defined('GHL_API_TOKEN') && trim((string) GHL_API_TOKEN) !== '') {
        $token = trim((string) GHL_API_TOKEN);
    }
    if ($token === '') {
        return ['ok' => false, 'status' => 0, 'body' => 'GHL_API_KEY is not set', 'json' => null];
    }

    $base = defined('GHL_API_URL') ? (string) GHL_API_URL : 'https://services.leadconnectorhq.com';
    $url = rtrim($base, '/') . '/' . ltrim($path, '/');
    $version = defined('GHL_API_VERSION') ? (string) GHL_API_VERSION : '2021-07-28';

    $ch = curl_init($url);
    $headers = [
        'Authorization: Bearer ' . $token,
        'Version: ' . $version,
        'Accept: application/json',
        'Content-Type: application/json',
    ];
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_TIMEOUT => 30,
    ];
    if ($payload !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($payload);
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        return ['ok' => false, 'status' => $status, 'body' => $err !== '' ? $err : 'GHL request failed', 'json' => null];
    }

    $json = json_decode($body, true);

    $result = [
        'ok' => $status >= 200 && $status < 300,
        'status' => $status,
        'body' => (string) $body,
        'json' => is_array($json) ? $json : null,
        'method' => strtoupper($method),
        'path' => $path,
    ];
    $GLOBALS['cs_ghl_last_response'] = $result;

    return $result;
}

/** @return array{ok:bool,status:int,body:string,json:?array,method?:string,path?:string}|null */
function csGhlLastResponse(): ?array
{
    return isset($GLOBALS['cs_ghl_last_response']) && is_array($GLOBALS['cs_ghl_last_response'])
        ? $GLOBALS['cs_ghl_last_response']
        : null;
}

/**
 * Upsert contact with Cost Savings Pro tag. Returns contact id or null.
 *
 * @param array{email:string, first_name?:string, last_name?:string} $contact
 */
function csUpsertGhlContact(array $contact): ?string
{
    $locationId = defined('GHL_LOCATION_ID') ? trim((string) GHL_LOCATION_ID) : '';
    if ($locationId === '') {
        error_log('GHL upsert failed: GHL_LOCATION_ID is not set');
        return null;
    }

    $email = trim((string) ($contact['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        error_log('GHL upsert failed: invalid email');
        return null;
    }

    $payload = [
        'email' => $email,
        'locationId' => $locationId,
        'firstName' => (string) ($contact['first_name'] ?? ''),
        'lastName' => (string) ($contact['last_name'] ?? ''),
        'tags' => ['Cost Savings Pro'],
    ];

    $res = csGhlRequest('POST', 'contacts/upsert', $payload);
    if (!$res['ok']) {
        error_log('GHL upsert failed HTTP ' . $res['status'] . ': ' . $res['body']);
        if (function_exists('proLog')) {
            proLog('ghl_upsert_fail', ['email' => $email, 'status' => $res['status'], 'body' => $res['body']]);
        }
        return null;
    }

    $row = $res['json']['contact'] ?? $res['json'] ?? null;
    $id = is_array($row) ? ($row['id'] ?? null) : null;
    if (!is_string($id) || $id === '') {
        error_log('GHL upsert: contact id missing: ' . $res['body']);
        return null;
    }

    return $id;
}

/**
 * Send reminder email via GHL Conversations API.
 *
 * @return true|array{success:false,error_message:string,error_info:string}
 */
function csSendGhlEmail(string $to, string $subject, string $htmlBody, string $firstName = '', string $lastName = '')
{
    $contactId = csUpsertGhlContact([
        'email' => $to,
        'first_name' => $firstName,
        'last_name' => $lastName,
    ]);
    if ($contactId === null) {
        return [
            'success' => false,
            'error_message' => 'GHL contact upsert failed',
            'error_info' => 'Check GHL_API_KEY, GHL_LOCATION_ID, and API scopes (contacts).',
        ];
    }

    $fromEmail = defined('GHL_FROM_EMAIL') ? trim((string) GHL_FROM_EMAIL) : 'no-reply@savvycfo.com';

    $payload = [
        'type' => 'Email',
        'contactId' => $contactId,
        'emailFrom' => $fromEmail,
        'emailTo' => $to,
        'subject' => $subject,
        'html' => $htmlBody,
        'message' => trim(strip_tags(str_ireplace(['<br>', '<br/>', '<br />'], "\n", $htmlBody))),
    ];

    $res = csGhlRequest('POST', 'conversations/messages', $payload);
    if (!$res['ok']) {
        error_log('GHL send failed HTTP ' . $res['status'] . ': ' . $res['body']);
        if (function_exists('proLog')) {
            proLog('ghl_send_fail', [
                'to' => $to,
                'subject' => $subject,
                'status' => $res['status'],
                'body' => $res['body'],
            ]);
        }
        return [
            'success' => false,
            'error_message' => 'GHL Conversations send failed',
            'error_info' => 'HTTP ' . $res['status'] . ': ' . (function_exists('mb_substr') ? mb_substr($res['body'], 0, 300) : substr($res['body'], 0, 300)),
        ];
    }

    if (function_exists('proLog')) {
        proLog('ghl_send_ok', ['to' => $to, 'subject' => $subject, 'contact_id' => $contactId]);
    }

    return true;
}
