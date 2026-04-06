<?php

/**
 * Append a line to pro.logs at the application root (same directory as config.php).
 *
 * @param array<string, scalar|null> $context
 */
function proLog(string $message, array $context = []): void
{
    $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'pro.logs';
    $line = date('Y-m-d H:i:s') . ' ' . $message;
    if ($context !== []) {
        $line .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES);
    }
    $line .= "\n";
    @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
}
