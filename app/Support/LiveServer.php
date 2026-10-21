<?php

namespace App\Support;

/**
 * A short socket check on a database server's port before querying it. A
 * server out of reach otherwise holds the page for the whole connection
 * timeout. Local and SQLite connections always pass.
 */
final class LiveServer
{
    public static function reachable(string $connection, int $seconds = 3): bool
    {
        $config = config('database.connections.' . $connection, []);
        if (!in_array($config['driver'] ?? '', ['mysql', 'mariadb'], true) || !empty($config['unix_socket'])) {
            return true;
        }

        $host = (string) ($config['host'] ?? '127.0.0.1');
        if (in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            return true;
        }

        $socket = @fsockopen($host, (int) ($config['port'] ?? 3306), $errno, $error, $seconds);
        if ($socket === false) {
            return false;
        }
        fclose($socket);

        return true;
    }
}
