<?php

$phpmailer_paths = [
    __DIR__ . '/../public/phpmailer/phpmailer/src/PHPMailer.php',
    __DIR__ . '/../public/vendor/phpmailer/phpmailer/src/PHPMailer.php',
    __DIR__ . '/../vendor/phpmailer/phpmailer/src/PHPMailer.php',
];

$phpmailer_loaded = false;
foreach ($phpmailer_paths as $path) {
    if (file_exists($path)) {
        require_once $path;
        require_once dirname($path) . '/SMTP.php';
        require_once dirname($path) . '/Exception.php';
        $phpmailer_loaded = true;
        break;
    }
}

function sendEmail($to, $subject, $body) {
    global $phpmailer_loaded;

    error_log('sendEmail called for: ' . $to);

    // Use PHPMailer whenever the class is available (Composer autoload loads it even if manual paths above missed).
    if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        $headers = [
            'MIME-Version: 1.0',
            'Content-type: text/html; charset=UTF-8',
            'From: ' . SMTP_FROM_NAME . ' <' . SMTP_FROM_EMAIL . '>',
            'Reply-To: ' . SMTP_FROM_EMAIL,
            'X-Mailer: PHP/' . phpversion(),
        ];
        $result = mail($to, $subject, $body, implode("\r\n", $headers));

        return $result ? true : [
            'success' => false,
            'error_message' => 'PHP mail() function failed',
            'error_info' => 'Server mail configuration may be incorrect',
        ];
    }

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

    try {
        $mail->CharSet = 'UTF-8';
        $mail->SMTPDebug = 0;
        $mail->Timeout = 30;
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USERNAME;
        $mail->Password = SMTP_PASSWORD;
        $mail->Port = (int) SMTP_PORT;
        // Map config strings to PHPMailer constants (avoids subtle mismatches on some PHP/OpenSSL builds).
        $sec = strtolower(trim((string) SMTP_SECURE));
        if ($sec === 'ssl' || $sec === 'smtps') {
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($sec === 'tls' || $sec === 'starttls') {
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = SMTP_SECURE;
        }
        if (defined('SMTP_HELO_HOST') && SMTP_HELO_HOST !== '') {
            $mail->Hostname = SMTP_HELO_HOST;
        }
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ],
        ];
        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        // Align envelope sender with From (helps SPF/DMARC on strict hosts).
        if (defined('SMTP_ENVELOPE_FROM') && SMTP_ENVELOPE_FROM !== '') {
            $mail->Sender = SMTP_ENVELOPE_FROM;
        } else {
            $mail->Sender = SMTP_FROM_EMAIL;
        }
        $mail->addReplyTo(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $body;
        $plain = html_entity_decode(
            strip_tags(str_ireplace(['<br>', '<br/>', '<br />'], "\n", $body)),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );
        $mail->AltBody = trim($plain) !== '' ? trim($plain) : 'Please view this message in an HTML-capable email client.';
        $mail->send();

        return true;
    } catch (\Throwable $e) {
        error_log('PHPMailer sending failed: ' . $e->getMessage());

        return [
            'success' => false,
            'error_message' => $e->getMessage(),
            'error_info' => isset($mail->ErrorInfo) ? $mail->ErrorInfo : '',
        ];
    }
}
