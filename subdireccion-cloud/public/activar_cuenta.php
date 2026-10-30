<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/auth.php';

$user = require_login($pdo);
require_role($user, ['SUBDIRECTORA']);

function generar_contrasena(): string
{
    // Sin caracteres ambiguos (0/O, 1/l/I) para dictarla o escribirla sin errores.
    $alfabeto = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $out = '';
    for ($i = 0; $i < 4; $i++) {
        if ($i) $out .= '-';
        for ($j = 0; $j < 4; $j++) $out .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
    }
    return $out; // p. ej. "Hq7m-Vz3d-Kp9a-Wn2e" (19 caracteres)
}

$error = null;
$success = null;
$generada = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $targetId = (int)($_POST['usuario_id'] ?? 0);
    $accion = (string)($_POST['accion'] ?? '');
    $modo = (string)($_POST['modo'] ?? 'generar');

    $st = $pdo->prepare('SELECT u.usuario_id, u.estado, u.hash_credencial, p.nombre, u.identificador_login
        FROM usuario u JOIN persona p ON p.persona_id = u.persona_id WHERE u.usuario_id = :id');
    $st->execute(['id' => $targetId]);
    $target = $st->fetch();

    if (!$target) {
        $error = 'Selecciona una cuenta válida.';
    } elseif (!in_array($accion, ['activar', 'suspender'], true)) {
        $error = 'Acción no válida.';
    } elseif ($accion === 'suspender') {
        if ((int)$target['usuario_id'] === (int)$user['usuario_id']) {
            $error = 'No puedes suspender tu propia cuenta.';
        } else {
            $pdo->prepare("UPDATE usuario SET estado = 'Suspendido' WHERE usuario_id = :id")->execute(['id' => $targetId]);
            audit($pdo, (int)$user['usuario_id'], 'usuario', $targetId, 'SUSPENDER');
            $success = 'Cuenta de ' . $target['nombre'] . ' suspendida. Ya no podrá iniciar sesión.';
        }
    } else {
        if ($modo === 'escribir') {
            $password = (string)($_POST['password'] ?? '');
            $error = password_problem($password, (string)($_POST['password_confirm'] ?? ''));
        } else {
            $password = generar_contrasena();
        }
        if (!$error) {
            $pdo->prepare("UPDATE usuario SET hash_credencial = :h, estado = 'Activo', debe_cambiar_contrasena = TRUE WHERE usuario_id = :id")
                ->execute(['h' => password_hash($password, PASSWORD_DEFAULT), 'id' => $targetId]);
            $eraActiva = $target['estado'] === 'Activo' && !empty($target['hash_credencial']);
            audit($pdo, (int)$user['usuario_id'], 'usuario', $targetId, $eraActiva ? 'RESTABLECER_CONTRASENA' : 'ACTIVAR');
            $success = ($eraActiva ? 'Contraseña restablecida' : 'Cuenta activada') . ' para ' . $target['nombre']
                . ' (usuario: ' . $target['identificador_login'] . '). Al entrar, el sistema le pedirá definir su propia contraseña.';
            if ($modo !== 'escribir') {
                $generada = $password;
            }
        }
    }
}

$filtro = (string)($_GET['ver'] ?? 'pendientes');
$where = match ($filtro) {
    'activas' => "u.estado = 'Activo' AND u.hash_credencial IS NOT NULL",
    'todas'   => 'TRUE',
    default   => "(u.estado <> 'Activo' OR u.hash_credencial IS NULL)",
};
$cuentas = $pdo->query("SELECT u.usuario_id, u.identificador_login, u.estado, u.ultimo_acceso,
        (u.hash_credencial IS NOT NULL) AS tiene_contrasena, u.debe_cambiar_contrasena,
        p.nombre AS persona,
        string_agg(DISTINCT r.nombre, ', ' ORDER BY r.nombre) AS roles
    FROM usuario u
    JOIN persona p ON p.persona_id = u.persona_id
    LEFT JOIN usuario_rol ur ON ur.usuario_id = u.usuario_id AND ur.estado = 'Activo'
    LEFT JOIN rol r ON r.rol_id = ur.rol_id
    WHERE $where
    GROUP BY u.usuario_id, p.nombre
    ORDER BY p.nombre")->fetchAll();

render_header('Administración de cuentas', $user);
?>
<main class="content">
  <a class="back" href="index.php">← Volver al inicio</a>
  <section class="panel">
    <p class="eyebrow auth-eyebrow">ADMINISTRACIÓN DE CUENTAS</p>
    <h2>Activar o restablecer cuentas</h2>
    <p class="muted">Asigna una contraseña temporal y entrégala a la persona titular por un canal privado. Al iniciar sesión, el sistema le pedirá cambiarla.</p>
    <?php if ($error): ?><div class="auth-alert" role="alert"><?= h($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="auth-success" role="status"><?= h($success) ?></div><?php endif; ?>
    <?php if ($generada): ?>
      <div class="password-reveal" role="status">
        <span>Contraseña temporal (se muestra solo esta vez):</span>
        <code><?= h($generada) ?></code>
      </div>
    <?php endif; ?>

    <form method="post" class="auth-form form-grid" id="formCuenta">
      <?= csrf_field() ?>
      <div class="span-2">
        <label for="usuario_id">Cuenta</label>
        <select id="usuario_id" name="usuario_id" required>
          <option value="">Selecciona una cuenta…</option>
          <?php foreach ($cuentas as $a): ?>
            <option value="<?= (int)$a['usuario_id'] ?>"><?= h($a['persona']) ?> — <?= h($a['identificador_login']) ?><?= $a['roles'] ? ' — ' . h($a['roles']) : '' ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="accion">Acción</label>
        <select id="accion" name="accion">
          <option value="activar">Activar / restablecer contraseña</option>
          <option value="suspender">Suspender cuenta</option>
        </select>
      </div>
      <div id="modoWrap">
        <label for="modo">Contraseña temporal</label>
        <select id="modo" name="modo">
          <option value="generar">Generarla automáticamente (recomendado)</option>
          <option value="escribir">Escribirla yo</option>
        </select>
      </div>
      <div class="manual-pass">
        <label for="password">Contraseña (mínimo <?= PASSWORD_MIN_LEN ?> caracteres)</label>
        <input id="password" name="password" type="password" minlength="<?= PASSWORD_MIN_LEN ?>" autocomplete="new-password">
      </div>
      <div class="manual-pass">
        <label for="password_confirm">Confirmar contraseña</label>
        <input id="password_confirm" name="password_confirm" type="password" minlength="<?= PASSWORD_MIN_LEN ?>" autocomplete="new-password">
      </div>
      <div class="span-2"><button type="submit" class="auth-button">Aplicar</button></div>
    </form>
  </section>

  <section class="panel">
    <div class="section-heading">
      <h2>Cuentas</h2>
      <nav class="tabs">
        <a href="?ver=pendientes" class="<?= $filtro === 'pendientes' ? 'active' : '' ?>">Pendientes</a>
        <a href="?ver=activas" class="<?= $filtro === 'activas' ? 'active' : '' ?>">Activas</a>
        <a href="?ver=todas" class="<?= $filtro === 'todas' ? 'active' : '' ?>">Todas</a>
      </nav>
    </div>
    <?php if (!$cuentas): ?>
      <p class="empty">No hay cuentas en esta vista.</p>
    <?php else: ?>
    <div class="table-wrap"><table>
      <thead><tr><th>Persona</th><th>Usuario</th><th>Roles</th><th>Estado</th><th>Último acceso</th></tr></thead>
      <tbody>
      <?php foreach ($cuentas as $a): ?>
        <tr>
          <td><?= h($a['persona']) ?></td>
          <td><code><?= h($a['identificador_login']) ?></code></td>
          <td><?= h($a['roles'] ?? '—') ?></td>
          <td><span class="tag"><?= h($a['estado']) ?><?= $a['debe_cambiar_contrasena'] ? ' · contraseña temporal' : '' ?></span></td>
          <td><?= $a['ultimo_acceso'] ? h(date('d/m/Y H:i', strtotime((string)$a['ultimo_acceso']))) : '—' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </section>
</main>
<script>
(function () {
  var accion = document.getElementById('accion'), modo = document.getElementById('modo');
  var manual = document.querySelectorAll('.manual-pass'), modoWrap = document.getElementById('modoWrap');
  function sync() {
    var act = accion.value === 'activar', esc = act && modo.value === 'escribir';
    modoWrap.style.display = act ? '' : 'none';
    manual.forEach(function (el) {
      el.style.display = esc ? '' : 'none';
      el.querySelector('input').required = esc;
    });
  }
  accion.addEventListener('change', sync); modo.addEventListener('change', sync); sync();
  document.getElementById('formCuenta').addEventListener('submit', function (e) {
    if (accion.value === 'suspender' && !confirm('¿Suspender esta cuenta? La persona ya no podrá iniciar sesión.')) e.preventDefault();
  });
})();
</script>
<?php render_footer();
