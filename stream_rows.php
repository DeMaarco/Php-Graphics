<?php
// Endpoint SSE: transmite filas del CSV en tiempo real al frontend.
header('Content-Type: text/event-stream; charset=UTF-8');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');
header('Vary: Accept-Encoding');

@ini_set('max_execution_time', '0');
@set_time_limit(0);
@ignore_user_abort(true);

if (
    extension_loaded('zlib')
    && !headers_sent()
    && isset($_SERVER['HTTP_ACCEPT_ENCODING'])
    && strpos((string)$_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip') !== false
    && !in_array('ob_gzhandler', ob_list_handlers(), true)
) {
    ob_start('ob_gzhandler');
}

session_start();
require_once __DIR__ . DIRECTORY_SEPARATOR . 'csv_chunk.php';

// Solo GET para stream continuo con EventSource.
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo "event: error\n";
    echo 'data: ' . json_encode(['error' => 'Método no permitido.'], JSON_UNESCAPED_UNICODE) . "\n\n";
    flush();
    exit;
}

$uploadId = isset($_GET['upload_id']) ? (string)$_GET['upload_id'] : '';
$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 200;

if ($uploadId === '') {
    echo "event: error\n";
    echo 'data: ' . json_encode(['error' => 'Falta upload_id.'], JSON_UNESCAPED_UNICODE) . "\n\n";
    flush();
    exit;
}

if ($offset < 0) {
    $offset = 0;
}
if ($limit < 50) {
    $limit = 50;
}
if ($limit > 200000) {
    $limit = 200000;
}

$entry = $_SESSION['csv_uploads'][$uploadId] ?? null;
$storedPath = is_array($entry) ? ($entry['path'] ?? null) : $entry;
$chunkedUpload = is_array($entry) && isset($entry['upload_state']) && is_array($entry['upload_state']);
$completeFlagPath = is_string($storedPath) ? ($storedPath . '.complete') : '';

if (!is_string($storedPath) || !file_exists($storedPath)) {
    echo "event: error\n";
    echo 'data: ' . json_encode(['error' => 'Sesión de carga no encontrada o expirada.'], JSON_UNESCAPED_UNICODE) . "\n\n";
    flush();
    exit;
}

session_write_close();

// Se desactiva buffering para enviar eventos de forma inmediata.
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', '1');
}
@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', '0');
while (ob_get_level() > 0) {
    @ob_end_flush();
}

$hasMore = true;
$currentOffset = $offset;

try {
    // Bucle principal: lee chunks, emite evento rows y finaliza con event end.
    while ($hasMore) {
        if (connection_aborted()) {
            break;
        }

        $chunk = readCsvChunk($storedPath, $currentOffset, $limit);
        $rows = $chunk['rows'];
        $currentOffset = (int)$chunk['next_offset'];
        $hasMore = (bool)$chunk['has_more'];

        if (!$hasMore) {
            // En carga por chunks se espera bandera .complete para confirmar EOF real.
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

        if (!empty($rows)) {
            echo "event: rows\n";
            echo 'data: ' . json_encode([
                'rows' => $rows,
                'next_offset' => $currentOffset,
                'has_more' => $hasMore,
                'engine' => $chunk['engine'] ?? 'unknown'
            ], JSON_UNESCAPED_UNICODE) . "\n\n";
            flush();
        }

        if (!$hasMore) {
            echo "event: end\n";
            echo "data: {}\n\n";
            flush();
            break;
        }

        if (empty($rows)) {
            // Evita busy-loop cuando aún no llegan más bytes del archivo chunked.
            usleep(120000);
        }
    }

    // Limpieza final del archivo temporal y del estado de sesión.
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
    echo "event: error\n";
    echo 'data: ' . json_encode(['error' => 'Error en streaming: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE) . "\n\n";
    flush();
}
