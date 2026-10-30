<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/auth.php';

/*
 * Configuración inicial / recuperación de la cuenta de la Subdirectora.
 *
 * Sustituye la restricción "solo desde localhost" del sistema original, que
 * en la nube hacía imposible el primer acceso. Solo funciona cuando la
 * variable de entorno SETUP_TOKEN está definida (mínimo 16 caracteres) y se
 * escribe ese mismo valor en el formulario. Después de usarla, elimina
 * SETUP_TOKEN de la configuración del servidor para desactivar esta página.
 */

$token = env_str('SETUP_TOKEN');
if (strlen($token) < 16) {
    fatal_page(404, 'Página no disponible', 'La configuración inicial está desactivada. Para habilitarla, define la variable SETUP_TOKEN en el servidor.');
}

$cuentas = $pdo->query("SELECT DISTINCT u.usuario_id, u.identificador_login, u.estado, p.nombre
    FROM usuario u
    JOIN persona p ON p.persona_id = u.persona_id
    JOIN usuario_rol ur ON ur.usuario_id = u.usuario_id AND ur.estado = 'Activo'
    JOIN rol r ON r.rol_id = ur.rol_id AND r.codigo = 'SUBDIRECTORA'
    ORDER BY p.nombre")->fetchAll();

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $clave = ['u:setup', 'ip:' . client_ip()];
    $st = $pdo->prepare("SELECT COUNT(*) FROM intento_login WHERE clave = :c AND NOT exitoso AND fecha > now() - interval '15 minutes'");
    $st->execute(['c' => $clave[1]]);
    $bloqueado = (int)$st->fetchColumn() >= 5;

    $targetId = (int)($_POST['usuario_id'] ?? 0);
    $ids = array_map(static fn(array $c): int => (int)$c['usuario_id'], $cuentas);

    if ($bloqueado) {
        $error = 'Demasiados intentos. Espera 15 minutos.';
    } elseif (!hash_equals($token, (string)($_POST['setup_token'] ?? ''))) {
        $pdo->prepare('INSERT INTO intento_login (clave, exitoso) VALUES (:c, FALSE)')->execute(['c' => $clave[1]]);
        $error = 'El código de configuración no es correcto.';
    } elseif (!in_array($targetId, $ids, true)) {
        $error = 'Selecciona la cuenta de la Subdirectora.';
    } elseif ($p = password_problem((string)($_POST['password'] ?? ''), (string)($_POST['password_confirm'] ?? ''))) {
        $error = $p;
    } else {
        $pdo->prepare("UPDATE usuario SET hash_credencial = :h, estado = 'Activo', debe_cambiar_contrasena = FALSE WHERE usuario_id = :id")
            ->execute(['h' => password_hash((string)$_POST['password'], PASSWORD_DEFAULT), 'id' => $targetId]);
        audit($pdo, null, 'usuario', $targetId, 'CONFIGURACION_INICIAL', 'Contraseña definida con SETUP_TOKEN');
        $success = 'Listo. Ya puedes iniciar sesión. Recuerda eliminar la variable SETUP_TOKEN del servidor.';
    }
}

render_header('Configuración inicial', null);
?>
<main class="auth-wrap">
  <section class="auth-card auth-card-wide">
    <p class="eyebrow auth-eyebrow">CONFIGURACIÓN INICIAL</p>
    <h2>Contraseña de la Subdirectora</h2>
    <p class="muted">Usa esta pantalla solo para el primer acceso o si se olvidó la contraseña de la Subdirectora. Necesitas el código SETUP_TOKEN configurado en el servidor.</p>
    <?php if ($error): ?><div class="auth-alert" role="alert"><?= h($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="auth-success" role="status"><?= h($success) ?> <a href="login.php">Iniciar sesión</a></div><?php else: ?>
    <form method="post" class="auth-form">
      <?= csrf_field() ?>
      <label for="setup_token">Código de configuración (SETUP_TOKEN)</label>
      <input id="setup_token" name="setup_token" type="password" required autocomplete="off">
      <label for="usuario_id">Cuenta</label>
      <select id="usuario_id" name="usuario_id" required>
        <?php foreach ($cuentas as $c): ?>
          <option value="<?= (int)$c['usuario_id'] ?>"><?= h($c['nombre']) ?> — usuario: <?= h($c['identificador_login']) ?></option>
        <?php endforeach; ?>
      </select>
      <label for="password">Nueva contraseña (mínimo <?= PASSWORD_MIN_LEN ?> caracteres)</label>
      <input id="password" name="password" type="password" minlength="<?= PASSWORD_MIN_LEN ?>" autocomplete="new-password" required>
      <label for="password_confirm">Confirmar contraseña</label>
      <input id="password_confirm" name="password_confirm" type="password" minlength="<?= PASSWORD_MIN_LEN ?>" autocomplete="new-password" required>
      <button type="submit" class="auth-button">Guardar</button>
    </form>
    <?php endif; ?>
  </section>
</main>
<?php render_footer();
