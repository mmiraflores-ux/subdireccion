<?php
declare(strict_types=1);

/*
 * Arranque común de la plataforma.
 * Toda la configuración llega por variables de entorno (ver .env.example),
 * de modo que el mismo código funciona en local (Docker) y en la nube.
 */

date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'America/Mexico_City');

const APP_NOMBRE      = 'Sistema de Subdirección';
const APP_INSTITUCION = 'CBT No. 6 Gerardo Linares Terrón';

define('PLANEACIONES_MAX_BYTES', (int)(getenv('PLANEACIONES_MAX_MB') ?: 15) * 1024 * 1024);
define('PLANEACIONES_MAX_FILES', (int)(getenv('PLANEACIONES_MAX_ARCHIVOS') ?: 60));
define('PASSWORD_MIN_LEN', 12);

/** Roles con acceso a todas las planeaciones. */
const ROLES_GESTION  = ['SUBDIRECTORA', 'APOYO_SUBDIRECCION'];
const ROLES_CONSULTA = ['SUBDIRECTORA', 'APOYO_SUBDIRECCION', 'DIRECTOR'];

require_once __DIR__ . '/db.php';

function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function tamano_legible(int $bytes): string
{
    return $bytes >= 1048576 ? number_format($bytes / 1048576, 1) . ' MB' : max(1, (int)round($bytes / 1024)) . ' KB';
}

function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    // Render, Railway, Koyeb, etc. terminan TLS en un proxy.
    return strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

function client_ip(): string
{
    $fwd = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
    if ($fwd !== '' && env_str('TRUST_PROXY', '1') === '1') {
        return trim(explode(',', $fwd)[0]);
    }
    return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

function fatal_page(int $code, string $titulo, string $mensaje): never
{
    http_response_code($code);
    render_header($titulo, null);
    echo '<main class="auth-wrap"><section class="auth-card"><p class="eyebrow auth-eyebrow">AVISO</p><h2>'
        . h($titulo) . '</h2><p class="muted">' . h($mensaje)
        . '</p><p class="auth-help"><a href="index.php">Volver al inicio</a></p></section></main>';
    render_footer();
    exit;
}

/* ---------------------------------------------------------------------
 * Sesiones guardadas en PostgreSQL (el disco de los servicios gratuitos
 * se borra en cada reinicio, así que no se usan archivos).
 * ------------------------------------------------------------------- */
final class DbSessionHandler implements SessionHandlerInterface
{
    public function __construct(private PDO $pdo, private int $ttl) {}

    public function open(string $path, string $name): bool { return true; }
    public function close(): bool { return true; }

    public function read(string $id): string|false
    {
        $st = $this->pdo->prepare('SELECT datos FROM app_sesion WHERE id = :id AND actualizado > now() - make_interval(secs => :ttl)');
        $st->execute(['id' => $id, 'ttl' => $this->ttl]);
        $v = $st->fetchColumn();
        return $v === false ? '' : (string)$v;
    }

    public function write(string $id, string $data): bool
    {
        $st = $this->pdo->prepare('INSERT INTO app_sesion (id, datos, actualizado) VALUES (:id, :d, now())
            ON CONFLICT (id) DO UPDATE SET datos = EXCLUDED.datos, actualizado = now()');
        return $st->execute(['id' => $id, 'd' => $data]);
    }

    public function destroy(string $id): bool
    {
        $this->pdo->prepare('DELETE FROM app_sesion WHERE id = :id')->execute(['id' => $id]);
        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        $st = $this->pdo->prepare('DELETE FROM app_sesion WHERE actualizado < now() - make_interval(secs => :t)');
        $st->execute(['t' => $max_lifetime]);
        return $st->rowCount();
    }
}

/* ---------------------------------------------------------------------
 * Arranque
 * ------------------------------------------------------------------- */
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; img-src 'self' data:; frame-ancestors 'self'; form-action 'self'");
if (is_https()) {
    header('Strict-Transport-Security: max-age=31536000');
}

try {
    $pdo = db_connect();
} catch (Throwable $e) {
    error_log('Error de conexión: ' . $e->getMessage());
    http_response_code(503);
    exit('No fue posible conectar con la base de datos. Revisa la variable DATABASE_URL y el registro del servidor.');
}

require_once __DIR__ . '/layout.php';

if (session_status() !== PHP_SESSION_ACTIVE && PHP_SAPI !== 'cli') {
    $ttl = (int)(getenv('SESSION_TTL') ?: 8 * 3600);
    ini_set('session.gc_maxlifetime', (string)$ttl);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_probability', '1');
    ini_set('session.gc_divisor', '100');
    session_set_save_handler(new DbSessionHandler($pdo, $ttl), true);
    session_name('subdireccion_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
