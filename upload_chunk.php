<?php
// Endpoint de carga por partes (chunked upload) para CSV grandes.
// Mantiene estado en sesión para validar orden y completitud de chunks.
header('Content-Type: application/json; charset=UTF-8');
session_start();

// Solo se admite POST porque el cuerpo trae bytes del chunk actual.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método no permitido.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$uploadId = isset($_GET['upload_id']) ? (string)$_GET['upload_id'] : '';
$fileName = isset($_GET['name']) ? (string)$_GET['name'] : '';
$index = isset($_GET['index']) ? (int)$_GET['index'] : -1;
$total = isset($_GET['total']) ? (int)$_GET['total'] : 0;

if ($fileName === '' || strtolower(pathinfo($fileName, PATHINFO_EXTENSION)) !== 'csv') {
    http_response_code(400);
    echo json_encode(['error' => 'El archivo debe tener extensión .csv'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($index < 0 || $total <= 0 || $index >= $total) {
    http_response_code(400);
    echo json_encode(['error' => 'Parámetros de chunk inválidos.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['csv_uploads']) || !is_array($_SESSION['csv_uploads'])) {
    $_SESSION['csv_uploads'] = [];
}

// Limpieza de sesiones expiradas para evitar residuos en disco y memoria.
$now = time();
$ttlSeconds = 3600;
foreach ($_SESSION['csv_uploads'] as $id => $entry) {
    if (!is_array($entry)) {
        unset($_SESSION['csv_uploads'][$id]);
        continue;
    }

    $path = $entry['path'] ?? null;
    $created = (int)($entry['created_at'] ?? 0);

    if (!is_string($path) || !file_exists($path)) {
        unset($_SESSION['csv_uploads'][$id]);
        continue;
    }

    if ($created > 0 && ($now - $created) > $ttlSeconds) {
        @unlink($path);
        @unlink($path . '.complete');
        unset($_SESSION['csv_uploads'][$id]);
    }
}

if ($uploadId === '') {
    // El primer chunk crea una nueva sesión temporal de subida.
    if ($index !== 0) {
        http_response_code(400);
        echo json_encode(['error' => 'upload_id faltante para chunk > 0.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $tmp = tempnam(sys_get_temp_dir(), 'csv_');
    if ($tmp === false) {
        http_response_code(500);
        echo json_encode(['error' => 'No se pudo crear archivo temporal.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $path = $tmp . '.csv';
    rename($tmp, $path);

    $uploadId = bin2hex(random_bytes(16));
    $_SESSION['csv_uploads'][$uploadId] = [
        'path' => $path,
        'created_at' => $now,
        'upload_state' => [
            'expected_index' => 0,
            'total_chunks' => $total,
            'complete' => false,
        ],
    ];
}

$entry = $_SESSION['csv_uploads'][$uploadId] ?? null;
if (!is_array($entry)) {
    http_response_code(404);
    echo json_encode(['error' => 'Sesión de carga no encontrada.'], JSON_UNESCAPED_UNICODE);
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
$storedTotal = (int)($state['total_chunks'] ?? $total);
$complete = (bool)($state['complete'] ?? false);

if ($complete) {
    http_response_code(409);
    echo json_encode(['error' => 'La carga ya fue finalizada.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($storedTotal !== $total) {
    http_response_code(400);
    echo json_encode(['error' => 'El total de chunks no coincide con la sesión.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($index !== $expected) {
    http_response_code(409);
    echo json_encode([
        'error' => 'Orden de chunks inválido.',
        'expected_index' => $expected,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
if ($raw === false) {
    http_response_code(400);
    echo json_encode(['error' => 'No se pudo leer el cuerpo del chunk.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$bytes = file_put_contents($path, $raw, FILE_APPEND | LOCK_EX);
if ($bytes === false) {
    http_response_code(500);
    echo json_encode(['error' => 'No se pudo escribir el chunk en disco.'], JSON_UNESCAPED_UNICODE);
    exit;
}

@unlink($path . '.complete');

// Se avanza el índice esperado para forzar orden estricto de chunks.
$_SESSION['csv_uploads'][$uploadId]['upload_state'] = [
    'expected_index' => $expected + 1,
    'total_chunks' => $storedTotal,
    'complete' => false,
];
$_SESSION['csv_uploads'][$uploadId]['created_at'] = $now;
session_write_close();

echo json_encode([
    'upload_id' => $uploadId,
    'received_index' => $index,
    'next_index' => $index + 1,
    'total_chunks' => $storedTotal,
], JSON_UNESCAPED_UNICODE);
