<?php

namespace CostSavings;

/**
 * QuickBooks Online OAuth2 + Transaction List report pull for vendor import.
 *
 * Payment / security posture (Intuit app listing attestations):
 * - Does not automate merchant application authorization UI (user completes Intuit OAuth in browser).
 * - Does not request or store the user's Intuit user ID (no OpenID `openid`/`profile` scopes; no `sub`/`userid`).
 * - Encrypts refresh tokens at rest (AES-256-GCM). App Client ID/Secret live in config, not per-org UI.
 * - Holds access tokens in process memory only; they are never written to DB, disk, or session.
 */
class QboService
{
    public const ENV_SANDBOX = 'sandbox';
    public const ENV_PRODUCTION = 'production';
    /** Accounting API only — no OpenID / payments / merchant scopes. */
    public const SCOPE = 'com.intuit.quickbooks.accounting';
    public const MAX_RANGE_MONTHS = 24;
    public const CACHE_TTL_SECONDS = 3600;

    private const ENC_PREFIX = 'qboenc:v1:';

    /**
     * Access tokens live only in request/process memory (volatile). Never persisted.
     * @var array<int, array{access_token:string, expires_at:int, token_type:string, scope:string}>
     */
    private static array $accessTokenMemory = [];

    private \PDO $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Global Intuit app credentials from config (same for every customer company).
     *
     * @return array{client_id:string, client_secret:string, environment:string}
     */
    public static function appCredentials(): array
    {
        $clientId = defined('QBO_CLIENT_ID') ? trim((string) QBO_CLIENT_ID) : '';
        $clientSecret = defined('QBO_CLIENT_SECRET') ? trim((string) QBO_CLIENT_SECRET) : '';
        $env = defined('QBO_ENVIRONMENT') ? (string) QBO_ENVIRONMENT : self::ENV_PRODUCTION;

        return [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'environment' => self::normalizeEnvironment($env),
        ];
    }

    public static function hasAppCredentials(): bool
    {
        $c = self::appCredentials();

        return $c['client_id'] !== '' && $c['client_secret'] !== '';
    }

    public static function redirectUri(): string
    {
        if (defined('QBO_REDIRECT_URI')) {
            $u = trim((string) QBO_REDIRECT_URI);
            if ($u !== '') {
                return $u;
            }
        }

        if (function_exists('publicAppBaseUrl')) {
            return rtrim(publicAppBaseUrl(), '/') . '/index.php?page=qbo-callback';
        }

        $base = defined('BASE_URL') ? rtrim((string) BASE_URL, '/') : '';

        return $base . '/index.php?page=qbo-callback';
    }

    /**
     * Org connection row merged with global app credentials.
     *
     * @return array{
     *   org_id:int,
     *   environment:string,
     *   client_id:string,
     *   client_secret:?string,
     *   realm_id:?string,
     *   company_name:?string,
     *   token_data:?array,
     *   connected_at:?string,
     *   updated_at:?string,
     *   updated_by:?int
     * }
     */
    public function getConnection(int $orgId): array
    {
        $app = self::appCredentials();
        $st = $this->pdo->prepare(
            'SELECT org_id, realm_id, company_name, token_data, connected_at, updated_at, updated_by
             FROM org_qbo_connections WHERE org_id = ? LIMIT 1'
        );
        $st->execute([$orgId]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);

        $tokens = null;
        if ($row && !empty($row['token_data'])) {
            $tokens = self::decodeStoredTokenPayload((string) $row['token_data']);
        }

        return [
            'org_id' => $orgId,
            'environment' => $app['environment'],
            'client_id' => $app['client_id'],
            'client_secret' => $app['client_secret'] !== '' ? $app['client_secret'] : null,
            // Company realm (not Intuit end-user ID). Required for Accounting API company context.
            'realm_id' => ($row && isset($row['realm_id']) && $row['realm_id'] !== '')
                ? (string) $row['realm_id']
                : null,
            'company_name' => ($row && isset($row['company_name']) && $row['company_name'] !== '')
                ? (string) $row['company_name']
                : null,
            'token_data' => $tokens,
            'connected_at' => $row['connected_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
            'updated_by' => isset($row['updated_by']) ? (int) $row['updated_by'] : null,
        ];
    }

    /**
     * @return array{connected:bool,has_credentials:bool,company_name:?string,environment:string,client_id_masked:string,redirect_uri:string}
     */
    public function connectionStatus(int $orgId): array
    {
        $conn = $this->getConnection($orgId);
        $hasCreds = self::hasAppCredentials();
        $tokens = $conn['token_data'] ?? null;
        $connected = $hasCreds
            && !empty($conn['realm_id'])
            && is_array($tokens)
            && !empty($tokens['refresh_token']);

        $clientId = $conn['client_id'];
        $masked = $clientId;
        if (strlen($clientId) > 8) {
            $masked = substr($clientId, 0, 4) . '…' . substr($clientId, -4);
        }

        return [
            'connected' => $connected,
            'has_credentials' => $hasCreds,
            'company_name' => $conn['company_name'] ?? null,
            'environment' => $conn['environment'] ?? self::ENV_PRODUCTION,
            'client_id_masked' => $masked,
            'redirect_uri' => self::redirectUri(),
        ];
    }

    public function isConnected(int $orgId): bool
    {
        return $this->connectionStatus($orgId)['connected'];
    }

    /** Ensure a row exists so OAuth callback can attach tokens. */
    public function ensureOrgRow(int $orgId, int $userId): void
    {
        $st = $this->pdo->prepare(
            'INSERT INTO org_qbo_connections (org_id, updated_by)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE updated_by = VALUES(updated_by)'
        );
        $st->execute([$orgId, $userId]);
    }

    public function disconnect(int $orgId, int $userId, bool $keepCredentials = true): void
    {
        unset(self::$accessTokenMemory[$orgId]);
        // App credentials live in config; only clear this org's company connection tokens.
        $st = $this->pdo->prepare(
            'UPDATE org_qbo_connections
             SET realm_id = NULL, company_name = NULL, token_data = NULL, connected_at = NULL, updated_by = ?
             WHERE org_id = ?'
        );
        $st->execute([$userId, $orgId]);
        if (!$keepCredentials) {
            // Column leftovers unused with global app credentials; full delete optional.
            $del = $this->pdo->prepare('DELETE FROM org_qbo_connections WHERE org_id = ?');
            $del->execute([$orgId]);
        }
    }

    /**
     * @return array{auth_url:string,state:string}
     */
    /**
     * @param array{resume_wizard?:bool,project_id?:int,project_name?:string} $resume
     * @return array{auth_url:string,state:string}
     */
    public function beginOAuth(int $orgId, int $userId = 0, array $resume = []): array
    {
        if (!self::hasAppCredentials()) {
            throw new \RuntimeException(
                'QuickBooks app is not configured. Set QBO_CLIENT_ID and QBO_CLIENT_SECRET in config.php.'
            );
        }
        $app = self::appCredentials();
        if ($userId > 0) {
            $this->ensureOrgRow($orgId, $userId);
        }

        $state = bin2hex(random_bytes(24));
        $_SESSION['qbo_oauth_state'] = $state;
        $_SESSION['qbo_oauth_org_id'] = $orgId;
        // Durable pending OAuth (Intuit return often drops PHP session cookies).
        self::storeOAuthPending($state, $orgId, $userId, $resume);

        $params = http_build_query([
            'client_id' => $app['client_id'],
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'redirect_uri' => self::redirectUri(),
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);

        return [
            'auth_url' => 'https://appcenter.intuit.com/connect/oauth2?' . $params,
            'state' => $state,
        ];
    }

    /**
     * @return array{org_id:int,user_id:int,resume_wizard:bool,project_id:int,project_name:string}
     */
    public function handleCallback(string $code, string $realmId, string $state): array
    {
        $sessionState = (string) ($_SESSION['qbo_oauth_state'] ?? '');
        $sessionOrgId = (int) ($_SESSION['qbo_oauth_org_id'] ?? 0);
        unset($_SESSION['qbo_oauth_state'], $_SESSION['qbo_oauth_org_id']);

        $pending = self::consumeOAuthPending($state);
        $orgId = 0;
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        $resumeWizard = false;
        $projectId = 0;
        $projectName = '';

        if (is_array($pending)) {
            $orgId = (int) ($pending['org_id'] ?? 0);
            if ($userId <= 0) {
                $userId = (int) ($pending['user_id'] ?? 0);
            }
            $resumeWizard = !empty($pending['resume_wizard']);
            $projectId = (int) ($pending['project_id'] ?? 0);
            $projectName = (string) ($pending['project_name'] ?? '');
        } elseif ($sessionState !== '' && hash_equals($sessionState, $state) && $sessionOrgId > 0) {
            $orgId = $sessionOrgId;
        } else {
            throw new \RuntimeException('Invalid or expired OAuth state. Sign in, then use Connect to QuickBooks again.');
        }

        if ($orgId <= 0) {
            throw new \RuntimeException('OAuth session expired. Sign in, then use Connect to QuickBooks again.');
        }
        if ($code === '' || $realmId === '') {
            throw new \RuntimeException('Missing authorization code or company id (realmId).');
        }
        if (!self::hasAppCredentials()) {
            throw new \RuntimeException('QuickBooks app credentials are not configured in config.php.');
        }

        $app = self::appCredentials();
        $this->ensureOrgRow($orgId, $userId > 0 ? $userId : 0);

        $tokenPayload = $this->exchangeAuthorizationCode(
            $app['client_id'],
            $app['client_secret'],
            $code
        );

        $tokenData = $this->normalizeTokenPayload($tokenPayload, null);
        // Volatile only — never persist access_token.
        $this->rememberAccessToken($orgId, $tokenData);

        $companyName = null;
        try {
            $companyName = $this->fetchCompanyName(
                $app['environment'],
                $realmId,
                (string) $tokenData['access_token']
            );
        } catch (\Throwable $e) {
            error_log('QboService company name: ' . $e->getMessage());
        }

        // Persist company realm + encrypted refresh token only (no Intuit user ID, no access token).
        $st = $this->pdo->prepare(
            'UPDATE org_qbo_connections
             SET realm_id = ?, company_name = ?, token_data = ?, connected_at = NOW(), updated_by = ?
             WHERE org_id = ?'
        );
        $st->execute([
            $realmId,
            $companyName,
            self::encodeStoredTokenPayload($tokenData),
            $userId > 0 ? $userId : null,
            $orgId,
        ]);

        return [
            'org_id' => $orgId,
            'user_id' => $userId,
            'resume_wizard' => $resumeWizard,
            'project_id' => $projectId,
            'project_name' => $projectName,
        ];
    }

    /**
     * Persist OAuth CSRF/org binding outside PHP session (survives Intuit redirect).
     *
     * @param array{resume_wizard?:bool,project_id?:int,project_name?:string} $resume
     */
    public static function storeOAuthPending(string $state, int $orgId, int $userId, array $resume = []): void
    {
        if (!preg_match('/^[a-f0-9]{32,64}$/', $state) || $orgId <= 0 || !defined('CACHE_DIR')) {
            return;
        }
        $dir = rtrim((string) CACHE_DIR, '/\\') . DIRECTORY_SEPARATOR;
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            error_log('QboService: cannot write OAuth pending cache');
            return;
        }
        $path = $dir . 'qbo_oauth_' . $state . '.json';
        $payload = json_encode([
            'org_id' => $orgId,
            'user_id' => $userId,
            'created_at' => time(),
            'resume_wizard' => !empty($resume['resume_wizard']),
            'project_id' => (int) ($resume['project_id'] ?? 0),
            'project_name' => (string) ($resume['project_name'] ?? ''),
        ]);
        if ($payload !== false) {
            @file_put_contents($path, $payload, LOCK_EX);
        }
    }

    /**
     * @return array{org_id:int,user_id:int,resume_wizard:bool,project_id:int,project_name:string}|null
     */
    public static function consumeOAuthPending(string $state): ?array
    {
        if (!preg_match('/^[a-f0-9]{32,64}$/', $state) || !defined('CACHE_DIR')) {
            return null;
        }
        $path = rtrim((string) CACHE_DIR, '/\\') . DIRECTORY_SEPARATOR . 'qbo_oauth_' . $state . '.json';
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        @unlink($path);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }
        $created = (int) ($data['created_at'] ?? 0);
        // 30 minutes; Intuit auth codes are short-lived.
        if ($created <= 0 || (time() - $created) > 1800) {
            return null;
        }
        $orgId = (int) ($data['org_id'] ?? 0);
        if ($orgId <= 0) {
            return null;
        }

        return [
            'org_id' => $orgId,
            'user_id' => (int) ($data['user_id'] ?? 0),
            'resume_wizard' => !empty($data['resume_wizard']),
            'project_id' => (int) ($data['project_id'] ?? 0),
            'project_name' => (string) ($data['project_name'] ?? ''),
        ];
    }

    /**
     * Returns a live access token from process memory, refreshing via encrypted refresh_token as needed.
     * Access tokens are never written to durable storage.
     *
     * @return array{access_token:string, refresh_token:string, expires_in:int, created:int, token_type:string, scope:string}
     */
    public function ensureAccessToken(int $orgId): array
    {
        $conn = $this->getConnection($orgId);
        if (empty($conn['realm_id']) || empty($conn['client_id']) || empty($conn['client_secret'])) {
            throw new \RuntimeException('QuickBooks is not connected for this organization.');
        }
        $tokens = $conn['token_data'];
        if (!is_array($tokens) || empty($tokens['refresh_token'])) {
            throw new \RuntimeException('QuickBooks is not connected. Complete OAuth in Settings.');
        }

        $mem = self::$accessTokenMemory[$orgId] ?? null;
        if (is_array($mem)
            && !empty($mem['access_token'])
            && (int) ($mem['expires_at'] ?? 0) > (time() + 120)
        ) {
            return [
                'access_token' => (string) $mem['access_token'],
                'refresh_token' => (string) $tokens['refresh_token'],
                'expires_in' => max(0, (int) $mem['expires_at'] - time()),
                'created' => time(),
                'token_type' => (string) ($mem['token_type'] ?? 'bearer'),
                'scope' => (string) ($mem['scope'] ?? self::SCOPE),
            ];
        }

        $refreshed = $this->refreshAccessToken(
            $conn['client_id'],
            (string) $conn['client_secret'],
            (string) $tokens['refresh_token']
        );
        $tokenData = $this->normalizeTokenPayload($refreshed, $tokens);
        $this->rememberAccessToken($orgId, $tokenData);

        // Persist only encrypted refresh token (and non-secret metadata). Never access_token.
        $st = $this->pdo->prepare('UPDATE org_qbo_connections SET token_data = ? WHERE org_id = ?');
        $st->execute([self::encodeStoredTokenPayload($tokenData), $orgId]);

        return $tokenData;
    }

    /**
     * @return array<int, array{vendor_name:string,transaction_date:string,amount:float,transaction_type:string,account:string,memo:string}>
     */
    public function fetchTransactionRows(int $orgId, string $startDate, string $endDate): array
    {
        $this->assertValidDateRange($startDate, $endDate);
        $conn = $this->getConnection($orgId);
        if (empty($conn['realm_id'])) {
            throw new \RuntimeException('QuickBooks is not connected.');
        }
        $tokens = $this->ensureAccessToken($orgId);
        $apiBase = $conn['environment'] === self::ENV_SANDBOX
            ? 'https://sandbox-quickbooks.api.intuit.com'
            : 'https://quickbooks.api.intuit.com';
        $realm = rawurlencode((string) $conn['realm_id']);
        $authHeaders = [
            'Authorization: Bearer ' . $tokens['access_token'],
            'Accept: application/json',
        ];

        // Prefer General Ledger (grouped by CoA / GL account, like CSV "Transaction Detail by Account").
        // Fall back to Transaction List with Split (expense/GL side) preferred over bank Account.
        $attempts = [
            [
                'path' => 'GeneralLedger',
                'query' => [
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'minorversion' => '65',
                ],
            ],
            [
                'path' => 'GeneralLedger',
                'query' => [
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'accounting_method' => 'Accrual',
                    'minorversion' => '65',
                ],
            ],
            [
                'path' => 'GeneralLedger',
                'query' => [
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'accounting_method' => 'Cash',
                    'minorversion' => '65',
                ],
            ],
            [
                'path' => 'TransactionList',
                'query' => [
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'columns' => 'tx_date,txn_type,name,memo,split_acc,account_name,nat_amount',
                    'minorversion' => '65',
                ],
            ],
            [
                'path' => 'TransactionListWithSplits',
                'query' => [
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'minorversion' => '65',
                ],
            ],
            [
                'path' => 'TransactionList',
                'query' => [
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'minorversion' => '65',
                ],
            ],
            [
                'path' => 'TransactionList',
                'query' => [
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'accounting_method' => 'Accrual',
                    'minorversion' => '65',
                ],
            ],
            [
                'path' => 'TransactionList',
                'query' => [
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'accounting_method' => 'Cash',
                    'minorversion' => '65',
                ],
            ],
            [
                'path' => 'TransactionListByVendor',
                'query' => [
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'minorversion' => '65',
                ],
            ],
        ];

        $lastError = null;
        $bestRows = [];
        foreach ($attempts as $attempt) {
            $path = (string) $attempt['path'];
            $preferGl = $this->reportGroupBy($path) === 'account'
                || $path === 'TransactionListWithSplits'
                || (
                    isset($attempt['query']['columns'])
                    && is_string($attempt['query']['columns'])
                    && str_contains($attempt['query']['columns'], 'split_acc')
                );
            try {
                $qs = http_build_query($attempt['query'], '', '&', PHP_QUERY_RFC3986);
                $url = $apiBase . '/v3/company/' . $realm . '/reports/' . $path . '?' . $qs;
                $report = $this->httpJson('GET', $url, $authHeaders);
                if (!is_array($report)) {
                    continue;
                }
                if ($this->reportHasNoData($report)) {
                    continue;
                }
                $rows = $this->mapReportToRawRows($report, $path);
                if (count($rows) > count($bestRows)) {
                    $bestRows = $rows;
                }
                // Stop on first successful GL-oriented report; keep scanning weaker fallbacks.
                if (count($rows) > 0 && $preferGl) {
                    return $rows;
                }
            } catch (\Throwable $e) {
                $lastError = $e;
                error_log('QboService report ' . $path . ': ' . $e->getMessage());
            }
        }

        if (count($bestRows) > 0) {
            return $bestRows;
        }
        if ($lastError !== null) {
            throw new \RuntimeException(
                'Could not read QuickBooks transactions: ' . $lastError->getMessage()
            );
        }

        return [];
    }

    /**
     * @param array<string, mixed> $report
     */
    private function reportHasNoData(array $report): bool
    {
        $options = $report['Header']['Option'] ?? null;
        if (!is_array($options)) {
            return false;
        }
        // Single Option object vs list
        if (isset($options['Name'])) {
            $options = [$options];
        }
        foreach ($options as $opt) {
            if (!is_array($opt)) {
                continue;
            }
            if (strtolower((string) ($opt['Name'] ?? '')) === 'noreportdata'
                && strtolower((string) ($opt['Value'] ?? '')) === 'true'
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array{vendor_name:string,transaction_date:string,amount:float,transaction_type:string,account:string,memo:string}> $rows
     * @return array<int, array{name:string, transaction_count:int}>
     */
    public static function listAccountsFromRows(array $rows): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $acct = trim((string) ($row['account'] ?? ''));
            if ($acct === '') {
                $acct = '(No account)';
            }
            if (!isset($counts[$acct])) {
                $counts[$acct] = 0;
            }
            $counts[$acct]++;
        }
        ksort($counts, SORT_NATURAL | SORT_FLAG_CASE);
        $out = [];
        foreach ($counts as $name => $count) {
            $out[] = ['name' => $name, 'transaction_count' => $count];
        }

        return $out;
    }

    /**
     * @param array<int, array{vendor_name:string,transaction_date:string,amount:float,transaction_type:string,account:string,memo:string}> $rows
     * @param array<int, string> $selectedAccounts
     * @return array<int, array{vendor_name:string,transaction_date:string,amount:float,transaction_type:string,account:string,memo:string}>
     */
    public static function filterRowsByAccounts(array $rows, array $selectedAccounts): array
    {
        $filter = [];
        foreach ($selectedAccounts as $a) {
            $key = trim((string) $a);
            if ($key !== '') {
                $filter[$key] = true;
            }
        }
        if (count($filter) === 0) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $acct = trim((string) ($row['account'] ?? ''));
            if ($acct === '') {
                $acct = '(No account)';
            }
            if (isset($filter[$acct])) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @param array<int, array{vendor_name:string,transaction_date:string,amount:float,transaction_type:string,account:string,memo:string}> $rawRows
     * @return array{
     *   summary: array<int, array{vendor_name:string,cost_per_period:float,frequency:string,annual_cost:float,last_payment_date:?string}>,
     *   raw: array
     * }
     */
    public static function buildImportPayload(array $rawRows): array
    {
        $payeeRows = [];
        foreach ($rawRows as $row) {
            $vendor = trim((string) ($row['vendor_name'] ?? ''));
            if ($vendor === '') {
                continue;
            }
            if (!isset($payeeRows[$vendor])) {
                $payeeRows[$vendor] = [];
            }
            $payeeRows[$vendor][] = [
                'date' => $row['transaction_date'],
                'amount' => $row['amount'],
                'account' => $row['account'] ?? '',
            ];
        }

        $summary = [];
        foreach ($payeeRows as $payee => $rows) {
            if (count($rows) > 0) {
                $summary[] = CsvImport::buildVendorSummary($payee, $rows);
            }
        }

        return ['summary' => $summary, 'raw' => $rawRows];
    }

    /**
     * @param array<int, array{vendor_name:string,transaction_date:string,amount:float,transaction_type:string,account:string,memo:string}> $rows
     */
    public static function writeSyncCache(int $orgId, int $userId, array $rows, string $startDate, string $endDate): string
    {
        if (!defined('CACHE_DIR')) {
            throw new \RuntimeException('CACHE_DIR is not configured.');
        }
        $dir = rtrim((string) CACHE_DIR, '/\\') . DIRECTORY_SEPARATOR;
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('Unable to create cache directory.');
        }

        $token = bin2hex(random_bytes(16));
        $key = 'qbo_sync_' . $orgId . '_' . $userId . '_' . $token;
        $path = $dir . $key . '.json';
        $payload = [
            'created_at' => time(),
            'org_id' => $orgId,
            'user_id' => $userId,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'rows' => $rows,
        ];
        $json = json_encode($payload);
        if ($json === false || file_put_contents($path, $json) === false) {
            throw new \RuntimeException('Unable to stage QuickBooks sync data.');
        }

        return $key;
    }

    /**
     * @return array{
     *   created_at:int,
     *   org_id:int,
     *   user_id:int,
     *   start_date:string,
     *   end_date:string,
     *   rows: array<int, array{vendor_name:string,transaction_date:string,amount:float,transaction_type:string,account:string,memo:string}>
     * }|null
     */
    public static function readSyncCache(string $key, int $orgId, int $userId): ?array
    {
        if (!preg_match('/^qbo_sync_\d+_\d+_[a-f0-9]{32}$/', $key)) {
            return null;
        }
        if (!defined('CACHE_DIR')) {
            return null;
        }
        $path = rtrim((string) CACHE_DIR, '/\\') . DIRECTORY_SEPARATOR . $key . '.json';
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }
        if ((int) ($data['org_id'] ?? 0) !== $orgId || (int) ($data['user_id'] ?? 0) !== $userId) {
            return null;
        }
        $created = (int) ($data['created_at'] ?? 0);
        if ($created <= 0 || (time() - $created) > self::CACHE_TTL_SECONDS) {
            @unlink($path);
            return null;
        }
        if (!isset($data['rows']) || !is_array($data['rows'])) {
            return null;
        }

        return $data;
    }

    public static function deleteSyncCache(string $key): void
    {
        if (!preg_match('/^qbo_sync_\d+_\d+_[a-f0-9]{32}$/', $key) || !defined('CACHE_DIR')) {
            return;
        }
        $path = rtrim((string) CACHE_DIR, '/\\') . DIRECTORY_SEPARATOR . $key . '.json';
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public static function normalizeEnvironment(string $environment): string
    {
        $e = strtolower(trim($environment));
        return $e === self::ENV_SANDBOX ? self::ENV_SANDBOX : self::ENV_PRODUCTION;
    }

    public static function assertValidDateRange(string $startDate, string $endDate): void
    {
        $start = \DateTimeImmutable::createFromFormat('Y-m-d', $startDate);
        $end = \DateTimeImmutable::createFromFormat('Y-m-d', $endDate);
        if ($start === false || $end === false) {
            throw new \InvalidArgumentException('Dates must be YYYY-MM-DD.');
        }
        if ($start > $end) {
            throw new \InvalidArgumentException('Start date must be on or before end date.');
        }
        $limit = $start->modify('+' . self::MAX_RANGE_MONTHS . ' months');
        if ($end > $limit) {
            throw new \InvalidArgumentException('Date range cannot exceed ' . self::MAX_RANGE_MONTHS . ' months.');
        }
    }

    /**
     * @param array<string, mixed>|null $previous
     * @return array{access_token:string, refresh_token:string, expires_in:int, created:int, token_type:string, scope:string}
     */
    private function normalizeTokenPayload(array $payload, ?array $previous): array
    {
        if (isset($payload['error'])) {
            $msg = (string) ($payload['error_description'] ?? $payload['error']);
            throw new \RuntimeException('QuickBooks token error: ' . $msg);
        }
        if (empty($payload['access_token'])) {
            throw new \RuntimeException('QuickBooks token response missing access_token.');
        }

        // Drop Intuit/OpenID identity fields if ever present — never request or keep user Intuit ID.
        unset(
            $payload['id_token'],
            $payload['x_refresh_token_expires_in'],
            $payload['userid'],
            $payload['userId'],
            $payload['user_id'],
            $payload['sub'],
            $payload['email'],
            $payload['givenName'],
            $payload['familyName']
        );

        return [
            'access_token' => (string) $payload['access_token'],
            'refresh_token' => (string) ($payload['refresh_token'] ?? ($previous['refresh_token'] ?? '')),
            'expires_in' => (int) ($payload['expires_in'] ?? 3600),
            'created' => time(),
            'token_type' => (string) ($payload['token_type'] ?? 'bearer'),
            'scope' => (string) ($payload['scope'] ?? ($previous['scope'] ?? self::SCOPE)),
        ];
    }

    /**
     * Keep access token in process memory only.
     *
     * @param array{access_token:string, expires_in?:int, token_type?:string, scope?:string} $tokenData
     */
    private function rememberAccessToken(int $orgId, array $tokenData): void
    {
        $expiresIn = (int) ($tokenData['expires_in'] ?? 3600);
        self::$accessTokenMemory[$orgId] = [
            'access_token' => (string) $tokenData['access_token'],
            'expires_at' => time() + max(60, $expiresIn),
            'token_type' => (string) ($tokenData['token_type'] ?? 'bearer'),
            'scope' => (string) ($tokenData['scope'] ?? self::SCOPE),
        ];
    }

    /**
     * Encrypted persistence payload: refresh token only (no access_token, no Intuit user id).
     *
     * @param array{refresh_token?:string, scope?:string, token_type?:string} $tokenData
     */
    private static function encodeStoredTokenPayload(array $tokenData): string
    {
        $refresh = trim((string) ($tokenData['refresh_token'] ?? ''));
        if ($refresh === '') {
            throw new \RuntimeException('QuickBooks refresh token missing; cannot store connection.');
        }

        $persist = [
            'refresh_token' => $refresh,
            'scope' => (string) ($tokenData['scope'] ?? self::SCOPE),
            'token_type' => (string) ($tokenData['token_type'] ?? 'bearer'),
            // Explicitly omit access_token and any identity claims.
        ];

        return self::encryptString((string) json_encode($persist));
    }

    /**
     * @return array{refresh_token:string, scope?:string, token_type?:string}|null
     */
    private static function decodeStoredTokenPayload(?string $raw): ?array
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $plain = self::decryptString($raw);
        $decoded = json_decode($plain, true);
        if (!is_array($decoded)) {
            return null;
        }

        // Legacy plaintext migration: strip access_token if present and never return it.
        $refresh = trim((string) ($decoded['refresh_token'] ?? ''));
        if ($refresh === '') {
            return null;
        }

        return [
            'refresh_token' => $refresh,
            'scope' => (string) ($decoded['scope'] ?? self::SCOPE),
            'token_type' => (string) ($decoded['token_type'] ?? 'bearer'),
        ];
    }

    private static function encryptionKey(): string
    {
        $material = '';
        if (defined('QBO_TOKEN_ENCRYPTION_KEY')) {
            $material = (string) QBO_TOKEN_ENCRYPTION_KEY;
        }
        if ($material === '' && defined('SSO_SHARED_SECRET')) {
            $material = (string) SSO_SHARED_SECRET;
        }
        if ($material === '') {
            // Last-resort app-local key material (prefer setting QBO_TOKEN_ENCRYPTION_KEY).
            $material = (defined('BASE_URL') ? (string) BASE_URL : 'costsavings')
                . '|qbo|'
                . (defined('DB_NAME') ? (string) DB_NAME : 'db');
        }

        return hash('sha256', $material, true);
    }

    private static function encryptString(string $plaintext): string
    {
        if ($plaintext === '') {
            return '';
        }
        // Already encrypted payloads are left untouched.
        if (str_starts_with($plaintext, self::ENC_PREFIX)) {
            return $plaintext;
        }
        if (!function_exists('openssl_encrypt')) {
            throw new \RuntimeException('OpenSSL is required to encrypt QuickBooks tokens.');
        }

        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            self::encryptionKey(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16
        );
        if ($cipher === false) {
            throw new \RuntimeException('Failed to encrypt QuickBooks secret material.');
        }

        return self::ENC_PREFIX . base64_encode($iv . $tag . $cipher);
    }

    private static function decryptString(string $ciphertext): string
    {
        if ($ciphertext === '') {
            return '';
        }

        // Legacy plaintext (pre-encryption) support for one-time reads / migration.
        if (!str_starts_with($ciphertext, self::ENC_PREFIX)) {
            return $ciphertext;
        }

        if (!function_exists('openssl_decrypt')) {
            throw new \RuntimeException('OpenSSL is required to decrypt QuickBooks tokens.');
        }

        $raw = base64_decode(substr($ciphertext, strlen(self::ENC_PREFIX)), true);
        if ($raw === false || strlen($raw) < 28) {
            throw new \RuntimeException('Invalid encrypted QuickBooks payload.');
        }

        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain = openssl_decrypt(
            $cipher,
            'aes-256-gcm',
            self::encryptionKey(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
        if ($plain === false) {
            throw new \RuntimeException('Failed to decrypt QuickBooks secret material.');
        }

        return $plain;
    }

    /**
     * @return array<string, mixed>
     */
    private function exchangeAuthorizationCode(string $clientId, string $clientSecret, string $code): array
    {
        return $this->tokenRequest($clientId, $clientSecret, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => self::redirectUri(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function refreshAccessToken(string $clientId, string $clientSecret, string $refreshToken): array
    {
        return $this->tokenRequest($clientId, $clientSecret, [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);
    }

    /**
     * @param array<string, string> $fields
     * @return array<string, mixed>
     */
    private function tokenRequest(string $clientId, string $clientSecret, array $fields): array
    {
        $body = http_build_query($fields);
        $auth = base64_encode($clientId . ':' . $clientSecret);
        $result = $this->httpRaw(
            'POST',
            'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer',
            [
                'Authorization: Basic ' . $auth,
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded',
            ],
            $body
        );

        $decoded = json_decode($result['body'], true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Invalid token response from Intuit.');
        }
        if ($result['status'] >= 400) {
            $msg = (string) ($decoded['error_description'] ?? $decoded['error'] ?? ('HTTP ' . $result['status']));
            throw new \RuntimeException('QuickBooks OAuth failed: ' . $msg);
        }

        return $decoded;
    }

    private function fetchCompanyName(string $environment, string $realmId, string $accessToken): ?string
    {
        $apiBase = $environment === self::ENV_SANDBOX
            ? 'https://sandbox-quickbooks.api.intuit.com'
            : 'https://quickbooks.api.intuit.com';
        $url = $apiBase . '/v3/company/' . rawurlencode($realmId) . '/companyinfo/' . rawurlencode($realmId);
        $data = $this->httpJson('GET', $url, [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json',
        ]);
        $name = $data['CompanyInfo']['CompanyName'] ?? null;
        if (!is_string($name) || trim($name) === '') {
            return null;
        }

        return trim($name);
    }

    /**
     * @return 'account'|'vendor'|''
     */
    private function reportGroupBy(string $reportPath): string
    {
        $path = strtolower(trim($reportPath));
        if ($path === 'generalledger' || $path === 'generalledgerdetail') {
            return 'account';
        }
        if ($path === 'transactionlistbyvendor') {
            return 'vendor';
        }

        return '';
    }

    /**
     * @param array<string, mixed> $report
     * @return array<int, array{vendor_name:string,transaction_date:string,amount:float,transaction_type:string,account:string,memo:string}>
     */
    private function mapReportToRawRows(array $report, string $reportPath = ''): array
    {
        $colKeys = $this->extractColumnKeys($report);
        $rows = [];
        $this->walkReportRows(
            $report['Rows']['Row'] ?? [],
            $colKeys,
            $rows,
            '',
            '',
            $this->reportGroupBy($reportPath)
        );

        return $rows;
    }

    /**
     * @param array<string, mixed> $report
     * @return array<string, int>
     */
    private function extractColumnKeys(array $report): array
    {
        $map = [];
        $columns = $report['Columns']['Column'] ?? [];
        if (!is_array($columns)) {
            return $map;
        }
        // Single Column object
        if (isset($columns['ColTitle']) || isset($columns['MetaData'])) {
            $columns = [$columns];
        }

        // Keep split separate from account so we can prefer Split (GL) over Account (often bank/cash).
        $titleMap = [
            'date' => 'tx_date',
            'transaction date' => 'tx_date',
            'transaction type' => 'txn_type',
            'type' => 'txn_type',
            'name' => 'name',
            'vendor' => 'name',
            'customer' => 'name',
            'payee' => 'name',
            'memo/description' => 'memo',
            'memo' => 'memo',
            'description' => 'memo',
            'account' => 'account_name',
            'account full name' => 'account_name',
            'split' => 'split',
            'split account' => 'split',
            'amount' => 'nat_amount',
            'natural amount' => 'nat_amount',
            'open balance' => 'nat_amount',
            'subt_nat_amount' => 'nat_amount',
            'debt_amt' => 'debt_amt',
            'credit_amt' => 'credit_amt',
            'debit' => 'debt_amt',
            'credit' => 'credit_amt',
        ];

        foreach ($columns as $idx => $col) {
            if (!is_array($col)) {
                continue;
            }
            $key = null;
            $meta = $col['MetaData'] ?? [];
            if (is_array($meta)) {
                // Single MetaData vs list
                if (isset($meta['Name'])) {
                    $metaList = [$meta];
                } else {
                    $metaList = $meta;
                }
                foreach ($metaList as $m) {
                    if (!is_array($m)) {
                        continue;
                    }
                    if (($m['Name'] ?? '') === 'ColKey' && isset($m['Value'])) {
                        $key = strtolower(trim((string) $m['Value']));
                        break;
                    }
                }
            }
            if ($key === null || $key === '') {
                $title = strtolower(trim((string) ($col['ColTitle'] ?? '')));
                $key = $titleMap[$title] ?? $title;
            }
            if ($key === 'split_acc') {
                $key = 'split';
            }
            if ($key !== '') {
                $map[$key] = (int) $idx;
            }
        }

        // Alias common amount keys so mapDataRow can find them
        if (!isset($map['nat_amount'])) {
            foreach (['subt_nat_amount', 'amount', 'debt_amt'] as $alt) {
                if (isset($map[$alt])) {
                    $map['nat_amount'] = $map[$alt];
                    break;
                }
            }
        }
        if (!isset($map['split']) && isset($map['split_acc'])) {
            $map['split'] = $map['split_acc'];
        }
        if (!isset($map['tx_date']) && isset($map['date'])) {
            $map['tx_date'] = $map['date'];
        }
        if (!isset($map['txn_type']) && isset($map['type'])) {
            $map['txn_type'] = $map['type'];
        }

        return $map;
    }

    /**
     * @param mixed $nodes
     * @param array<string, int> $colKeys
     * @param array<int, array{vendor_name:string,transaction_date:string,amount:float,transaction_type:string,account:string,memo:string}> $out
     * @param 'account'|'vendor'|'' $groupBy
     */
    private function walkReportRows(
        $nodes,
        array $colKeys,
        array &$out,
        string $sectionVendor = '',
        string $sectionAccount = '',
        string $groupBy = ''
    ): void {
        if (!is_array($nodes)) {
            return;
        }
        // Single Row object vs list
        if (isset($nodes['ColData']) || isset($nodes['type']) || isset($nodes['Header']) || isset($nodes['Rows'])) {
            $nodes = [$nodes];
        }

        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }

            $localVendor = $sectionVendor;
            $localAccount = $sectionAccount;
            // Section headers: GeneralLedger = GL account; TransactionListByVendor = vendor/payee.
            if (isset($node['Header']['ColData']) && is_array($node['Header']['ColData'])) {
                $headerVal = $this->firstColDataValue($node['Header']['ColData']);
                if ($headerVal !== '' && stripos($headerVal, 'total for') !== 0) {
                    if ($groupBy === 'account') {
                        $localAccount = $headerVal;
                    } elseif ($groupBy === 'vendor') {
                        $localVendor = $headerVal;
                    }
                }
            }

            if (isset($node['Rows']['Row'])) {
                $this->walkReportRows(
                    $node['Rows']['Row'],
                    $colKeys,
                    $out,
                    $localVendor,
                    $localAccount,
                    $groupBy
                );
            }

            $type = (string) ($node['type'] ?? '');
            // Skip section/summary headers; only map real data lines (or ColData without type).
            if ($type === 'Section' || $type === 'section') {
                continue;
            }
            if ($type === 'Data' || $type === 'data' || ($type === '' && isset($node['ColData']))) {
                $mapped = $this->mapDataRow(
                    $node['ColData'] ?? [],
                    $colKeys,
                    $localVendor,
                    $localAccount
                );
                if ($mapped !== null) {
                    $out[] = $mapped;
                }
            }
        }
    }

    /**
     * @param mixed $colData
     */
    private function firstColDataValue($colData): string
    {
        if (!is_array($colData) || count($colData) === 0) {
            return '';
        }
        $first = $colData[0] ?? reset($colData);
        if (is_array($first)) {
            return trim((string) ($first['value'] ?? ''));
        }

        return trim((string) $first);
    }

    /**
     * @param mixed $colData
     * @param array<string, int> $colKeys
     * @return array{vendor_name:string,transaction_date:string,amount:float,transaction_type:string,account:string,memo:string}|null
     */
    private function mapDataRow(
        $colData,
        array $colKeys,
        string $sectionVendor = '',
        string $sectionAccount = ''
    ): ?array {
        if (!is_array($colData)) {
            return null;
        }
        // Single ColData cell object
        if (isset($colData['value']) && !isset($colData[0])) {
            $colData = [$colData];
        }

        $values = [];
        foreach ($colData as $i => $cell) {
            if (is_array($cell)) {
                $values[$i] = trim((string) ($cell['value'] ?? ''));
            } else {
                $values[$i] = trim((string) $cell);
            }
        }

        // If columns metadata missing, fall back to common TransactionList column order
        if (count($colKeys) === 0 && count($values) >= 5) {
            $colKeys = [
                'tx_date' => 0,
                'txn_type' => 1,
                'name' => min(4, count($values) - 1),
                'memo' => min(5, count($values) - 1),
                'account_name' => min(6, count($values) - 1),
                'nat_amount' => count($values) - 1,
            ];
        }

        $get = static function (string $key) use ($colKeys, $values): string {
            if (!isset($colKeys[$key])) {
                return '';
            }
            $idx = $colKeys[$key];

            return isset($values[$idx]) ? (string) $values[$idx] : '';
        };

        $vendor = $get('name');
        if ($vendor === '') {
            $vendor = $sectionVendor;
        }
        if ($vendor === '' || stripos($vendor, 'total for') === 0) {
            // Keep bank/fee lines that still have amount+date; label for review
            $vendor = $get('memo');
        }
        if ($vendor === '' || stripos($vendor, 'total for') === 0) {
            $vendor = '(Unnamed)';
        }

        $dateRaw = $get('tx_date');
        if ($dateRaw === '') {
            // Heuristic: first cell that looks like a date
            foreach ($values as $v) {
                if (CsvImport::parseDate($v) !== null || preg_match('/^\d{4}-\d{2}-\d{2}/', $v)) {
                    $dateRaw = $v;
                    break;
                }
            }
        }
        $date = CsvImport::parseDate($dateRaw);
        if ($date === null && preg_match('/^\d{4}-\d{2}-\d{2}/', $dateRaw)) {
            $date = substr($dateRaw, 0, 10);
        }
        if ($date === null) {
            return null;
        }

        $amountRaw = $get('nat_amount');
        if ($amountRaw === '') {
            $amountRaw = $get('subt_nat_amount');
        }
        if ($amountRaw === '') {
            $amountRaw = $get('amount');
        }
        if ($amountRaw === '') {
            $debt = $get('debt_amt');
            $credit = $get('credit_amt');
            if ($debt !== '') {
                $amountRaw = $debt;
            } elseif ($credit !== '') {
                $amountRaw = $credit;
            }
        }
        if ($amountRaw === '') {
            // Last non-empty cell that parses as money
            for ($i = count($values) - 1; $i >= 0; $i--) {
                $try = CsvImport::parseAmount($values[$i]);
                if ($try !== null && $values[$i] !== $dateRaw) {
                    $amountRaw = $values[$i];
                    break;
                }
            }
        }

        $amount = CsvImport::parseAmount($amountRaw);
        if ($amount === null || abs((float) $amount) < 0.00001) {
            return null;
        }

        // Prefer GL attribution: section account (General Ledger) → Split → Account (often bank/cash).
        $account = trim($sectionAccount);
        if ($account === '') {
            $account = $get('split');
        }
        if ($account === '') {
            $account = $get('split_acc');
        }
        if ($account === '') {
            $account = $get('account_name');
        }
        if ($account === '') {
            $account = $get('account');
        }

        return [
            'vendor_name' => $vendor,
            'transaction_date' => $date,
            'amount' => abs((float) $amount),
            'transaction_type' => $get('txn_type'),
            'account' => $account,
            'memo' => $get('memo'),
        ];
    }

    /**
     * @param array<int, string> $headers
     * @return array<string, mixed>
     */
    private function httpJson(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $result = $this->httpRaw($method, $url, $headers, $body);
        $decoded = json_decode($result['body'], true);
        if ($result['status'] >= 400) {
            $msg = is_array($decoded)
                ? (string) ($decoded['Fault']['Error'][0]['Message']
                    ?? $decoded['fault']['error'][0]['message']
                    ?? $decoded['error_description']
                    ?? $decoded['error']
                    ?? ('HTTP ' . $result['status']))
                : ('HTTP ' . $result['status']);
            throw new \RuntimeException('QuickBooks API error: ' . $msg);
        }
        if (!is_array($decoded)) {
            throw new \RuntimeException('Invalid JSON response from QuickBooks.');
        }

        return $decoded;
    }

    /**
     * @param array<int, string> $headers
     * @return array{status:int, body:string}
     */
    private function httpRaw(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('cURL is required for QuickBooks Online integration.');
        }
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Unable to start HTTP request.');
        }

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 90,
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            throw new \RuntimeException('HTTP request failed: ' . $err);
        }

        return ['status' => $status, 'body' => (string) $resp];
    }
}
