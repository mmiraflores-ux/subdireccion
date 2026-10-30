<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/auth.php';

$user = require_login($pdo);
$verTodo = user_has_role($user, ROLES_CONSULTA);
$puedeCargar = user_has_role($user, ROLES_GESTION);

$vista    = (string)($_GET['vista'] ?? 'lista') === 'entregas' ? 'entregas' : 'lista';
$busqueda = trim((string)($_GET['buscar'] ?? ''));
$grupoId  = (int)($_GET['grupo'] ?? 0);
$soloVig  = !isset($_GET['historial']);

$grupos = $pdo->query('SELECT grupo_id, identificador FROM grupo ORDER BY identificador')->fetchAll();

$cond = [];
$params = [];
if (!$verTodo) {
    $cond[] = 'aa.docente_persona_id = :persona';
    $params['persona'] = (int)$user['persona_id'];
}
if ($grupoId > 0) {
    $cond[] = 'aa.grupo_id = :grupo';
    $params['grupo'] = $grupoId;
}

if ($vista === 'lista') {
    if ($busqueda !== '') {
        $cond[] = "(f_normaliza(pl.nombre_archivo) LIKE '%' || f_normaliza(:q1) || '%'
                 OR f_normaliza(g.identificador) LIKE '%' || f_normaliza(:q2) || '%'
                 OR f_normaliza(uc.nombre_oficial) LIKE '%' || f_normaliza(:q3) || '%'
                 OR f_normaliza(pe.nombre) LIKE '%' || f_normaliza(:q4) || '%')";
        $params += ['q1' => $busqueda, 'q2' => $busqueda, 'q3' => $busqueda, 'q4' => $busqueda];
    }
    if ($soloVig) {
        $cond[] = 'pl.es_vigente';
    }
    $sql = "SELECT pl.planeacion_id, pl.nombre_archivo, pl.estado, pl.resultado_revision,
                   pl.es_vigente, pl.fecha_carga, pl.tipo_documento,
                   pa.numero AS numero_parcial, pi.codigo AS periodo_codigo,
                   pi.nombre AS periodo_nombre, ce.nombre AS ciclo_nombre,
                   g.identificador AS grupo, uc.nombre_oficial AS asignatura,
                   pe.nombre AS docente, arch.tamano_bytes
            FROM planeacion pl
            JOIN asignacion_academica aa ON aa.asignacion_id = pl.asignacion_id
            JOIN grupo g ON g.grupo_id = aa.grupo_id
            JOIN unidad_curricular uc ON uc.unidad_curricular_id = aa.unidad_curricular_id
            JOIN persona pe ON pe.persona_id = aa.docente_persona_id
            LEFT JOIN planeacion_archivo arch ON arch.planeacion_id = pl.planeacion_id
            LEFT JOIN parcial pa ON pa.parcial_id = pl.parcial_id
            LEFT JOIN periodo_institucional pi ON pi.periodo_id = COALESCE(pa.periodo_id, aa.periodo_id)
            LEFT JOIN ciclo_escolar ce ON ce.ciclo_id = pi.ciclo_id"
        . ($cond ? ' WHERE ' . implode(' AND ', $cond) : '')
        . ' ORDER BY ce.nombre DESC, pi.codigo, g.identificador, uc.nombre_oficial, pl.tipo_documento DESC, pa.numero, pl.fecha_carga DESC
            LIMIT 500';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $planeaciones = $st->fetchAll();
} else {
    // Seguimiento de entregas: una fila por asignación vigente, columnas por tipo de entrega.
    $cond[] = "aa.estado = 'Vigente'";
    $sql = "SELECT aa.asignacion_id, g.identificador AS grupo, uc.nombre_oficial AS asignatura, pe.nombre AS docente,
                   pi.nombre AS periodo,
                   bool_or(pl.tipo_documento = 'SEMESTRAL') AS semestral,
                   array_remove(array_agg(DISTINCT pa.numero ORDER BY pa.numero), NULL) AS parciales_entregados,
                   (SELECT COUNT(*) FROM parcial x WHERE x.periodo_id = aa.periodo_id) AS parciales_total
            FROM asignacion_academica aa
            JOIN grupo g ON g.grupo_id = aa.grupo_id
            JOIN unidad_curricular uc ON uc.unidad_curricular_id = aa.unidad_curricular_id
            JOIN persona pe ON pe.persona_id = aa.docente_persona_id
            JOIN periodo_institucional pi ON pi.periodo_id = aa.periodo_id
            LEFT JOIN planeacion pl ON pl.asignacion_id = aa.asignacion_id AND pl.es_vigente
            LEFT JOIN parcial pa ON pa.parcial_id = pl.parcial_id
            WHERE " . implode(' AND ', $cond) . "
            GROUP BY aa.asignacion_id, g.identificador, uc.nombre_oficial, pe.nombre, pi.nombre
            ORDER BY g.identificador, uc.nombre_oficial";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $entregas = $st->fetchAll();
    $maxParciales = 0;
    foreach ($entregas as &$e) {
        $e['parciales_entregados'] = $e['parciales_entregados'] === '{}' ? [] : array_map('intval', explode(',', trim((string)$e['parciales_entregados'], '{}')));
        $maxParciales = max($maxParciales, (int)$e['parciales_total']);
    }
    unset($e);
}

function qs(array $over): string
{
    $q = array_merge($_GET, $over);
    $q = array_filter($q, static fn($v) => $v !== null && $v !== '');
    return 'planeaciones.php' . ($q ? '?' . http_build_query($q) : '');
}

render_header('Planeaciones', $user);
?>
<main class="content wide">
  <a class="back" href="index.php">← Volver al inicio</a>
  <section class="panel">
    <h2><?= $verTodo ? 'Planeaciones' : 'Mis planeaciones' ?></h2>
    <p class="muted"><?= $verTodo ? 'Consulta las planeaciones registradas por grupo, asignatura, docente o nombre del archivo.' : 'Planeaciones registradas para las asignaturas que tienes asignadas.' ?></p>
    <div class="top-actions">
      <nav class="tabs">
        <a href="<?= h(qs(['vista' => null])) ?>" class="<?= $vista === 'lista' ? 'active' : '' ?>">Listado de documentos</a>
        <a href="<?= h(qs(['vista' => 'entregas'])) ?>" class="<?= $vista === 'entregas' ? 'active' : '' ?>">Seguimiento de entregas</a>
      </nav>
      <?php if ($puedeCargar): ?><a class="btn" href="carga_masiva_planeaciones.php">+ Carga masiva de PDF</a><?php endif; ?>
    </div>
    <form class="search-form" method="get" action="planeaciones.php">
      <?php if ($vista === 'entregas'): ?><input type="hidden" name="vista" value="entregas"><?php endif; ?>
      <?php if ($vista === 'lista'): ?>
      <input type="search" name="buscar" value="<?= h($busqueda) ?>" placeholder="Ej. 502, Inglés V, nombre del docente o del archivo" aria-label="Buscar planeaciones">
      <?php endif; ?>
      <select name="grupo" aria-label="Grupo">
        <option value="">Todos los grupos</option>
        <?php foreach ($grupos as $g): ?><option value="<?= (int)$g['grupo_id'] ?>" <?= $grupoId === (int)$g['grupo_id'] ? 'selected' : '' ?>>Grupo <?= h($g['identificador']) ?></option><?php endforeach; ?>
      </select>
      <?php if ($vista === 'lista'): ?>
      <label class="check"><input type="checkbox" name="historial" value="1" <?= $soloVig ? '' : 'checked' ?>> Incluir versiones anteriores</label>
      <?php endif; ?>
      <button type="submit">Filtrar</button>
      <a class="btn btn-secondary" href="<?= $vista === 'entregas' ? 'planeaciones.php?vista=entregas' : 'planeaciones.php' ?>">Limpiar</a>
    </form>
  </section>

  <?php if ($vista === 'lista'): ?>
  <section class="panel">
    <p class="muted">Planeaciones encontradas</p><div class="count"><?= count($planeaciones) ?></div>
    <?php if (!$planeaciones): ?>
      <div class="notice"><strong>No se encontraron planeaciones.</strong><p><?= $puedeCargar ? 'Cuando registres el primer lote desde «Carga masiva de PDF», aparecerá aquí.' : 'Cuando la Subdirección registre tus planeaciones, aparecerán aquí.' ?></p></div>
    <?php else: ?>
    <div class="table-wrap"><table>
      <thead><tr><th>Ciclo / periodo</th><th>Entrega</th><th>Grupo</th><th>Asignatura</th><th>Docente</th><th>Archivo</th><th>Estado</th><th>Revisión</th><th>Cargada</th><th>PDF</th></tr></thead>
      <tbody>
      <?php foreach ($planeaciones as $p): ?>
        <tr class="<?= $p['es_vigente'] ? '' : 'row-old' ?>">
          <td><?= h($p['ciclo_nombre']) ?><br><span class="muted small">Periodo <?= h($p['periodo_codigo'] ?? '') ?> — <?= h($p['periodo_nombre'] ?? '') ?></span></td>
          <td><?= $p['tipo_documento'] === 'SEMESTRAL' ? 'Semestral' : 'Parcial ' . (int)$p['numero_parcial'] ?><?= $p['es_vigente'] ? '' : '<br><span class="tag tag-muted">Versión anterior</span>' ?></td>
          <td><?= h($p['grupo']) ?></td>
          <td><?= h($p['asignatura']) ?></td>
          <td><?= h($p['docente']) ?></td>
          <td class="filename"><?= h($p['nombre_archivo']) ?><?php if ($p['tamano_bytes']): ?><br><span class="muted small"><?= tamano_legible((int)$p['tamano_bytes']) ?></span><?php endif; ?></td>
          <td><span class="tag"><?= h($p['estado']) ?></span></td>
          <td><?= h($p['resultado_revision']) ?></td>
          <td><?= h(date('d/m/Y H:i', strtotime((string)$p['fecha_carga']))) ?></td>
          <td class="nowrap"><a href="ver_planeacion.php?id=<?= (int)$p['planeacion_id'] ?>" target="_blank" rel="noopener">Ver PDF</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </section>
  <?php else: ?>
  <section class="panel">
    <?php
      $totalCeldas = 0; $entregadas = 0;
      foreach ($entregas as $e) { $totalCeldas += 1 + (int)$e['parciales_total']; $entregadas += ($e['semestral'] ? 1 : 0) + count($e['parciales_entregados']); }
    ?>
    <p class="muted">Avance de entregas (semestral + parciales)</p>
    <div class="count"><?= $totalCeldas ? round($entregadas * 100 / $totalCeldas) : 0 ?>% <span class="muted small"><?= $entregadas ?> de <?= $totalCeldas ?> documentos esperados</span></div>
    <?php if (!$entregas): ?>
      <div class="notice"><strong>No hay asignaciones vigentes con estos filtros.</strong></div>
    <?php else: ?>
    <div class="table-wrap"><table class="coverage">
      <thead><tr><th>Grupo</th><th>Asignatura</th><th>Docente</th><th>Semestral</th><?php for ($i = 1; $i <= $maxParciales; $i++): ?><th>Parcial <?= $i ?></th><?php endfor; ?></tr></thead>
      <tbody>
      <?php foreach ($entregas as $e): ?>
        <tr>
          <td><?= h($e['grupo']) ?></td>
          <td><?= h($e['asignatura']) ?></td>
          <td><?= h($e['docente']) ?></td>
          <td><?= $e['semestral'] ? '<span class="ok" title="Entregada">✓</span>' : '<span class="missing" title="Sin entregar">—</span>' ?></td>
          <?php for ($i = 1; $i <= $maxParciales; $i++): ?>
            <td><?php if ($i > (int)$e['parciales_total']): ?><span class="muted">n/a</span><?php elseif (in_array($i, $e['parciales_entregados'], true)): ?><span class="ok" title="Entregada">✓</span><?php else: ?><span class="missing" title="Sin entregar">—</span><?php endif; ?></td>
          <?php endfor; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </section>
  <?php endif; ?>
  <p class="muted small">Toda carga de archivos se valida y requiere confirmación antes de registrarse.</p>
</main>
<?php render_footer();
