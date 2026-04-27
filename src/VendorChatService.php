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

        if ($role === 'admin') {
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
