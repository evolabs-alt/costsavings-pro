<?php

namespace CostSavings;

use PDO;
use PDOException;

class VendorChatService
{
    private const MESSAGE_MAX_LENGTH = 2000;

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
                'SELECT id, vendor_item_id, user_id, username_snapshot, message, created_at
                 FROM vendor_item_chat_messages
                 WHERE org_id = ? AND project_id = ? AND vendor_item_id = ?
                 ORDER BY created_at ASC, id ASC'
            );
            $st->execute([$orgId, $projectId, $vendorItemId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);

            $messages = [];
            foreach ($rows as $row) {
                $messages[] = self::mapMessageRow($row);
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
                (org_id, project_id, vendor_item_id, user_id, username_snapshot, message)
                VALUES (?, ?, ?, ?, ?, ?)'
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
            $st = $pdo->prepare(
                'SELECT id, vendor_item_id, user_id, username_snapshot, message, created_at
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
                'message' => self::mapMessageRow($row),
            ];
        } catch (PDOException $e) {
            error_log('VendorChatService::addMessage: ' . $e->getMessage());

            return ['success' => false, 'error' => 'Unable to save chat message'];
        }
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
     * Unread counts (messages from other users) per vendor row visible to this user.
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
                'SELECT user_id, username_snapshot, message, created_at
                 FROM vendor_item_chat_messages
                 WHERE org_id = ? AND project_id = ? AND vendor_item_id = ?
                 ORDER BY created_at ASC, id ASC'
            );
            $insertMsg = $pdo->prepare(
                'INSERT INTO vendor_item_chat_messages
                (org_id, project_id, vendor_item_id, user_id, username_snapshot, message, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?)'
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
     * @return array<string, mixed>
     */
    private static function mapMessageRow(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'vendor_item_id' => (int) ($row['vendor_item_id'] ?? 0),
            'user_id' => (int) ($row['user_id'] ?? 0),
            'username' => (string) ($row['username_snapshot'] ?? ''),
            'message' => (string) ($row['message'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }
}
