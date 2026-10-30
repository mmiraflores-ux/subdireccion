<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/auth.php';

$user = require_login($pdo);
require_role($user, ROLES_GESTION);

$periodos = $pdo->query('SELECT pi.periodo_id, pi.codigo, pi.nombre, ce.nombre AS ciclo
    FROM periodo_institucional pi JOIN ciclo_escolar ce ON ce.ciclo_id = pi.ciclo_id
    ORDER BY ce.nombre DESC, pi.codigo')->fetchAll();
$grupos = $pdo->query("SELECT DISTINCT g.grupo_id, g.identificador, aa.periodo_id
    FROM asignacion_academica aa JOIN grupo g ON g.grupo_id = aa.grupo_id
    WHERE aa.estado = 'Vigente' ORDER BY g.identificador")->fetchAll();
$parciales = $pdo->query('SELECT parcial_id, periodo_id, numero, fecha_inicio, fecha_fin FROM parcial ORDER BY periodo_id, numero')->fetchAll();

function ini_bytes(string $v): int
{
    $v = trim($v);
    $n = (int)$v;
    return match (strtolower(substr($v, -1))) { 'g' => $n << 30, 'm' => $n << 20, 'k' => $n << 10, default => $n };
}

$errores = [];
$avisos = [];
$sel = ['periodo_id' => 0, 'grupo_id' => 0, 'tipo_documento' => 'SEMESTRAL', 'parcial_id' => 0];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postMax = ini_bytes((string)ini_get('post_max_size'));
    if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $postMax) {
        $errores[] = 'El lote completo supera el máximo permitido por el servidor (' . round($postMax / 1048576) . ' MB). Divide los archivos en lotes más pequeños.';
    } else {
        verify_csrf();
        $sel['periodo_id'] = $periodoId = (int)($_POST['periodo_id'] ?? 0);
        $sel['grupo_id'] = $grupoId = (int)($_POST['grupo_id'] ?? 0);
        $sel['tipo_documento'] = $tipo = (string)($_POST['tipo_documento'] ?? '');
        $parcialId = ($_POST['parcial_id'] ?? '') !== '' ? (int)$_POST['parcial_id'] : null;
        $sel['parcial_id'] = (int)$parcialId;

        if (!in_array($tipo, ['SEMESTRAL', 'PARCIAL'], true)) $errores[] = 'Selecciona un tipo de documento válido.';
        if ($periodoId < 1 || $grupoId < 1) $errores[] = 'Selecciona el periodo y el grupo.';
        if ($tipo === 'SEMESTRAL') $parcialId = null;
        if ($tipo === 'PARCIAL') {
            if (!$parcialId) {
                $errores[] = 'Selecciona el parcial para la entrega parcial.';
            } else {
                $st = $pdo->prepare('SELECT 1 FROM parcial WHERE parcial_id = :p AND periodo_id = :per');
                $st->execute(['p' => $parcialId, 'per' => $periodoId]);
                if (!$st->fetchColumn()) $errores[] = 'El parcial seleccionado no corresponde al periodo.';
            }
        }
        if ($periodoId > 0 && $grupoId > 0) {
            $st = $pdo->prepare("SELECT COUNT(*) FROM asignacion_academica WHERE grupo_id = :g AND periodo_id = :p AND estado = 'Vigente'");
            $st->execute(['g' => $grupoId, 'p' => $periodoId]);
            if ((int)$st->fetchColumn() === 0) $errores[] = 'El grupo no tiene asignaciones vigentes en ese periodo.';
        }

        // Reunir archivos (de la carpeta o de la selección manual)
        $validos = [];
        $ignorados = 0;
        $files = $_FILES['pdfs'] ?? null;
        if (!$files || !is_array($files['name'] ?? null)) {
            $errores[] = 'Selecciona una carpeta o varios archivos PDF.';
        } else {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            foreach ($files['name'] as $i => $name) {
                $err = (int)($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);
                if ($err === UPLOAD_ERR_NO_FILE) continue;
                $name = (string)$name;
                if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'pdf') {
                    $ignorados++; // desktop.ini, Thumbs.db, imágenes, etc. dentro de la carpeta
                    continue;
                }
                if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE || (int)$files['size'][$i] > PLANEACIONES_MAX_BYTES) {
                    $errores[] = '«' . $name . '» supera el máximo de ' . (PLANEACIONES_MAX_BYTES >> 20) . ' MB por archivo.';
                    continue;
                }
                if ($err !== UPLOAD_ERR_OK) {
                    $errores[] = '«' . $name . '» no se recibió correctamente (código ' . $err . ').';
                    continue;
                }
                $tmp = (string)$files['tmp_name'][$i];
                $fh = fopen($tmp, 'rb');
                $magic = $fh ? fread($fh, 5) : '';
                if ($fh) fclose($fh);
                if ($finfo->file($tmp) !== 'application/pdf' || $magic !== '%PDF-') {
                    $errores[] = '«' . $name . '» no es un PDF válido.';
                    continue;
                }
                $validos[] = [
                    'tmp' => $tmp,
                    'original' => mb_substr(basename(str_replace('\\', '/', $name)), 0, 255),
                    'relative' => (string)($files['full_path'][$i] ?? $name),
                    'size' => (int)$files['size'][$i],
                ];
            }
            $total = count($files['name']);
            if ($total >= (int)ini_get('max_file_uploads')) {
                $avisos[] = 'El servidor recibe como máximo ' . ini_get('max_file_uploads') . ' archivos por envío; si la carpeta tenía más, divídela en varios lotes.';
            }
            if (!$validos && !$errores) $errores[] = 'La selección no contiene archivos PDF.';
            if (count($validos) > PLANEACIONES_MAX_FILES) $errores[] = 'El lote debe contener como máximo ' . PLANEACIONES_MAX_FILES . ' archivos PDF.';
            if ($ignorados) $avisos[] = $ignorados . ' archivo(s) que no eran PDF se omitieron.';
        }

        if (!$errores) {
            try {
                // Limpieza: lotes abandonados de más de 24 h y el lote anterior de este usuario.
                $pdo->exec("DELETE FROM carga_pendiente WHERE creado_en < now() - interval '24 hours'");
                $pdo->prepare('DELETE FROM carga_pendiente WHERE usuario_id = :u')->execute(['u' => $user['usuario_id']]);

                $loteId = bin2hex(random_bytes(16));
                $pdo->beginTransaction();
                $ins = $pdo->prepare('INSERT INTO carga_pendiente
                    (lote_id, usuario_id, orden, nombre_original, ruta_relativa, sha256, tamano_bytes, contenido)
                    VALUES (:lote, :u, :o, :n, :r, :sha, :t, :c)');
                foreach ($validos as $o => $f) {
                    $contenido = (string)file_get_contents($f['tmp']);
                    $ins->bindValue('lote', $loteId);
                    $ins->bindValue('u', (int)$user['usuario_id'], PDO::PARAM_INT);
                    $ins->bindValue('o', $o, PDO::PARAM_INT);
                    $ins->bindValue('n', $f['original']);
                    $ins->bindValue('r', $f['relative']);
                    $ins->bindValue('sha', hash_file('sha256', $f['tmp']));
                    $ins->bindValue('t', $f['size'], PDO::PARAM_INT);
                    $ins->bindValue('c', $contenido, PDO::PARAM_LOB);
                    $ins->execute();
                    unset($contenido);
                }
                $pdo->commit();
                $_SESSION['carga_lote'] = [
                    'id' => $loteId,
                    'periodo_id' => $periodoId,
                    'grupo_id' => $grupoId,
                    'tipo_documento' => $tipo,
                    'parcial_id' => $parcialId,
                    'usuario_id' => (int)$user['usuario_id'],
                    'avisos' => $avisos,
                ];
                header('Location: confirmar_carga_masiva.php');
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Carga masiva (validación): ' . $e->getMessage());
                $errores[] = 'No fue posible guardar temporalmente los archivos. Inténtalo de nuevo.';
            }
        }
    }
}

render_header('Carga masiva', $user);
?>
<main class="content">
  <a class="back" href="planeaciones.php">← Volver a planeaciones</a>
  <section class="panel">
    <h2>Carga masiva de planeaciones PDF</h2>
    <p class="muted">Selecciona el tipo de entrega y la carpeta del grupo. Los archivos se validan y después confirmas a qué asignatura corresponde cada uno.</p>
    <?php foreach ($errores as $e): ?><p class="error"><?= h($e) ?></p><?php endforeach; ?>
    <?php foreach ($avisos as $a): ?><p class="notice"><?= h($a) ?></p><?php endforeach; ?>
    <form method="post" enctype="multipart/form-data" id="formCarga">
      <?= csrf_field() ?>
      <div class="grid">
        <div><label for="periodo_id">Ciclo / periodo institucional</label>
          <select id="periodo_id" name="periodo_id" required><option value="">Selecciona…</option>
          <?php foreach ($periodos as $p): ?><option value="<?= (int)$p['periodo_id'] ?>" <?= $sel['periodo_id'] === (int)$p['periodo_id'] ? 'selected' : '' ?>><?= h($p['ciclo'] . ' — ' . $p['codigo'] . ' / ' . $p['nombre']) ?></option><?php endforeach; ?>
          </select></div>
        <div><label for="grupo_id">Grupo</label>
          <select id="grupo_id" name="grupo_id" required><option value="">Selecciona…</option>
          <?php foreach ($grupos as $g): ?><option value="<?= (int)$g['grupo_id'] ?>" data-periodo="<?= (int)$g['periodo_id'] ?>" <?= $sel['grupo_id'] === (int)$g['grupo_id'] ? 'selected' : '' ?>><?= h($g['identificador']) ?></option><?php endforeach; ?>
          </select></div>
        <div><label for="tipo_documento">Tipo de entrega</label>
          <select id="tipo_documento" name="tipo_documento" required>
            <option value="SEMESTRAL" <?= $sel['tipo_documento'] === 'SEMESTRAL' ? 'selected' : '' ?>>Planeación semestral</option>
            <option value="PARCIAL" <?= $sel['tipo_documento'] === 'PARCIAL' ? 'selected' : '' ?>>Planeación específica de un parcial</option>
          </select></div>
        <div id="parcialWrap"><label for="parcial_id">Parcial</label>
          <select id="parcial_id" name="parcial_id"><option value="">Selecciona…</option>
          <?php foreach ($parciales as $pa): ?><option value="<?= (int)$pa['parcial_id'] ?>" data-periodo="<?= (int)$pa['periodo_id'] ?>" <?= $sel['parcial_id'] === (int)$pa['parcial_id'] ? 'selected' : '' ?>>Parcial <?= (int)$pa['numero'] ?><?= $pa['fecha_inicio'] ? ' (' . h(date('d/m', strtotime($pa['fecha_inicio']))) . ' a ' . h(date('d/m', strtotime((string)$pa['fecha_fin']))) . ')' : '' ?></option><?php endforeach; ?>
          </select></div>
      </div>
      <fieldset class="modo-archivos">
        <legend>¿Cómo quieres seleccionar los archivos?</legend>
        <label class="check"><input type="radio" name="modo_sel" value="carpeta" checked> Una carpeta completa</label>
        <label class="check"><input type="radio" name="modo_sel" value="archivos"> Varios archivos PDF</label>
      </fieldset>
      <div><label for="pdfs" id="pdfsLabel">Carpeta del grupo</label>
        <input id="pdfs" type="file" name="pdfs[]" accept="application/pdf,.pdf" multiple webkitdirectory required>
        <p class="muted small" id="pdfsInfo">Máximo <?= PLANEACIONES_MAX_FILES ?> PDF de <?= PLANEACIONES_MAX_BYTES >> 20 ?> MB cada uno. Los archivos que no sean PDF se omiten.</p>
      </div>
      <div class="actions"><button type="submit" id="btnEnviar">Validar archivos del lote</button><a class="btn btn-secondary" href="planeaciones.php">Cancelar</a></div>
    </form>
  </section>
</main>
<script>
(function () {
  var tipo = document.getElementById('tipo_documento'), wrap = document.getElementById('parcialWrap'),
      parcial = document.getElementById('parcial_id'), periodo = document.getElementById('periodo_id'),
      grupo = document.getElementById('grupo_id'), input = document.getElementById('pdfs'),
      label = document.getElementById('pdfsLabel'), info = document.getElementById('pdfsInfo');
  function syncTipo() { var sem = tipo.value === 'SEMESTRAL'; wrap.style.display = sem ? 'none' : ''; parcial.required = !sem; if (sem) parcial.value = ''; }
  function filtra(sel, val) { for (var i = 0; i < sel.options.length; i++) { var o = sel.options[i]; if (o.value) { o.hidden = !!val && o.dataset.periodo !== val; if (o.hidden && o.selected) sel.value = ''; } } }
  function syncPeriodo() { filtra(parcial, periodo.value); filtra(grupo, periodo.value); }
  tipo.addEventListener('change', syncTipo); periodo.addEventListener('change', syncPeriodo); syncTipo(); syncPeriodo();
  document.querySelectorAll('input[name=modo_sel]').forEach(function (r) {
    r.addEventListener('change', function () {
      var carpeta = this.value === 'carpeta';
      input.value = '';
      if (carpeta) input.setAttribute('webkitdirectory', ''); else input.removeAttribute('webkitdirectory');
      label.textContent = carpeta ? 'Carpeta del grupo' : 'Archivos PDF (puedes seleccionar varios)';
    });
  });
  input.addEventListener('change', function () {
    var pdf = 0; for (var i = 0; i < input.files.length; i++) if (/\.pdf$/i.test(input.files[i].name)) pdf++;
    info.textContent = pdf + ' PDF seleccionados' + (input.files.length > pdf ? ' (' + (input.files.length - pdf) + ' archivos que no son PDF se omitirán)' : '') + '.';
  });
  document.getElementById('formCarga').addEventListener('submit', function () {
    var b = document.getElementById('btnEnviar'); b.disabled = true; b.textContent = 'Enviando archivos…';
  });
})();
</script>
<?php render_footer();
