<?php
/**
 * Gmail API OAuth client — token management and outbound sending (invites).
 */

use Google\Client;
use Google\Service\Exception as GoogleServiceException;
use Google\Service\Gmail;
use Google\Service\Gmail\Message;

class GmailService
{
    private Client $client;

    public function __construct()
    {
        if (!class_exists(Client::class)) {
            throw new RuntimeException('Google API client not installed. Run: composer install');
        }

        $this->client = new Client();
        $this->client->setClientId(GOOGLE_CLIENT_ID);
        $this->client->setClientSecret(GOOGLE_CLIENT_SECRET);
        $this->client->setRedirectUri(GOOGLE_REDIRECT_URI);
        $this->client->setAccessType('offline');
        $this->client->setPrompt('select_account consent');
        $this->client->addScope([
            Gmail::GMAIL_READONLY,
            Gmail::GMAIL_MODIFY,
            Gmail::MAIL_GOOGLE_COM,
        ]);
    }

    public function getAuthUrl(): string
    {
        return $this->client->createAuthUrl();
    }

    public function fetchAccessToken(string $authCode): array
    {
        $accessToken = $this->client->fetchAccessTokenWithAuthCode($authCode);
        if (isset($accessToken['error'])) {
            throw new RuntimeException('OAuth token exchange failed: ' . ($accessToken['error_description'] ?? $accessToken['error']));
        }

        $formattedToken = [
            'access_token' => $accessToken['access_token'],
            'expires_in' => $accessToken['expires_in'],
            'refresh_token' => $accessToken['refresh_token'] ?? null,
            'scope' => $accessToken['scope'] ?? '',
            'token_type' => $accessToken['token_type'] ?? 'Bearer',
            'created' => $accessToken['created'] ?? time(),
        ];

        csSaveGmailToken($formattedToken);
        $this->client->setAccessToken($formattedToken);

        return $formattedToken;
    }

    public function setAccessTokenFromDB(): void
    {
        $tokenRecord = csGetGmailToken();
        if (!$tokenRecord) {
            throw new RuntimeException('No Gmail OAuth token found. Complete OAuth setup first.');
        }

        $tokenData = json_decode($tokenRecord['token_data'], true);
        if (!is_array($tokenData)) {
            throw new RuntimeException('Invalid Gmail token data in database.');
        }

        $this->client->setAccessToken($tokenData);
        $this->refreshTokenIfNeeded((int) $tokenRecord['id'], $tokenData);
    }

    public function refreshTokenIfNeeded(?int $tokenId = null, ?array $tokenData = null): void
    {
        if (!$this->client->isAccessTokenExpired()) {
            return;
        }

        $tokenRecord = csGetGmailToken();
        if (!$tokenRecord) {
            throw new RuntimeException('No Gmail OAuth token found for refresh.');
        }

        $tokenId = $tokenId ?? (int) $tokenRecord['id'];
        $tokenData = $tokenData ?? json_decode($tokenRecord['token_data'], true);
        if (empty($tokenData['refresh_token'])) {
            throw new RuntimeException('Gmail refresh token missing. Re-authorize via OAuth setup.');
        }

        $newAccessToken = $this->client->fetchAccessTokenWithRefreshToken($tokenData['refresh_token']);
        if (isset($newAccessToken['error'])) {
            throw new RuntimeException('Token refresh failed: ' . ($newAccessToken['error_description'] ?? $newAccessToken['error']));
        }

        $formattedToken = [
            'access_token' => $newAccessToken['access_token'],
            'expires_in' => $newAccessToken['expires_in'],
            'refresh_token' => $newAccessToken['refresh_token'] ?? $tokenData['refresh_token'],
            'scope' => $newAccessToken['scope'] ?? ($tokenData['scope'] ?? ''),
            'token_type' => $newAccessToken['token_type'] ?? 'Bearer',
            'created' => $newAccessToken['created'] ?? time(),
        ];

        $pdo = getDBConnection();
        $pdo->prepare('UPDATE cs_gmail_tokens SET token_data = :t WHERE id = :id')
            ->execute([':t' => json_encode($formattedToken), ':id' => $tokenId]);
        $this->client->setAccessToken($formattedToken);
    }

    public function sendEmail(string $to, string $subject, string $htmlBody, ?string $from = null): void
    {
        $this->setAccessTokenFromDB();

        $from = $from ?? SMTP_FROM_EMAIL;
        $rawMessage = $this->buildRawMessage($from, $to, $subject, $htmlBody);

        $service = new Gmail($this->client);
        $message = new Message();
        $message->setRaw($rawMessage);

        try {
            $service->users_messages->send('me', $message);
        } catch (GoogleServiceException $e) {
            throw new RuntimeException('Gmail API send failed: ' . $e->getMessage(), 0, $e);
        }
    }

    private function buildRawMessage(string $from, string $to, string $subject, string $htmlBody): string
    {
        $fromName = defined('SMTP_FROM_NAME') ? (string) SMTP_FROM_NAME : 'Savvy CFO Portal';
        $boundary = uniqid('boundary_');

        $raw = "From: {$fromName} <{$from}>\r\n";
        $raw .= "To: {$to}\r\n";
        $raw .= "Subject: {$subject}\r\n";
        $raw .= "MIME-Version: 1.0\r\n";
        $raw .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n\r\n";
        $raw .= "--{$boundary}\r\n";
        $raw .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
        $raw .= $htmlBody . "\r\n";
        $raw .= "--{$boundary}--";

        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
