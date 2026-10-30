<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/auth.php';

$user = require_login($pdo);

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    fatal_page(400, 'Solicitud inválida', 'El identificador del documento no es válido.');
}

$st = $pdo->prepare('SELECT pl.nombre_archivo, aa.docente_persona_id, a.tamano_bytes
    FROM planeacion pl
    JOIN asignacion_academica aa ON aa.asignacion_id = pl.asignacion_id
    LEFT JOIN planeacion_archivo a ON a.planeacion_id = pl.planeacion_id
    WHERE pl.planeacion_id = :id');
$st->execute(['id' => $id]);
$p = $st->fetch();
if (!$p) {
    fatal_page(404, 'Documento no encontrado', 'La planeación solicitada no existe.');
}

// Personal de Subdirección/Dirección ve todo; cada docente solo sus propias planeaciones.
$esPropia = (int)$p['docente_persona_id'] === (int)$user['persona_id'];
if (!$esPropia && !user_has_role($user, ROLES_CONSULTA)) {
    fatal_page(403, 'Acceso restringido', 'No tienes permiso para consultar este documento.');
}
if ($p['tamano_bytes'] === null) {
    fatal_page(404, 'Archivo no disponible', 'El registro existe pero el PDF no está almacenado.');
}

$st = $pdo->prepare('SELECT contenido FROM planeacion_archivo WHERE planeacion_id = :id');
$st->execute(['id' => $id]);
$st->bindColumn(1, $lob, PDO::PARAM_LOB);
$st->fetch(PDO::FETCH_BOUND);

$nombre = str_replace(['"', "\r", "\n", '\\', '/'], '', basename((string)$p['nombre_archivo']));
header('Content-Type: application/pdf');
header('Content-Length: ' . (int)$p['tamano_bytes']);
header('Content-Disposition: inline; filename="' . preg_replace('/[^\x20-\x7E]/', '_', $nombre) . '"; filename*=UTF-8\'\'' . rawurlencode($nombre));
header('Cache-Control: private, max-age=0, no-store');
// El visor de PDF del navegador no funciona con la CSP general de las páginas.
header_remove('Content-Security-Policy');

if (is_resource($lob)) {
    fpassthru($lob);
} else {
    echo $lob;
}
