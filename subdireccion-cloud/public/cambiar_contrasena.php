<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/auth.php';

$user = require_login($pdo, true);
$obligatorio = (bool)$user['debe_cambiar_contrasena'];
$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $actual = (string)($_POST['actual'] ?? '');
    $nueva = (string)($_POST['nueva'] ?? '');
    $confirm = (string)($_POST['confirmar'] ?? '');

    $st = $pdo->prepare('SELECT hash_credencial FROM usuario WHERE usuario_id = :id');
    $st->execute(['id' => $user['usuario_id']]);
    $hash = (string)$st->fetchColumn();

    if (!password_verify($actual, $hash)) {
        $error = 'La contraseña actual no es correcta.';
    } elseif ($p = password_problem($nueva, $confirm)) {
        $error = $p;
    } elseif (hash_equals($actual, $nueva)) {
        $error = 'La nueva contraseña debe ser distinta de la actual.';
    } else {
        $pdo->prepare('UPDATE usuario SET hash_credencial = :h, debe_cambiar_contrasena = FALSE WHERE usuario_id = :id')
            ->execute(['h' => password_hash($nueva, PASSWORD_DEFAULT), 'id' => $user['usuario_id']]);
        session_regenerate_id(true);
        audit($pdo, (int)$user['usuario_id'], 'usuario', (int)$user['usuario_id'], 'CAMBIO_CONTRASENA');
        $success = 'Tu contraseña se actualizó correctamente.';
        $obligatorio = false;
    }
}

render_header('Cambiar contraseña', $user);
?>
<main class="auth-wrap">
  <section class="auth-card">
    <p class="eyebrow auth-eyebrow">SEGURIDAD DE LA CUENTA</p>
    <h2>Cambiar contraseña</h2>
    <?php if ($obligatorio): ?>
      <div class="auth-alert" role="status">Tu contraseña fue asignada por la Subdirección. Por seguridad, define una contraseña personal antes de continuar.</div>
    <?php else: ?>
      <p class="muted">Usa al menos <?= PASSWORD_MIN_LEN ?> caracteres. Una frase fácil de recordar para ti y difícil de adivinar para otros es una buena opción.</p>
    <?php endif; ?>
    <?php if ($error): ?><div class="auth-alert" role="alert"><?= h($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="auth-success" role="status"><?= h($success) ?> <a href="index.php">Ir al inicio</a></div><?php endif; ?>
    <?php if (!$success): ?>
    <form method="post" class="auth-form">
      <?= csrf_field() ?>
      <label for="actual">Contraseña actual</label>
      <input id="actual" name="actual" type="password" autocomplete="current-password" required>
      <label for="nueva">Nueva contraseña (mínimo <?= PASSWORD_MIN_LEN ?> caracteres)</label>
      <input id="nueva" name="nueva" type="password" minlength="<?= PASSWORD_MIN_LEN ?>" autocomplete="new-password" required>
      <label for="confirmar">Confirmar nueva contraseña</label>
      <input id="confirmar" name="confirmar" type="password" minlength="<?= PASSWORD_MIN_LEN ?>" autocomplete="new-password" required>
      <button type="submit" class="auth-button">Guardar contraseña</button>
    </form>
    <?php endif; ?>
    <?php if (!$obligatorio): ?><p class="auth-help"><a href="index.php">Volver al sistema</a></p><?php endif; ?>
  </section>
</main>
<?php render_footer();
