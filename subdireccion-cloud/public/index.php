<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/auth.php';

$user = require_login($pdo);
$roles = array_map(static fn(array $r): string => $r['nombre'], $user['roles']);
$esGestion = user_has_role($user, ROLES_CONSULTA);

// Indicadores rápidos
if ($esGestion) {
    $kpi = $pdo->query("SELECT
        (SELECT COUNT(*) FROM asignacion_academica WHERE estado = 'Vigente') AS asignaciones,
        (SELECT COUNT(*) FROM planeacion WHERE es_vigente) AS planeaciones,
        (SELECT COUNT(*) FROM planeacion WHERE es_vigente AND resultado_revision = 'Pendiente') AS pendientes,
        (SELECT COUNT(*) FROM usuario WHERE estado = 'Activo' AND hash_credencial IS NOT NULL) AS cuentas")->fetch();
} else {
    $st = $pdo->prepare("SELECT
        (SELECT COUNT(*) FROM asignacion_academica WHERE estado = 'Vigente' AND docente_persona_id = :p) AS asignaciones,
        (SELECT COUNT(*) FROM planeacion pl JOIN asignacion_academica aa ON aa.asignacion_id = pl.asignacion_id
            WHERE pl.es_vigente AND aa.docente_persona_id = :p) AS planeaciones,
        (SELECT COUNT(*) FROM planeacion pl JOIN asignacion_academica aa ON aa.asignacion_id = pl.asignacion_id
            WHERE pl.es_vigente AND aa.docente_persona_id = :p AND pl.resultado_revision = 'Pendiente') AS pendientes");
    $st->execute(['p' => $user['persona_id']]);
    $kpi = $st->fetch();
}

render_header('Inicio', $user);
?>
<main class="container">
  <section class="welcome">
    <span class="status"><span class="status-dot"></span>Sesión iniciada</span>
    <h2>Acompañamiento pedagógico y concreción áulica</h2>
    <p>Bienvenida/o, <?= h($user['nombre_persona']) ?>. Este espacio relaciona la planeación didáctica con la práctica observada y el seguimiento de acuerdos de mejora.</p>
    <div class="user-role-line"><strong>Roles:</strong> <?= h(implode(' · ', $roles) ?: 'Sin roles activos') ?></div>
  </section>

  <section class="kpi-row" aria-label="Indicadores">
    <div class="kpi"><span class="kpi-value"><?= (int)$kpi['asignaciones'] ?></span><span class="kpi-label"><?= $esGestion ? 'Asignaciones vigentes' : 'Mis asignaciones' ?></span></div>
    <div class="kpi"><span class="kpi-value"><?= (int)$kpi['planeaciones'] ?></span><span class="kpi-label">Planeaciones vigentes</span></div>
    <div class="kpi"><span class="kpi-value"><?= (int)$kpi['pendientes'] ?></span><span class="kpi-label">Pendientes de revisión</span></div>
    <?php if ($esGestion): ?><div class="kpi"><span class="kpi-value"><?= (int)$kpi['cuentas'] ?></span><span class="kpi-label">Cuentas activas</span></div><?php endif; ?>
  </section>

  <section class="section-heading"><h2>Áreas de trabajo</h2><p>Los módulos se habilitan de manera gradual.</p></section>
  <section class="module-grid" aria-label="Módulos de la plataforma">
    <article class="module-card"><span class="module-number">01</span><h3>Estructura académica</h3><p>Consulta ciclos, periodos, semestres, grados y grupos.</p><a href="estructura_academica.php">Consultar estructura académica →</a><span class="module-state">Consulta disponible</span></article>
    <article class="module-card"><span class="module-number">02</span><h3><?= $esGestion ? 'Planeaciones' : 'Mis planeaciones' ?></h3><p><?= $esGestion ? 'Consulta, recepción y carga masiva de planeaciones PDF.' : 'Consulta las planeaciones registradas de tus asignaturas.' ?></p><a href="planeaciones.php">Consultar planeaciones →</a><span class="module-state">Disponible</span></article>
    <article class="module-card"><span class="module-number">03</span><h3>Acompañamiento</h3><p>Sesiones, observaciones, concreción áulica y evidencias.</p><span class="module-state">En desarrollo</span></article>
    <article class="module-card"><span class="module-number">04</span><h3>Reflexión y acuerdos</h3><p>Diálogo pedagógico, compromisos y seguimiento.</p><span class="module-state">En desarrollo</span></article>
    <?php if (user_has_role($user, ['SUBDIRECTORA'])): ?>
    <article class="module-card"><span class="module-number">05</span><h3>Administración de cuentas</h3><p>Activación de usuarios, contraseñas temporales y suspensión de cuentas.</p><a href="activar_cuenta.php">Administrar cuentas →</a><span class="module-state">Disponible para Subdirección</span></article>
    <?php endif; ?>
  </section>
</main>
<?php render_footer();
