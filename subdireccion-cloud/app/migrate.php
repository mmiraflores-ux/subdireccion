<?php
declare(strict_types=1);

/*
 * Aplica automáticamente los archivos database/NNN_*.sql que aún no se hayan
 * ejecutado. Se llama al arrancar el contenedor, así que una base de datos
 * vacía (por ejemplo, recién creada en Neon) queda lista sin pasos manuales.
 *
 * Uso manual:  php app/migrate.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'America/Mexico_City');
require_once __DIR__ . '/db.php';

// La base gratuita puede estar "dormida"; se reintenta mientras despierta.
$intentos = (int)(getenv('MIGRATE_RETRIES') ?: 10);
for ($i = 1; ; $i++) {
    try {
        $pdo = db_connect();
        break;
    } catch (Throwable $e) {
        if ($i >= $intentos) {
            fwrite(STDERR, "[migrate] No hay conexión con la base de datos: {$e->getMessage()}\n");
            exit(1);
        }
        fwrite(STDERR, "[migrate] Esperando la base de datos (intento {$i})…\n");
        sleep(3);
    }
}

$pdo->exec('CREATE TABLE IF NOT EXISTS schema_migracion (
    archivo VARCHAR(200) PRIMARY KEY,
    aplicado_en TIMESTAMPTZ NOT NULL DEFAULT now()
)');
// Evita que dos instancias migren al mismo tiempo.
$pdo->exec('SELECT pg_advisory_lock(727274)');

$aplicados = $pdo->query('SELECT archivo FROM schema_migracion')->fetchAll(PDO::FETCH_COLUMN);
$archivos = glob(dirname(__DIR__) . '/database/[0-9][0-9][0-9]_*.sql') ?: [];
sort($archivos, SORT_STRING);

$n = 0;
foreach ($archivos as $ruta) {
    $nombre = basename($ruta);
    if (in_array($nombre, $aplicados, true)) {
        continue;
    }
    echo "[migrate] Aplicando {$nombre}…\n";
    $pdo->beginTransaction();
    try {
        $pdo->exec((string)file_get_contents($ruta));
        $pdo->prepare('INSERT INTO schema_migracion (archivo) VALUES (:a)')->execute(['a' => $nombre]);
        $pdo->commit();
        $n++;
    } catch (Throwable $e) {
        $pdo->rollBack();
        fwrite(STDERR, "[migrate] Error en {$nombre}: {$e->getMessage()}\n");
        $pdo->exec('SELECT pg_advisory_unlock(727274)');
        exit(1);
    }
}
$pdo->exec('SELECT pg_advisory_unlock(727274)');
echo $n ? "[migrate] {$n} archivo(s) aplicados.\n" : "[migrate] La base de datos está al día.\n";
