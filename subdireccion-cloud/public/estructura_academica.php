<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/auth.php';

$user = require_login($pdo);

$ciclos    = $pdo->query('SELECT ciclo_id, nombre, estado FROM ciclo_escolar ORDER BY nombre DESC')->fetchAll();
$periodos  = $pdo->query('SELECT p.periodo_id, p.codigo, p.nombre, p.fecha_inicio, p.fecha_fin, c.nombre AS ciclo_nombre,
        (SELECT COUNT(*) FROM parcial pa WHERE pa.periodo_id = p.periodo_id) AS parciales
    FROM periodo_institucional p JOIN ciclo_escolar c ON c.ciclo_id = p.ciclo_id
    ORDER BY c.nombre DESC, p.codigo')->fetchAll();
$parciales = $pdo->query('SELECT pa.numero, pa.fecha_inicio, pa.fecha_fin, pa.fecha_evaluacion, p.nombre AS periodo, p.codigo
    FROM parcial pa JOIN periodo_institucional p ON p.periodo_id = pa.periodo_id
    ORDER BY p.codigo, pa.numero')->fetchAll();
$semestres = $pdo->query('SELECT semestre_id, numero, nombre FROM semestre_academico ORDER BY numero')->fetchAll();
$grados    = $pdo->query('SELECT grado_id, nombre FROM grado ORDER BY grado_id')->fetchAll();
$grupos    = $pdo->query("SELECT g.grupo_id, g.identificador, g.carrera, g.turno, c.nombre AS ciclo_nombre,
        s.nombre AS semestre_nombre, gr.nombre AS grado_nombre,
        (SELECT COUNT(*) FROM asignacion_academica aa WHERE aa.grupo_id = g.grupo_id AND aa.estado = 'Vigente') AS asignaturas
    FROM grupo g
    JOIN ciclo_escolar c ON c.ciclo_id = g.ciclo_id
    JOIN semestre_academico s ON s.semestre_id = g.semestre_id
    JOIN grado gr ON gr.grado_id = g.grado_id
    ORDER BY c.nombre DESC, g.identificador")->fetchAll();

function fecha_corta(?string $f): string
{
    return $f ? date('d/m/Y', strtotime($f)) : '—';
}

render_header('Estructura académica', $user);
?>
<main class="content">
  <a class="back" href="index.php">← Volver al inicio</a>
  <section class="page-head"><h2>Estructura académica</h2><p class="muted">Consulta de los catálogos institucionales instalados.</p></section>

  <div class="catalog-grid">
    <section class="panel"><h3>Ciclos escolares</h3><div class="count"><?= count($ciclos) ?></div>
      <?php if (!$ciclos): ?><p class="empty">Todavía no hay ciclos registrados.</p><?php else: ?>
      <div class="table-wrap"><table><thead><tr><th>Ciclo</th><th>Estado</th></tr></thead><tbody>
      <?php foreach ($ciclos as $r): ?><tr><td><?= h($r['nombre']) ?></td><td><?= h($r['estado']) ?></td></tr><?php endforeach; ?>
      </tbody></table></div><?php endif; ?>
    </section>

    <section class="panel"><h3>Periodos institucionales</h3><div class="count"><?= count($periodos) ?></div>
      <?php if (!$periodos): ?><p class="empty">Todavía no hay periodos registrados.</p><?php else: ?>
      <div class="table-wrap"><table><thead><tr><th>Ciclo</th><th>Periodo</th><th>Fechas</th><th>Parciales</th></tr></thead><tbody>
      <?php foreach ($periodos as $r): ?><tr><td><?= h($r['ciclo_nombre']) ?></td><td><?= h($r['nombre']) ?> (<?= h($r['codigo']) ?>)</td><td><?= fecha_corta($r['fecha_inicio']) ?> a <?= fecha_corta($r['fecha_fin']) ?></td><td><?= (int)$r['parciales'] ?></td></tr><?php endforeach; ?>
      </tbody></table></div><?php endif; ?>
    </section>

    <section class="panel"><h3>Parciales</h3><div class="count"><?= count($parciales) ?></div>
      <?php if (!$parciales): ?><p class="empty">Todavía no hay parciales registrados.</p><?php else: ?>
      <div class="table-wrap"><table><thead><tr><th>Periodo</th><th>Parcial</th><th>Del</th><th>Al</th><th>Evaluación</th></tr></thead><tbody>
      <?php foreach ($parciales as $r): ?><tr><td><?= h($r['periodo']) ?> (<?= h($r['codigo']) ?>)</td><td><?= (int)$r['numero'] ?></td><td><?= fecha_corta($r['fecha_inicio']) ?></td><td><?= fecha_corta($r['fecha_fin']) ?></td><td><?= fecha_corta($r['fecha_evaluacion']) ?></td></tr><?php endforeach; ?>
      </tbody></table></div><?php endif; ?>
    </section>

    <section class="panel"><h3>Semestres y grados</h3><div class="count"><?= count($semestres) ?> · <?= count($grados) ?></div>
      <div class="table-wrap"><table><thead><tr><th>Semestres</th><th>Grados</th></tr></thead><tbody>
      <?php for ($i = 0, $n = max(count($semestres), count($grados)); $i < $n; $i++): ?>
        <tr><td><?= isset($semestres[$i]) ? h($semestres[$i]['nombre']) : '' ?></td><td><?= isset($grados[$i]) ? h($grados[$i]['nombre']) : '' ?></td></tr>
      <?php endfor; ?>
      </tbody></table></div>
    </section>
  </div>

  <section class="panel"><h3>Grupos registrados</h3>
    <?php if (!$grupos): ?><p class="empty">Todavía no hay grupos registrados.</p><?php else: ?>
    <div class="table-wrap"><table><thead><tr><th>Grupo</th><th>Ciclo</th><th>Semestre</th><th>Grado</th><th>Carrera</th><th>Turno</th><th>Asignaturas vigentes</th></tr></thead><tbody>
    <?php foreach ($grupos as $r): ?><tr><td><strong><?= h($r['identificador']) ?></strong></td><td><?= h($r['ciclo_nombre']) ?></td><td><?= h($r['semestre_nombre']) ?></td><td><?= h($r['grado_nombre']) ?></td><td><?= h($r['carrera']) ?></td><td><?= h($r['turno'] ?? '—') ?></td><td><?= (int)$r['asignaturas'] ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
  </section>
</main>
<?php render_footer();
