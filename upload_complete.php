<?php
// Endpoint que cierra una subida por chunks y la marca como lista para lectura.
header('Content-Type: application/json; charset=UTF-8');
session_start();
require_once __DIR__ . DIRECTORY_SEPARATOR . 'csv_chunk.php';

// Solo POST para mantener semántica de mutación del estado de subida.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método no permitido.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw ?: '{}', true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['error' => 'Payload inválido.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$uploadId = isset($payload['upload_id']) ? (string)$payload['upload_id'] : '';
if ($uploadId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Falta upload_id.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$entry = readUploadMeta($uploadId);
if (!is_array($entry)) {
    $entry = $_SESSION['csv_uploads'][$uploadId] ?? null;
}

if (!is_array($entry)) {
    http_response_code(404);
    echo json_encode(['error' => 'Carga no encontrada o expirada.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$path = $entry['path'] ?? null;
$state = isset($entry['upload_state']) && is_array($entry['upload_state']) ? $entry['upload_state'] : [];

if (!is_string($path) || !file_exists($path)) {
    http_response_code(404);
    echo json_encode(['error' => 'Archivo temporal no encontrado.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$expected = (int)($state['expected_index'] ?? 0);
$total = (int)($state['total_chunks'] ?? 0);
// Si aún faltan chunks, no se puede finalizar.
if ($total <= 0 || $expected < $total) {
    http_response_code(409);
    echo json_encode([
        'error' => 'Faltan chunks por subir antes de finalizar.',
        'received_chunks' => $expected,
        'total_chunks' => $total,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$ffi = getCSVReaderFFI();
// Se valida FFI en este punto para fallar temprano antes de marcar como completo.
if ($ffi === null) {
    http_response_code(503);
    echo json_encode([
        'error' => 'FFI no disponible. Habilita extension=ffi, ffi.enable=true y csv_reader.dll.',
        'ffi_error' => getCSVReaderFFILastError(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$_SESSION['csv_uploads'][$uploadId]['upload_state'] = [
    'expected_index' => $expected,
    'total_chunks' => $total,
    'complete' => true,
];
$_SESSION['csv_uploads'][$uploadId]['created_at'] = time();

$createdAt = time();
writeUploadMeta($uploadId, [
    'path' => $path,
    'created_at' => $createdAt,
    'upload_state' => [
        'expected_index' => $expected,
        'total_chunks' => $total,
        'complete' => true,
    ],
]);

$flagBytes = @file_put_contents($path . '.complete', '1', LOCK_EX);
if ($flagBytes === false) {
    http_response_code(500);
    echo json_encode(['error' => 'No se pudo marcar la carga como completa.'], JSON_UNESCAPED_UNICODE);
    exit;
}

session_write_close();

echo json_encode(['upload_id' => $uploadId], JSON_UNESCAPED_UNICODE);
