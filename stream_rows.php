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

function emitSseEvent(string $event, string $data): void
{
    echo "event: {$event}\n";
    $lines = preg_split('/\r\n|\n|\r/', $data);
    if (!is_array($lines) || $lines === []) {
        echo "data: \n\n";
        flush();
        return;
    }

    foreach ($lines as $line) {
        echo 'data: ' . $line . "\n";
    }
    echo "\n";
    flush();
}

function parseNdjsonChunkPayload(string $payload): array
{
    $metaMarker = "\n__META__ ";
    $metaPos = strrpos($payload, $metaMarker);

    if ($metaPos === false) {
        if (strpos($payload, '__META__ ') === 0) {
            $metaPos = 0;
        } else {
            throw new RuntimeException('Payload NDJSON sin metadatos.');
        }
    }

    $rowsPart = $metaPos > 0 ? substr($payload, 0, $metaPos) : '';
    $metaRaw = trim(substr($payload, $metaPos + ($metaPos > 0 ? strlen($metaMarker) : strlen('__META__ '))));
    $meta = json_decode($metaRaw, true);

    if (!is_array($meta)) {
        throw new RuntimeException('Metadatos NDJSON inválidos.');
    }

    $nextOffset = (int)($meta['next_offset'] ?? 0);
    $hasMore = (bool)($meta['has_more'] ?? false);
    $trimmedRows = rtrim($rowsPart, "\r\n");
    $rowCount = $trimmedRows === '' ? 0 : (substr_count($trimmedRows, "\n") + 1);

    return [$rowsPart, $nextOffset, $hasMore, $rowCount];
}

if ($uploadId === '') {
    emitSseEvent('error', json_encode(['error' => 'Falta upload_id.'], JSON_UNESCAPED_UNICODE));
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

$entry = readUploadMeta($uploadId);
$storedPath = is_array($entry) ? ($entry['path'] ?? null) : null;
$chunkedUpload = is_array($entry) && isset($entry['upload_state']) && is_array($entry['upload_state']);
$completeFlagPath = is_string($storedPath) ? ($storedPath . '.complete') : '';

if (!is_string($storedPath) || !file_exists($storedPath)) {
    emitSseEvent('error', json_encode(['error' => 'Carga no encontrada o expirada.'], JSON_UNESCAPED_UNICODE));
    exit;
}

$ffi = getCSVReaderFFI();
if ($ffi === null) {
    emitSseEvent('error', json_encode([
        'error' => 'FFI no disponible para streaming.',
        'ffi_error' => getCSVReaderFFILastError(),
    ], JSON_UNESCAPED_UNICODE));
    exit;
}

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
$idleWaitMicros = 30000;
$ctx = null;
$currentLimit = $limit;

$adaptiveMinLimit = 20000;
$adaptiveMaxLimit = 140000;
$adaptiveTargetMs = 115.0;

try {
    $ctx = openCsvStreamFFI($ffi, $storedPath, $offset);

    // Bucle principal: lee chunks NDJSON por contexto stateful.
    while ($hasMore) {
        if (connection_aborted()) {
            break;
        }

        $uploadComplete = true;
        if ($chunkedUpload) {
            clearstatcache(true, $storedPath);
            if ($completeFlagPath !== '') {
                clearstatcache(true, $completeFlagPath);
            }
            $uploadComplete = ($completeFlagPath !== '' && file_exists($completeFlagPath));
        }

        $readStartedAt = microtime(true);
        $payload = readCsvStreamNextNdjsonFFI($ffi, $ctx, $currentLimit, $uploadComplete);
        $readElapsedMs = (microtime(true) - $readStartedAt) * 1000;
        [$rowsNdjson, $nextOffset, $hasMore, $rowCount] = parseNdjsonChunkPayload($payload);

        if ($rowCount > 0) {
            if ($readElapsedMs > ($adaptiveTargetMs * 1.55) && $currentLimit > $adaptiveMinLimit) {
                $currentLimit = max($adaptiveMinLimit, (int)floor($currentLimit * 0.86));
            } elseif (
                $readElapsedMs < ($adaptiveTargetMs * 0.72)
                && $rowCount >= (int)floor($currentLimit * 0.9)
                && $currentLimit < $adaptiveMaxLimit
            ) {
                $currentLimit = min($adaptiveMaxLimit, (int)floor($currentLimit * 1.12));
            }
        }

        if (!$hasMore) {
            // En carga por chunks se espera bandera .complete para confirmar EOF real.
            clearstatcache(true, $storedPath);
            if ($chunkedUpload && $completeFlagPath !== '') {
                clearstatcache(true, $completeFlagPath);
            }
            if (!$uploadComplete) {
                $hasMore = true;
            }
        }

        if ($rowCount > 0) {
            emitSseEvent('rows_ndjson', $rowsNdjson);
            emitSseEvent('meta', json_encode([
                'next_offset' => $nextOffset,
                'has_more' => $hasMore,
            ], JSON_UNESCAPED_UNICODE));
        }

        if (!$hasMore) {
            emitSseEvent('end', '{}');
            break;
        }

        if ($rowCount === 0) {
            // Evita busy-loop cuando aún no llegan más bytes del archivo chunked.
            usleep($idleWaitMicros);
            if ($idleWaitMicros < 80000) {
                $idleWaitMicros += 5000;
            }
        } else {
            $idleWaitMicros = 20000;
        }
    }

    // Limpieza final del archivo temporal y del metadata.
    if (!$hasMore && is_string($storedPath) && file_exists($storedPath)) {
        @unlink($storedPath);
        if ($chunkedUpload && $completeFlagPath !== '' && file_exists($completeFlagPath)) {
            @unlink($completeFlagPath);
        }
        deleteUploadMeta($uploadId);
    }
} catch (Throwable $e) {
    emitSseEvent('error', json_encode(['error' => 'Error en streaming: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE));
} finally {
    if ($ctx !== null) {
        closeCsvStreamFFI($ffi, $ctx);
    }
}
