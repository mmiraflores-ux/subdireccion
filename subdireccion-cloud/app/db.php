<?php
declare(strict_types=1);

function env_str(string $name, string $default = ''): string
{
    $v = getenv($name);
    return ($v === false || $v === '') ? $default : $v;
}

/**
 * Conexión a PostgreSQL.
 * Acepta DATABASE_URL (formato de Neon/Render/Supabase/Aiven) o variables separadas.
 */
function db_connect(): PDO
{
    $url = env_str('DATABASE_URL');
    if ($url !== '') {
        $p = parse_url($url);
        if ($p === false || empty($p['host'])) {
            throw new RuntimeException('DATABASE_URL no es válida.');
        }
        parse_str($p['query'] ?? '', $q);
        $host = $p['host'];
        $port = (string)($p['port'] ?? 5432);
        $name = ltrim($p['path'] ?? '/subdireccion', '/');
        $user = rawurldecode($p['user'] ?? '');
        $pass = rawurldecode($p['pass'] ?? '');
        $ssl  = (string)($q['sslmode'] ?? env_str('DB_SSLMODE', 'prefer'));
    } else {
        $host = env_str('DB_HOST', '127.0.0.1');
        $port = env_str('DB_PORT', '5432');
        $name = env_str('DB_NAME', 'subdireccion');
        $user = env_str('DB_USER', 'postgres');
        $pass = env_str('DB_PASSWORD');
        $ssl  = env_str('DB_SSLMODE', 'prefer');
    }
    $pdo = new PDO("pgsql:host={$host};port={$port};dbname={$name};sslmode={$ssl};connect_timeout=15", $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $tz = preg_replace('~[^A-Za-z0-9_/+\-]~', '', date_default_timezone_get());
    $pdo->exec("SET TIME ZONE '{$tz}'");
    return $pdo;
}
