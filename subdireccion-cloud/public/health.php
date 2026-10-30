<?php
declare(strict_types=1);
// Verificación de salud para la plataforma de hospedaje (no requiere sesión).
require_once __DIR__ . '/../app/bootstrap.php';
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
try {
    $pdo->query('SELECT 1')->fetchColumn();
    echo 'ok';
} catch (Throwable $e) {
    http_response_code(503);
    echo 'db-error';
}
