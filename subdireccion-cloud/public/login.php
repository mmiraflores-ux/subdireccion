<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/auth.php';

const MAX_INTENTOS = 5;      // intentos fallidos permitidos…
const VENTANA_MIN  = 15;     // …dentro de esta ventana de minutos

$next = safe_next((string)($_GET['next'] ?? $_POST['next'] ?? 'index.php'));

if (current_user($pdo)) {
    header('Location: ' . $next);
    exit;
}

function intentos_fallidos(PDO $pdo, string $clave): int
{
    $st = $pdo->prepare("SELECT COUNT(*) FROM intento_login
        WHERE clave = :c AND NOT exitoso AND fecha > now() - make_interval(mins => :m)");
    $st->execute(['c' => $clave, 'm' => VENTANA_MIN]);
    return (int)$st->fetchColumn();
}

function registrar_intento(PDO $pdo, array $claves, bool $ok): void
{
    $st = $pdo->prepare('INSERT INTO intento_login (clave, exitoso) VALUES (:c, :ok)');
    foreach ($claves as $c) {
        $st->bindValue('c', $c);
        $st->bindValue('ok', $ok, PDO::PARAM_BOOL);
        $st->execute();
    }
}

$error = null;
$login = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $login = trim((string)($_POST['identificador_login'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $claves = ['u:' . mb_strtolower($login), 'ip:' . client_ip()];

    if ($login === '' || $password === '') {
        $error = 'Escribe tu usuario y contraseña.';
    } elseif (intentos_fallidos($pdo, $claves[0]) >= MAX_INTENTOS || intentos_fallidos($pdo, $claves[1]) >= MAX_INTENTOS * 4) {
        $error = 'Demasiados intentos fallidos. Espera ' . VENTANA_MIN . ' minutos e inténtalo de nuevo.';
    } else {
        $stmt = $pdo->prepare('SELECT usuario_id, hash_credencial, estado FROM usuario WHERE lower(identificador_login) = lower(:login)');
        $stmt->execute(['login' => $login]);
        $account = $stmt->fetch();
        $ok = $account && $account['estado'] === 'Activo'
            && !empty($account['hash_credencial'])
            && password_verify($password, (string)$account['hash_credencial']);
        if (!$account) {
            // Tiempo de respuesta similar exista o no la cuenta.
            password_verify($password, '$2y$10$wjD8bl0mUjKt2oGJ/VzLveKVPHFAGNi5R5cnAZuYhGzHUSfK9iB3m');
        }
        registrar_intento($pdo, $claves, $ok);
        if ($ok) {
            if (password_needs_rehash((string)$account['hash_credencial'], PASSWORD_DEFAULT)) {
                $pdo->prepare('UPDATE usuario SET hash_credencial = :h WHERE usuario_id = :id')
                    ->execute(['h' => password_hash($password, PASSWORD_DEFAULT), 'id' => $account['usuario_id']]);
            }
            $pdo->prepare('UPDATE usuario SET ultimo_acceso = now() WHERE usuario_id = :id')->execute(['id' => $account['usuario_id']]);
            session_regenerate_id(true);
            $_SESSION['usuario_id'] = (int)$account['usuario_id'];
            unset($_SESSION['csrf_token']);
            header('Location: ' . $next);
            exit;
        }
        $error = 'No fue posible iniciar sesión. Verifica tus datos o solicita la activación de tu cuenta.';
    }
}

render_header('Iniciar sesión', null);
?>
<main class="auth-wrap">
  <section class="auth-card">
    <p class="eyebrow auth-eyebrow">ACCESO INSTITUCIONAL</p>
    <h2>Iniciar sesión</h2>
    <p class="muted">Ingresa con el usuario asignado y tu contraseña.</p>
    <?php if ($error): ?><div class="auth-alert" role="alert"><?= h($error) ?></div><?php endif; ?>
    <form method="post" action="login.php" class="auth-form" autocomplete="on">
      <?= csrf_field() ?>
      <input type="hidden" name="next" value="<?= h($next) ?>">
      <label for="identificador_login">Usuario</label>
      <input id="identificador_login" name="identificador_login" type="text" value="<?= h($login) ?>" autocomplete="username" required maxlength="150" autofocus>
      <label for="password">Contraseña</label>
      <input id="password" name="password" type="password" autocomplete="current-password" required>
      <button type="submit" class="auth-button">Entrar</button>
    </form>
    <p class="auth-help">¿Tu cuenta aún no está activada o olvidaste tu contraseña? Solicita a la Subdirección que la habilite o la restablezca.</p>
  </section>
</main>
<?php render_footer();
