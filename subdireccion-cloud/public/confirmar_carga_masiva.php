<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/auth.php';

$user = require_login($pdo);
require_role($user, ROLES_GESTION);

$lote = $_SESSION['carga_lote'] ?? null;
$mensaje = $_SESSION['carga_mensaje'] ?? null;
unset($_SESSION['carga_mensaje']);

if (!$mensaje && (!$lote || (int)$lote['usuario_id'] !== (int)$user['usuario_id'])) {
    unset($_SESSION['carga_lote']);
    fatal_page(410, 'Sin lote pendiente', 'No hay un lote pendiente de confirmar. Regresa a la carga masiva para seleccionar los archivos.');
}

if ($mensaje) {
    render_header('Lote registrado', $user);
    echo '<main class="content"><section class="panel"><h2>Lote registrado</h2><p class="success">' . h($mensaje)
        . '</p><div class="actions"><a class="btn" href="planeaciones.php">Consultar planeaciones</a><a class="btn btn-secondary" href="carga_masiva_planeaciones.php">Cargar otro lote</a></div></section></main>';
    render_footer();
    exit;
}

$loteId = (string)$lote['id'];

$st = $pdo->prepare('SELECT identificador FROM grupo WHERE grupo_id = :id');
$st->execute(['id' => (int)$lote['grupo_id']]);
$grupo = (string)$st->fetchColumn();
$st = $pdo->prepare('SELECT pi.codigo, pi.nombre, ce.nombre AS ciclo FROM periodo_institucional pi JOIN ciclo_escolar ce ON ce.ciclo_id = pi.ciclo_id WHERE pi.periodo_id = :id');
$st->execute(['id' => (int)$lote['periodo_id']]);
$periodo = $st->fetch() ?: ['codigo' => '', 'nombre' => '', 'ciclo' => ''];
$parcialNombre = 'Semestral (no exclusiva de un parcial)';
if ($lote['parcial_id']) {
    $st = $pdo->prepare('SELECT numero FROM parcial WHERE parcial_id = :id AND periodo_id = :periodo');
    $st->execute(['id' => (int)$lote['parcial_id'], 'periodo' => (int)$lote['periodo_id']]);
    $n = $st->fetchColumn();
    if ($n === false) fatal_page(400, 'Parcial inválido', 'El parcial no corresponde al periodo seleccionado.');
    $parcialNombre = 'Parcial ' . $n;
}

// Corrección: el original no filtraba por periodo y mostraba asignaciones de otros periodos.
$st = $pdo->prepare("SELECT aa.asignacion_id, uc.nombre_oficial AS asignatura, pe.nombre AS docente,
        EXISTS (SELECT 1 FROM planeacion pl WHERE pl.asignacion_id = aa.asignacion_id AND pl.es_vigente
                AND pl.tipo_documento = :tipo AND pl.parcial_id IS NOT DISTINCT FROM CAST(:parcial AS bigint)) AS ya_tiene
    FROM asignacion_academica aa
    JOIN unidad_curricular uc ON uc.unidad_curricular_id = aa.unidad_curricular_id
    JOIN persona pe ON pe.persona_id = aa.docente_persona_id
    WHERE aa.grupo_id = :grupo AND aa.periodo_id = :periodo AND aa.estado = 'Vigente'
    ORDER BY uc.nombre_oficial, pe.nombre");
$st->bindValue('tipo', $lote['tipo_documento']);
$st->bindValue('parcial', $lote['parcial_id'], $lote['parcial_id'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
$st->bindValue('grupo', (int)$lote['grupo_id'], PDO::PARAM_INT);
$st->bindValue('periodo', (int)$lote['periodo_id'], PDO::PARAM_INT);
$st->execute();
$asignaciones = [];
foreach ($st->fetchAll() as $a) $asignaciones[(int)$a['asignacion_id']] = $a;

$st = $pdo->prepare('SELECT cp.orden, cp.nombre_original, cp.ruta_relativa, cp.sha256, cp.tamano_bytes,
        EXISTS (SELECT 1 FROM planeacion_archivo_hash h WHERE h.sha256 = cp.sha256) AS duplicado_bd,
        COUNT(*) OVER (PARTITION BY cp.sha256) > 1 AS duplicado_lote
    FROM carga_pendiente cp WHERE cp.lote_id = :l AND cp.usuario_id = :u ORDER BY cp.orden');
$st->execute(['l' => $loteId, 'u' => $user['usuario_id']]);
$items = $st->fetchAll();
if (!$items) {
    unset($_SESSION['carga_lote']);
    fatal_page(410, 'Lote vencido', 'Los archivos temporales de este lote ya no están disponibles (se eliminan a las 24 horas). Vuelve a cargarlos.');
}

$errores = [];
$elegidas = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (($_POST['accion'] ?? '') === 'cancelar') {
        $pdo->prepare('DELETE FROM carga_pendiente WHERE lote_id = :l')->execute(['l' => $loteId]);
        unset($_SESSION['carga_lote']);
        header('Location: carga_masiva_planeaciones.php');
        exit;
    }

    $map = (array)($_POST['asignacion'] ?? []);
    foreach ($items as $it) {
        $elegidas[(int)$it['orden']] = (int)($map[(int)$it['orden']] ?? 0);
    }
    foreach ($items as $it) {
        $o = (int)$it['orden'];
        if (!isset($asignaciones[$elegidas[$o]])) {
            $errores[] = 'Asigna una materia válida a «' . $it['nombre_original'] . '».';
        }
        if ($it['duplicado_bd']) $errores[] = '«' . $it['nombre_original'] . '» ya está registrado en el sistema (archivo idéntico). Cancela el lote y quítalo.';
    }
    $conteo = array_count_values(array_filter($elegidas));
    foreach ($conteo as $aid => $n) {
        if ($n > 1 && isset($asignaciones[$aid])) {
            $errores[] = 'Elegiste la misma asignatura (' . $asignaciones[$aid]['asignatura'] . ') para ' . $n . ' archivos. Cada asignatura admite un documento por entrega.';
        }
    }
    $errores = array_values(array_unique($errores));

    if (!$errores) {
        try {
            $pdo->beginTransaction();
            $prev = $pdo->prepare('SELECT planeacion_id FROM planeacion WHERE asignacion_id = :a AND es_vigente
                AND tipo_documento = :t AND parcial_id IS NOT DISTINCT FROM CAST(:p AS bigint) FOR UPDATE');
            $baja = $pdo->prepare('UPDATE planeacion SET es_vigente = FALSE WHERE planeacion_id = :id');
            $ins = $pdo->prepare("INSERT INTO planeacion
                    (asignacion_id, parcial_id, tipo_documento, planeacion_anterior_id, nombre_archivo,
                     archivo_original_uri, estado, resultado_revision, es_vigente, usuario_carga)
                VALUES (:a, :p, :t, :ant, :n, 'bd:planeacion_archivo', 'Cargada', 'Pendiente', TRUE, :u)
                RETURNING planeacion_id");
            $arch = $pdo->prepare('INSERT INTO planeacion_archivo (planeacion_id, tamano_bytes, contenido)
                SELECT :id, tamano_bytes, contenido FROM carga_pendiente WHERE lote_id = :l AND orden = :o');
            $hash = $pdo->prepare('INSERT INTO planeacion_archivo_hash (planeacion_id, sha256) VALUES (:id, :sha)');
            $parcial = $lote['parcial_id'];
            $reemplazos = 0;

            foreach ($items as $it) {
                $o = (int)$it['orden'];
                $aid = $elegidas[$o];
                $prev->bindValue('a', $aid, PDO::PARAM_INT);
                $prev->bindValue('t', $lote['tipo_documento']);
                $prev->bindValue('p', $parcial, $parcial === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
                $prev->execute();
                $anterior = $prev->fetchColumn();
                if ($anterior !== false) {
                    $baja->execute(['id' => $anterior]);
                    $reemplazos++;
                }
                $ins->bindValue('a', $aid, PDO::PARAM_INT);
                $ins->bindValue('p', $parcial, $parcial === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
                $ins->bindValue('t', $lote['tipo_documento']);
                $ins->bindValue('ant', $anterior === false ? null : (int)$anterior, $anterior === false ? PDO::PARAM_NULL : PDO::PARAM_INT);
                $ins->bindValue('n', $it['nombre_original']);
                $ins->bindValue('u', (int)$user['usuario_id'], PDO::PARAM_INT);
                $ins->execute();
                $pid = (int)$ins->fetchColumn();
                $arch->execute(['id' => $pid, 'l' => $loteId, 'o' => $o]);
                $hash->execute(['id' => $pid, 'sha' => $it['sha256']]);
                audit($pdo, (int)$user['usuario_id'], 'planeacion', $pid, 'CARGA', null, [
                    'asignacion_id' => $aid, 'tipo' => $lote['tipo_documento'], 'parcial_id' => $parcial,
                    'archivo' => $it['nombre_original'], 'sha256' => $it['sha256'], 'reemplaza' => $anterior === false ? null : (int)$anterior,
                ]);
            }
            $pdo->prepare('DELETE FROM carga_pendiente WHERE lote_id = :l')->execute(['l' => $loteId]);
            $pdo->commit();
            unset($_SESSION['carga_lote']);
            $_SESSION['carga_mensaje'] = 'Lote registrado correctamente: ' . count($items) . ' PDF del grupo ' . $grupo . ' (' . $parcialNombre . ').'
                . ($reemplazos ? ' ' . $reemplazos . ' documento(s) reemplazaron una versión anterior, que se conserva en el historial.' : '');
            header('Location: confirmar_carga_masiva.php');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Carga masiva (confirmación): ' . $e->getMessage());
            $errores[] = str_contains($e->getMessage(), 'uq_planeacion_archivo_hash_sha256')
                ? 'Se detectó un PDF duplicado ya registrado. Cancela el lote y revisa los archivos repetidos.'
                : 'No fue posible registrar el lote. Inténtalo de nuevo; si persiste, revisa el registro del servidor.';
        }
    }
}

render_header('Validar lote', $user);
?>
<main class="content wide">
  <a class="back" href="carga_masiva_planeaciones.php">← Volver a la carga</a>
  <section class="panel">
    <h2>Validación del lote</h2>
    <p class="muted">Ciclo/periodo: <strong><?= h($periodo['ciclo'] . ' — ' . $periodo['codigo'] . ' / ' . $periodo['nombre']) ?></strong> · Grupo: <strong><?= h($grupo) ?></strong> · Entrega: <strong><?= h($parcialNombre) ?></strong> · <?= count($items) ?> PDF</p>
    <?php foreach (($lote['avisos'] ?? []) as $a): ?><p class="notice"><?= h($a) ?></p><?php endforeach; ?>
    <?php foreach ($errores as $e): ?><p class="error"><?= h($e) ?></p><?php endforeach; ?>
    <form method="post">
      <?= csrf_field() ?>
      <div class="table-wrap"><table>
        <thead><tr><th>Archivo</th><th>Tamaño</th><th>Asignatura y docente (confirmar)</th></tr></thead>
        <tbody>
        <?php foreach ($items as $it): $o = (int)$it['orden']; ?>
          <tr>
            <td class="filename"><?= h($it['nombre_original']) ?>
              <?php if ($it['ruta_relativa'] && $it['ruta_relativa'] !== $it['nombre_original']): ?><br><span class="muted small"><?= h($it['ruta_relativa']) ?></span><?php endif; ?>
              <?php if ($it['duplicado_bd']): ?><br><span class="tag tag-warn">Ya registrado</span><?php endif; ?>
              <?php if ($it['duplicado_lote']): ?><br><span class="tag tag-warn">Repetido en este lote</span><?php endif; ?>
            </td>
            <td class="nowrap"><?= tamano_legible((int)$it['tamano_bytes']) ?></td>
            <td><select name="asignacion[<?= $o ?>]" required>
              <option value="">Selecciona asignación…</option>
              <?php foreach ($asignaciones as $aid => $a): ?>
                <option value="<?= $aid ?>" <?= ($elegidas[$o] ?? 0) === $aid ? 'selected' : '' ?>><?= h($a['asignatura'] . ' — ' . $a['docente']) ?><?= $a['ya_tiene'] ? ' (reemplaza la versión vigente)' : '' ?></option>
              <?php endforeach; ?>
            </select></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <p class="notice">Verifica la asignación de cada archivo. El sistema no adivina ni registra una materia sin tu confirmación. Si una asignatura ya tenía documento vigente para esta entrega, el nuevo lo reemplaza y el anterior queda en el historial.</p>
      <div class="actions">
        <button type="submit" name="accion" value="confirmar">Confirmar y registrar lote</button>
        <button type="submit" name="accion" value="cancelar" formnovalidate class="btn-secondary">Cancelar lote</button>
      </div>
    </form>
  </section>
</main>
<?php render_footer();
