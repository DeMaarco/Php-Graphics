<?php
// Endpoint JSON: devuelve un único lote de filas para polling incremental.
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-cache');

session_start();
require_once __DIR__ . DIRECTORY_SEPARATOR . 'csv_chunk.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Método no permitido.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$uploadId = isset($_GET['upload_id']) ? (string)$_GET['upload_id'] : '';
$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 200;

if ($uploadId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Falta upload_id.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($offset < 0) {
    $offset = 0;
}
if ($limit < 50) {
    $limit = 50;
}
if ($limit > 50000) {
    $limit = 50000;
}

$entry = $_SESSION['csv_uploads'][$uploadId] ?? null;
$storedPath = is_array($entry) ? ($entry['path'] ?? null) : $entry;
$chunkedUpload = is_array($entry) && isset($entry['upload_state']) && is_array($entry['upload_state']);
$completeFlagPath = is_string($storedPath) ? ($storedPath . '.complete') : '';

if (!is_string($storedPath) || !file_exists($storedPath)) {
    http_response_code(404);
    echo json_encode(['error' => 'Sesión de carga no encontrada o expirada.'], JSON_UNESCAPED_UNICODE);
    exit;
}

session_write_close();

try {
    $chunk = readCsvChunk($storedPath, $offset, $limit);
    $rows = $chunk['rows'];
    $nextOffset = (int)$chunk['next_offset'];
    $hasMore = (bool)$chunk['has_more'];

    if (!$hasMore) {
        clearstatcache(true, $storedPath);
        if ($chunkedUpload && $completeFlagPath !== '') {
            clearstatcache(true, $completeFlagPath);
        }

        $uploadComplete = $chunkedUpload
            ? ($completeFlagPath !== '' && file_exists($completeFlagPath))
            : true;

        if (!$uploadComplete) {
            $hasMore = true;
        }
    }

    echo json_encode([
        'rows' => $rows,
        'next_offset' => $nextOffset,
        'has_more' => $hasMore,
        'engine' => $chunk['engine'] ?? 'unknown',
    ], JSON_UNESCAPED_UNICODE);

    if (!$hasMore && is_string($storedPath) && file_exists($storedPath)) {
        @unlink($storedPath);
        if ($chunkedUpload && $completeFlagPath !== '' && file_exists($completeFlagPath)) {
            @unlink($completeFlagPath);
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        unset($_SESSION['csv_uploads'][$uploadId]);
        session_write_close();
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Error leyendo filas: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
