<?php

namespace CostSavings;

use PDO;
use PDOException;

class VendorChatService
{
    private const MESSAGE_MAX_LENGTH = 2000;

    private const EDIT_WINDOW_SECONDS = 3600;

    /**
     * @return array{success:bool, error?:string, messages?:array<int, array<string, mixed>>, vendor_name?:string}
     */
    public static function loadMessages(PDO $pdo, int $orgId, int $projectId, int $vendorItemId, int $userId, string $role): array
    {
        $item = self::loadAccessibleVendorItem($pdo, $orgId, $projectId, $vendorItemId, $userId, $role);
        if ($item === null) {
            return ['success' => false, 'error' => 'Vendor row not found or access denied'];
        }

        try {
            $st = $pdo->prepare(
                'SELECT id, vendor_item_id, user_id, username_snapshot, message, is_action_log, edited_at, created_at
                 FROM vendor_item_chat_messages
                 WHERE org_id = ? AND project_id = ? AND vendor_item_id = ?
                 ORDER BY created_at ASC, id ASC'
            );
            $st->execute([$orgId, $projectId, $vendorItemId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);

            $messages = [];
            foreach ($rows as $row) {
                $messages[] = self::mapMessageRow($row, $userId);
            }

            self::markThreadRead($pdo, $orgId, $projectId, $vendorItemId, $userId, $role);

            return [
                'success' => true,
                'vendor_name' => (string) ($item['vendor_name'] ?? ''),
                'messages' => $messages,
            ];
        } catch (PDOException $e) {
            error_log('VendorChatService::loadMessages: ' . $e->getMessage());

            return ['success' => false, 'error' => 'Unable to load chat messages'];
        }
    }

    /**
     * @return array{success:bool, error?:string, message?:array<string, mixed>, vendor_name?:string}
     */
    public static function addMessage(
        PDO $pdo,
        int $orgId,
        int $projectId,
        int $vendorItemId,
        int $userId,
        string $username,
        string $message,
        string $role
    ): array {
        $item = self::loadAccessibleVendorItem($pdo, $orgId, $projectId, $vendorItemId, $userId, $role);
        if ($item === null) {
            return ['success' => false, 'error' => 'Vendor row not found or access denied'];
        }

        $cleanMessage = trim(str_replace("\r\n", "\n", $message));
        if ($cleanMessage === '') {
            return ['success' => false, 'error' => 'Message is required'];
        }
        $msgLen = function_exists('mb_strlen') ? mb_strlen($cleanMessage, 'UTF-8') : strlen($cleanMessage);
        if ($msgLen > self::MESSAGE_MAX_LENGTH) {
            return ['success' => false, 'error' => 'Message exceeds maximum length of 2000 characters'];
        }

        $usernameSnapshot = trim($username);
        if ($usernameSnapshot === '') {
            $usernameSnapshot = self::resolveUsername($pdo, $userId);
        }

        try {
            $ins = $pdo->prepare(
                'INSERT INTO vendor_item_chat_messages
                (org_id, project_id, vendor_item_id, user_id, username_snapshot, message, is_action_log)
                VALUES (?, ?, ?, ?, ?, ?, 0)'
            );
            $ins->execute([
                $orgId,
                $projectId,
                $vendorItemId,
                $userId,
                $usernameSnapshot,
                $cleanMessage,
            ]);

            $msgId = (int) $pdo->lastInsertId();
            self::syncMessageMentions($pdo, $orgId, $msgId, $cleanMessage, $userId);

            $st = $pdo->prepare(
                'SELECT id, vendor_item_id, user_id, username_snapshot, message, is_action_log, edited_at, created_at
                 FROM vendor_item_chat_messages
                 WHERE id = ?'
            );
            $st->execute([$msgId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return ['success' => false, 'error' => 'Message saved but could not be loaded'];
            }

            return [
                'success' => true,
                'vendor_name' => (string) ($item['vendor_name'] ?? ''),
                'message' => self::mapMessageRow($row, $userId),
            ];
        } catch (PDOException $e) {
            error_log('VendorChatService::addMessage: ' . $e->getMessage());

            return ['success' => false, 'error' => 'Unable to save chat message'];
        }
    }

    /**
     * Append an automatic action-log line to a vendor chat thread (server-side only).
     * Failures are logged and do not throw.
     */
    public static function appendActionLogEntry(
        PDO $pdo,
        int $orgId,
        int $projectId,
        int $vendorItemId,
        int $actorUserId,
        string $actorName,
        string $message
    ): void {
        if ($vendorItemId <= 0) {
            return;
        }

        $cleanMessage = trim(str_replace("\r\n", "\n", $message));
        if ($cleanMessage === '') {
            return;
        }
        $msgLen = function_exists('mb_strlen') ? mb_strlen($cleanMessage, 'UTF-8') : strlen($cleanMessage);
        if ($msgLen > self::MESSAGE_MAX_LENGTH) {
            $cleanMessage = function_exists('mb_substr')
                ? mb_substr($cleanMessage, 0, self::MESSAGE_MAX_LENGTH, 'UTF-8')
                : substr($cleanMessage, 0, self::MESSAGE_MAX_LENGTH);
        }

        $actorName = trim($actorName);
        if ($actorName === '') {
            $actorName = $actorUserId > 0 ? self::resolveUsername($pdo, $actorUserId) : 'System';
        }

        try {
            $st = $pdo->prepare(
                'SELECT id FROM cost_calculator_items
                 WHERE id = ? AND org_id = ? AND project_id = ?
                 LIMIT 1'
            );
            $st->execute([$vendorItemId, $orgId, $projectId]);
            if (!$st->fetchColumn()) {
                return;
            }

            $ins = $pdo->prepare(
                'INSERT INTO vendor_item_chat_messages
                (org_id, project_id, vendor_item_id, user_id, username_snapshot, message, is_action_log)
                VALUES (?, ?, ?, ?, ?, ?, 1)'
            );
            $ins->execute([
                $orgId,
                $projectId,
                $vendorItemId,
                max(0, $actorUserId),
                $actorName,
                $cleanMessage,
            ]);
        } catch (PDOException $e) {
            error_log('VendorChatService::appendActionLogEntry: ' . $e->getMessage());
        }
    }

    /**
     * @return array{success:bool, error?:string, message?:array<string, mixed>, vendor_name?:string}
     */
    public static function editMessage(
        PDO $pdo,
        int $orgId,
        int $projectId,
        int $vendorItemId,
        int $messageId,
        int $userId,
        string $role,
        string $message
    ): array {
        $item = self::loadAccessibleVendorItem($pdo, $orgId, $projectId, $vendorItemId, $userId, $role);
        if ($item === null) {
            return ['success' => false, 'error' => 'Vendor row not found or access denied'];
        }
        if ($messageId <= 0) {
            return ['success' => false, 'error' => 'Message id is required'];
        }

        $cleanMessage = trim(str_replace("\r\n", "\n", $message));
        if ($cleanMessage === '') {
            return ['success' => false, 'error' => 'Message is required'];
        }
        $msgLen = function_exists('mb_strlen') ? mb_strlen($cleanMessage, 'UTF-8') : strlen($cleanMessage);
        if ($msgLen > self::MESSAGE_MAX_LENGTH) {
            return ['success' => false, 'error' => 'Message exceeds maximum length of 2000 characters'];
        }

        try {
            $st = $pdo->prepare(
                'SELECT id, vendor_item_id, user_id, username_snapshot, message, is_action_log, edited_at, created_at
                 FROM vendor_item_chat_messages
                 WHERE id = ? AND org_id = ? AND project_id = ? AND vendor_item_id = ?
                 LIMIT 1'
            );
            $st->execute([$messageId, $orgId, $projectId, $vendorItemId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return ['success' => false, 'error' => 'Message not found'];
            }
            if (!self::isMessageEditable($row, $userId)) {
                return ['success' => false, 'error' => 'This message can no longer be edited'];
            }

            $existing = trim((string) ($row['message'] ?? ''));
            if ($cleanMessage === $existing) {
                return [
                    'success' => true,
                    'vendor_name' => (string) ($item['vendor_name'] ?? ''),
                    'message' => self::mapMessageRow($row, $userId),
                ];
            }

            $upd = $pdo->prepare(
                'UPDATE vendor_item_chat_messages
                 SET message = ?, edited_at = CURRENT_TIMESTAMP
                 WHERE id = ? AND org_id = ? AND project_id = ? AND vendor_item_id = ?'
            );
            $upd->execute([$cleanMessage, $messageId, $orgId, $projectId, $vendorItemId]);

            self::syncMessageMentions($pdo, $orgId, $messageId, $cleanMessage, $userId);

            $st->execute([$messageId, $orgId, $projectId, $vendorItemId]);
            $updated = $st->fetch(PDO::FETCH_ASSOC);
            if (!$updated) {
                return ['success' => false, 'error' => 'Message updated but could not be loaded'];
            }

            return [
                'success' => true,
                'vendor_name' => (string) ($item['vendor_name'] ?? ''),
                'message' => self::mapMessageRow($updated, $userId),
            ];
        } catch (PDOException $e) {
            error_log('VendorChatService::editMessage: ' . $e->getMessage());

            return ['success' => false, 'error' => 'Unable to update chat message'];
        }
    }

    public static function formatStatusChangeMessage(string $fromStatus, string $toStatus): string
    {
        $from = VendorService::statusLabel($fromStatus);
        $to = VendorService::statusLabel($toStatus);

        return 'Changed status from ' . $from . ' to ' . $to . '.';
    }

    public static function formatPurposeChangeMessage(string $fromPurpose, string $toPurpose, string $verb = 'Changed'): string
    {
        $verb = trim($verb);
        if ($verb === '') {
            $verb = 'Changed';
        }

        return $verb . ' purpose from ' . self::formatPurposeForLog($fromPurpose)
            . ' to ' . self::formatPurposeForLog($toPurpose) . '.';
    }

    private static function formatPurposeForLog(string $purpose): string
    {
        $text = trim(VendorPurposeService::stripAiPurposeUiPrefix($purpose));
        if ($text === '') {
            return '(empty)';
        }

        $maxSnippet = 400;
        $len = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
        if ($len > $maxSnippet) {
            $text = (function_exists('mb_substr') ? mb_substr($text, 0, $maxSnippet, 'UTF-8') : substr($text, 0, $maxSnippet)) . '…';
        }

        return $text;
    }

    /**
     * Advances the user's read pointer to the latest message in the thread (if they may access it).
     */
    public static function markThreadRead(
        PDO $pdo,
        int $orgId,
        int $projectId,
        int $vendorItemId,
        int $userId,
        string $role
    ): void {
        $item = self::loadAccessibleVendorItem($pdo, $orgId, $projectId, $vendorItemId, $userId, $role);
        if ($item === null) {
            return;
        }

        try {
            $st = $pdo->prepare(
                'SELECT COALESCE(MAX(id), 0) FROM vendor_item_chat_messages
                 WHERE org_id = ? AND project_id = ? AND vendor_item_id = ?'
            );
            $st->execute([$orgId, $projectId, $vendorItemId]);
            $maxId = (int) $st->fetchColumn();

            $up = $pdo->prepare(
                'INSERT INTO vendor_item_chat_reads (user_id, vendor_item_id, last_read_message_id)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                   last_read_message_id = GREATEST(last_read_message_id, ?),
                   updated_at = CURRENT_TIMESTAMP'
            );
            $up->execute([$userId, $vendorItemId, $maxId, $maxId]);
        } catch (PDOException $e) {
            error_log('VendorChatService::markThreadRead: ' . $e->getMessage());
        }
    }

    /**
     * Unread counts (manual chat from other users) per vendor row visible to this user.
     * Automatic action-log lines (status/purpose changes, AI updates) are excluded.
     *
     * @return array<int, int> vendor_item_id => count
     */
    public static function unreadCountsForUserProject(
        PDO $pdo,
        int $orgId,
        int $projectId,
        int $userId,
        string $role
    ): array {
        $items = VendorService::loadVisibleItems($pdo, $userId, $orgId, $projectId, $role);
        $ids = [];
        foreach ($items as $it) {
            if (!is_array($it)) {
                continue;
            }
            $id = (int) ($it['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        if ($ids === []) {
            return [];
        }

        try {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $sql = "SELECT m.vendor_item_id, COUNT(*) AS c
                    FROM vendor_item_chat_messages m
                    LEFT JOIN vendor_item_chat_reads r
                      ON r.user_id = ? AND r.vendor_item_id = m.vendor_item_id
                    WHERE m.org_id = ? AND m.project_id = ?
                      AND m.vendor_item_id IN ($placeholders)
                      AND m.is_action_log = 0
                      AND m.user_id <> ?
                      AND m.id > COALESCE(r.last_read_message_id, 0)
                    GROUP BY m.vendor_item_id";

            $params = array_merge([$userId, $orgId, $projectId], $ids, [$userId]);
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $out = [];
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $vid = (int) ($row['vendor_item_id'] ?? 0);
                if ($vid > 0) {
                    $out[$vid] = (int) ($row['c'] ?? 0);
                }
            }

            return $out;
        } catch (PDOException $e) {
            error_log('VendorChatService::unreadCountsForUserProject: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * Unread @mention counts for the current user per vendor row.
     *
     * @return array<int, int> vendor_item_id => count
     */
    public static function taggedCountsForUserProject(
        PDO $pdo,
        int $orgId,
        int $projectId,
        int $userId,
        string $role
    ): array {
        $items = VendorService::loadVisibleItems($pdo, $userId, $orgId, $projectId, $role);
        $ids = [];
        foreach ($items as $it) {
            if (!is_array($it)) {
                continue;
            }
            $id = (int) ($it['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        if ($ids === []) {
            return [];
        }

        try {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $sql = "SELECT m.vendor_item_id, COUNT(DISTINCT m.id) AS c
                    FROM vendor_item_chat_messages m
                    INNER JOIN vendor_item_chat_mentions t
                      ON t.message_id = m.id AND t.mentioned_user_id = ?
                    LEFT JOIN vendor_item_chat_reads r
                      ON r.user_id = ? AND r.vendor_item_id = m.vendor_item_id
                    WHERE m.org_id = ? AND m.project_id = ?
                      AND m.vendor_item_id IN ($placeholders)
                      AND m.is_action_log = 0
                      AND m.user_id <> ?
                      AND m.id > COALESCE(r.last_read_message_id, 0)
                    GROUP BY m.vendor_item_id";

            $params = array_merge([$userId, $userId, $orgId, $projectId], $ids, [$userId]);
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $out = [];
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $vid = (int) ($row['vendor_item_id'] ?? 0);
                if ($vid > 0) {
                    $out[$vid] = (int) ($row['c'] ?? 0);
                }
            }

            return $out;
        } catch (PDOException $e) {
            error_log('VendorChatService::taggedCountsForUserProject: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * @return list<string>
     */
    public static function extractMentionTokens(string $message): array
    {
        if (!preg_match_all('/@([A-Za-z0-9._-]+)/u', $message, $matches)) {
            return [];
        }

        $tokens = [];
        foreach ($matches[1] as $token) {
            $token = trim((string) $token);
            if ($token !== '') {
                $tokens[] = $token;
            }
        }

        return $tokens;
    }

    /**
     * @return array<int, string> user_id => mention_token used in message
     */
    public static function resolveMentionsForOrg(PDO $pdo, int $orgId, string $message, int $senderUserId): array
    {
        $tokens = self::extractMentionTokens($message);
        if ($tokens === []) {
            return [];
        }

        try {
            $st = $pdo->prepare(
                'SELECT id, username, display_name, email FROM users
                 WHERE org_id = ? AND (is_disabled = 0 OR is_disabled IS NULL)'
            );
            $st->execute([$orgId]);
            $members = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('VendorChatService::resolveMentionsForOrg: ' . $e->getMessage());

            return [];
        }

        $lookup = self::buildMentionLookup($members);
        $resolved = [];
        foreach ($tokens as $token) {
            $key = self::normalizeMentionKey($token);
            if ($key === '' || !isset($lookup[$key])) {
                continue;
            }
            $userId = (int) $lookup[$key]['user_id'];
            if ($userId <= 0 || $userId === $senderUserId) {
                continue;
            }
            $resolved[$userId] = (string) $lookup[$key]['token'];
        }

        return $resolved;
    }

    /**
     * @param list<array<string, mixed>> $members
     * @return array<string, array{user_id:int, token:string}>
     */
    private static function buildMentionLookup(array $members): array
    {
        $lookup = [];
        foreach ($members as $member) {
            $userId = (int) ($member['id'] ?? 0);
            if ($userId <= 0) {
                continue;
            }

            $username = trim((string) ($member['username'] ?? ''));
            if ($username !== '') {
                $key = self::normalizeMentionKey($username);
                if ($key !== '' && !isset($lookup[$key])) {
                    $lookup[$key] = ['user_id' => $userId, 'token' => $username];
                }
            }

            $displayName = trim((string) ($member['display_name'] ?? ''));
            if ($displayName !== '') {
                $fullKey = self::normalizeMentionKey(str_replace(' ', '', $displayName));
                if ($fullKey !== '' && !isset($lookup[$fullKey])) {
                    $lookup[$fullKey] = ['user_id' => $userId, 'token' => $username !== '' ? $username : $displayName];
                }
                $parts = preg_split('/\s+/u', $displayName) ?: [];
                $firstWord = trim((string) ($parts[0] ?? ''));
                if ($firstWord !== '') {
                    $firstKey = self::normalizeMentionKey($firstWord);
                    if ($firstKey !== '' && !isset($lookup[$firstKey])) {
                        $lookup[$firstKey] = ['user_id' => $userId, 'token' => $username !== '' ? $username : $firstWord];
                    }
                }
            }

            $email = trim((string) ($member['email'] ?? ''));
            if ($email !== '' && str_contains($email, '@')) {
                $local = trim((string) (explode('@', $email, 2)[0] ?? ''));
                $emailKey = self::normalizeMentionKey($local);
                if ($emailKey !== '' && !isset($lookup[$emailKey])) {
                    $lookup[$emailKey] = ['user_id' => $userId, 'token' => $username !== '' ? $username : $local];
                }
            }
        }

        return $lookup;
    }

    private static function normalizeMentionKey(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        return function_exists('mb_strtolower')
            ? mb_strtolower($value, 'UTF-8')
            : strtolower($value);
    }

    private static function syncMessageMentions(
        PDO $pdo,
        int $orgId,
        int $messageId,
        string $message,
        int $senderUserId
    ): void {
        if ($messageId <= 0) {
            return;
        }

        try {
            $del = $pdo->prepare('DELETE FROM vendor_item_chat_mentions WHERE message_id = ?');
            $del->execute([$messageId]);

            $mentions = self::resolveMentionsForOrg($pdo, $orgId, $message, $senderUserId);
            if ($mentions === []) {
                return;
            }

            $ins = $pdo->prepare(
                'INSERT INTO vendor_item_chat_mentions (message_id, mentioned_user_id, mention_token)
                 VALUES (?, ?, ?)'
            );
            foreach ($mentions as $mentionedUserId => $token) {
                $ins->execute([$messageId, $mentionedUserId, $token]);
            }
        } catch (PDOException $e) {
            error_log('VendorChatService::syncMessageMentions: ' . $e->getMessage());
        }
    }

    /**
     * Copies vendor line chat threads from one project to another, matched by vendor name.
     * Preserves original author, message text, and timestamps.
     *
     * @return array{success:bool, copied_messages?:int, matched_vendors?:int, error?:string}
     */
    public static function copyChatsBetweenProjects(
        PDO $pdo,
        int $orgId,
        int $fromProjectId,
        int $toProjectId,
        int $userId,
        string $role
    ): array {
        if ($fromProjectId <= 0 || $toProjectId <= 0 || $fromProjectId === $toProjectId) {
            return ['success' => false, 'error' => 'Invalid source or target project.'];
        }

        $sourceMap = self::vendorItemIdsByNormalizedName($pdo, $orgId, $fromProjectId, $role);
        $targetMap = self::vendorItemIdsByNormalizedName($pdo, $orgId, $toProjectId, $role);
        if ($sourceMap === [] || $targetMap === []) {
            return ['success' => true, 'copied_messages' => 0, 'matched_vendors' => 0];
        }

        $matchedVendors = 0;
        $copiedMessages = 0;

        try {
            $selectMsgs = $pdo->prepare(
                'SELECT user_id, username_snapshot, message, is_action_log, edited_at, created_at
                 FROM vendor_item_chat_messages
                 WHERE org_id = ? AND project_id = ? AND vendor_item_id = ?
                 ORDER BY created_at ASC, id ASC'
            );
            $insertMsg = $pdo->prepare(
                'INSERT INTO vendor_item_chat_messages
                (org_id, project_id, vendor_item_id, user_id, username_snapshot, message, is_action_log, edited_at, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );

            foreach ($sourceMap as $norm => $sourceItemId) {
                if (!isset($targetMap[$norm])) {
                    continue;
                }
                $targetItemId = $targetMap[$norm];
                $selectMsgs->execute([$orgId, $fromProjectId, $sourceItemId]);
                $messages = $selectMsgs->fetchAll(PDO::FETCH_ASSOC);
                if ($messages === []) {
                    continue;
                }
                ++$matchedVendors;
                foreach ($messages as $msg) {
                    $insertMsg->execute([
                        $orgId,
                        $toProjectId,
                        $targetItemId,
                        (int) ($msg['user_id'] ?? 0),
                        (string) ($msg['username_snapshot'] ?? ''),
                        (string) ($msg['message'] ?? ''),
                        (int) ($msg['is_action_log'] ?? 0),
                        $msg['edited_at'] ?? null,
                        (string) ($msg['created_at'] ?? date('Y-m-d H:i:s')),
                    ]);
                    ++$copiedMessages;
                }
            }

            return [
                'success' => true,
                'copied_messages' => $copiedMessages,
                'matched_vendors' => $matchedVendors,
            ];
        } catch (PDOException $e) {
            error_log('VendorChatService::copyChatsBetweenProjects: ' . $e->getMessage());

            return ['success' => false, 'error' => 'Could not copy vendor chats.'];
        }
    }

    /**
     * @return array<string, int> normalized vendor name => item id
     */
    private static function vendorItemIdsByNormalizedName(PDO $pdo, int $orgId, int $projectId, string $role): array
    {
        $visibilityFilter = OrgRole::isSuperAdmin($role) ? '' : ' AND visibility = \'public\'';
        $st = $pdo->prepare(
            'SELECT id, vendor_name FROM cost_calculator_items
             WHERE org_id = ? AND project_id = ?' . $visibilityFilter
        );
        $st->execute([$orgId, $projectId]);
        $map = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $norm = VendorService::normalizeVendorName((string) ($row['vendor_name'] ?? ''));
            $id = (int) ($row['id'] ?? 0);
            if ($norm !== '' && $id > 0) {
                $map[$norm] = $id;
            }
        }

        return $map;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function loadAccessibleVendorItem(
        PDO $pdo,
        int $orgId,
        int $projectId,
        int $vendorItemId,
        int $userId,
        string $role
    ): ?array {
        if ($vendorItemId <= 0) {
            return null;
        }

        if (OrgRole::isSuperAdmin($role)) {
            $sql = 'SELECT id, vendor_name, visibility, manager_user_id
                    FROM cost_calculator_items
                    WHERE id = :id AND org_id = :oid AND project_id = :pid
                    LIMIT 1';
            $st = $pdo->prepare($sql);
            $st->execute([
                ':id' => $vendorItemId,
                ':oid' => $orgId,
                ':pid' => $projectId,
            ]);

            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        }

        if (OrgRole::isPrivileged($role)) {
            $sql = 'SELECT id, vendor_name, visibility, manager_user_id
                    FROM cost_calculator_items
                    WHERE id = :id
                      AND org_id = :oid
                      AND project_id = :pid
                      AND visibility = \'public\'
                    LIMIT 1';
            $st = $pdo->prepare($sql);
            $st->execute([
                ':id' => $vendorItemId,
                ':oid' => $orgId,
                ':pid' => $projectId,
            ]);

            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        }

        $sql = 'SELECT id, vendor_name, visibility, manager_user_id
                FROM cost_calculator_items
                WHERE id = :id
                  AND org_id = :oid
                  AND project_id = :pid
                  AND (visibility = \'public\' OR (visibility = \'confidential\' AND manager_user_id = :uid))
                LIMIT 1';
        $st = $pdo->prepare($sql);
        $st->execute([
            ':id' => $vendorItemId,
            ':oid' => $orgId,
            ':pid' => $projectId,
            ':uid' => $userId,
        ]);

        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private static function resolveUsername(PDO $pdo, int $userId): string
    {
        try {
            $st = $pdo->prepare(
                'SELECT display_name, username, email FROM users WHERE id = ? LIMIT 1'
            );
            $st->execute([$userId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return 'User #' . $userId;
            }

            $display = trim((string) ($row['display_name'] ?? ''));
            if ($display !== '') {
                return $display;
            }
            $username = trim((string) ($row['username'] ?? ''));
            if ($username !== '') {
                return $username;
            }
            $email = trim((string) ($row['email'] ?? ''));
            if ($email !== '') {
                return $email;
            }
        } catch (PDOException $e) {
            error_log('VendorChatService::resolveUsername: ' . $e->getMessage());
        }

        return 'User #' . $userId;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function isMessageEditable(array $row, int $currentUserId): bool
    {
        if ($currentUserId <= 0) {
            return false;
        }
        if (!empty($row['is_action_log'])) {
            return false;
        }
        if ((int) ($row['user_id'] ?? 0) !== $currentUserId) {
            return false;
        }

        $createdRaw = (string) ($row['created_at'] ?? '');
        if ($createdRaw === '') {
            return false;
        }
        $createdTs = strtotime($createdRaw);
        if ($createdTs === false) {
            return false;
        }

        return (time() - $createdTs) <= self::EDIT_WINDOW_SECONDS;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function mapMessageRow(array $row, int $currentUserId = 0): array
    {
        $editedAt = $row['edited_at'] ?? null;
        if ($editedAt !== null && $editedAt !== '') {
            $editedAt = (string) $editedAt;
        } else {
            $editedAt = null;
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'vendor_item_id' => (int) ($row['vendor_item_id'] ?? 0),
            'user_id' => (int) ($row['user_id'] ?? 0),
            'username' => (string) ($row['username_snapshot'] ?? ''),
            'message' => (string) ($row['message'] ?? ''),
            'is_action_log' => !empty($row['is_action_log']),
            'edited_at' => $editedAt,
            'created_at' => (string) ($row['created_at'] ?? ''),
            'can_edit' => self::isMessageEditable($row, $currentUserId),
        ];
    }
}
