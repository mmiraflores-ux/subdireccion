<?php
declare(strict_types=1);

/** Encabezado institucional común a todas las pantallas. */
function render_header(string $titulo, ?array $user): void
{
    ?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($titulo) ?> | <?= h(APP_NOMBRE) ?></title>
<link rel="stylesheet" href="assets/styles.css?v=2">
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='7' fill='%2317324d'/%3E%3Ctext x='16' y='22' font-size='18' text-anchor='middle' fill='white' font-family='Arial' font-weight='700'%3ES%3C/text%3E%3C/svg%3E">
</head>
<body>
<header class="topbar">
  <a class="brand-mark" href="index.php" aria-label="Inicio">S</a>
  <div class="topbar-title">
    <p class="eyebrow">PLATAFORMA INSTITUCIONAL</p>
    <h1><?= h(APP_NOMBRE) ?></h1>
    <p class="institution"><?= h(APP_INSTITUCION) ?></p>
  </div>
  <?php if ($user): ?>
  <div class="topbar-user">
    <span><?= h($user['nombre_persona']) ?></span>
    <div class="topbar-actions">
      <a class="logout-button" href="cambiar_contrasena.php">Cambiar contraseña</a>
      <form method="post" action="logout.php">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <button type="submit" class="logout-button">Cerrar sesión</button>
      </form>
    </div>
  </div>
  <?php endif; ?>
</header>
<?php
}

function render_footer(): void
{
    echo '<footer class="site-footer"><p>' . h(APP_NOMBRE) . ' · ' . h(APP_INSTITUCION) . '</p></footer></body></html>';
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $sent  = (string)($_POST['csrf_token'] ?? '');
    $saved = (string)($_SESSION['csrf_token'] ?? '');
    if ($sent === '' || $saved === '' || !hash_equals($saved, $sent)) {
        fatal_page(419, 'Formulario vencido', 'La sesión del formulario venció o el archivo enviado era demasiado grande. Regresa e inténtalo nuevamente.');
    }
}
