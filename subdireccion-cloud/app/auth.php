<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function current_user(PDO $pdo): ?array
{
    if (empty($_SESSION['usuario_id'])) {
        return null;
    }
    static $cache = null;
    if ($cache !== null && (int)$cache['usuario_id'] === (int)$_SESSION['usuario_id']) {
        return $cache;
    }

    $stmt = $pdo->prepare("SELECT u.usuario_id, u.persona_id, u.identificador_login, u.estado,
                   u.debe_cambiar_contrasena, p.nombre AS nombre_persona
            FROM usuario u
            JOIN persona p ON p.persona_id = u.persona_id
            WHERE u.usuario_id = :id AND u.estado = 'Activo' AND u.hash_credencial IS NOT NULL");
    $stmt->execute(['id' => (int)$_SESSION['usuario_id']]);
    $user = $stmt->fetch();
    if (!$user) {
        unset($_SESSION['usuario_id']);
        return null;
    }

    $roles = $pdo->prepare("SELECT r.codigo, r.nombre
        FROM usuario_rol ur
        JOIN rol r ON r.rol_id = ur.rol_id
        WHERE ur.usuario_id = :id AND ur.estado = 'Activo' AND r.estado = 'Activo'
          AND ur.fecha_inicio <= CURRENT_DATE
          AND (ur.fecha_fin IS NULL OR ur.fecha_fin >= CURRENT_DATE)
        ORDER BY r.nombre");
    $roles->execute(['id' => (int)$user['usuario_id']]);
    $user['roles'] = $roles->fetchAll();
    $cache = $user;
    return $user;
}

/** Exige sesión iniciada. Si la cuenta debe cambiar su contraseña, la envía a esa pantalla. */
function require_login(PDO $pdo, bool $allowPendingPasswordChange = false): array
{
    $user = current_user($pdo);
    if (!$user) {
        $target = basename((string)($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
        $qs = (string)($_SERVER['QUERY_STRING'] ?? '');
        header('Location: login.php?next=' . rawurlencode($target . ($qs !== '' ? '?' . $qs : '')));
        exit;
    }
    if (!$allowPendingPasswordChange && $user['debe_cambiar_contrasena']) {
        header('Location: cambiar_contrasena.php');
        exit;
    }
    return $user;
}

function user_has_role(array $user, array $codes): bool
{
    foreach (($user['roles'] ?? []) as $role) {
        if (in_array($role['codigo'], $codes, true)) {
            return true;
        }
    }
    return false;
}

function require_role(array $user, array $codes): void
{
    if (!user_has_role($user, $codes)) {
        fatal_page(403, 'Acceso restringido', 'Tu cuenta no tiene permiso para usar esta sección.');
    }
}

/** Valida que la ruta de retorno sea interna (sin dominio ni esquema). */
function safe_next(string $next): string
{
    if (preg_match('~^[a-z_]+\.php(\?[A-Za-z0-9_=&%.\-]*)?$~', $next)) {
        return $next;
    }
    return 'index.php';
}

function password_problem(string $password, string $confirm): ?string
{
    if ($password !== $confirm) {
        return 'Las contraseñas no coinciden.';
    }
    if (mb_strlen($password) < PASSWORD_MIN_LEN) {
        return 'La contraseña debe tener al menos ' . PASSWORD_MIN_LEN . ' caracteres.';
    }
    if (strlen($password) > 72) {
        return 'La contraseña es demasiado larga (máximo 72 bytes).';
    }
    return null;
}

function audit(PDO $pdo, ?int $usuarioId, string $entidad, int $registroId, string $accion, ?string $motivo = null, ?array $nuevo = null): void
{
    $st = $pdo->prepare('INSERT INTO auditoria (usuario_id, entidad, registro_id, accion, motivo, valor_nuevo)
        VALUES (:u, :e, :r, :a, :m, CAST(:n AS jsonb))');
    $st->execute([
        'u' => $usuarioId, 'e' => $entidad, 'r' => $registroId, 'a' => $accion, 'm' => $motivo,
        'n' => $nuevo === null ? null : json_encode($nuevo, JSON_UNESCAPED_UNICODE),
    ]);
}
