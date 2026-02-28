<?php

declare(strict_types=1);

// Último error capturado al inicializar FFI para diagnóstico en endpoints.
$GLOBALS['CSV_READER_FFI_ERROR'] = null;

/* ── FFI helpers ─────────────────────────────────────────────────────────── */

/**
 * Loads the csv_reader shared library via PHP FFI (once per process).
 * Returns the FFI instance, or null if FFI is unavailable or the library
 * is not compiled yet.
 */
function getCSVReaderFFI(): ?FFI
{
    static $ffi   = null;
    static $tried = false;

    $GLOBALS['CSV_READER_FFI_ERROR'] = null;

    if ($tried) {
        return $ffi;
    }
    $tried = true;

    // La lectura CSV del proyecto depende de FFI + csv_reader.dll/.so.
    if (!extension_loaded('ffi')) {
        $GLOBALS['CSV_READER_FFI_ERROR'] = 'La extensión ffi no está cargada.';
        return null;
    }

    $lib = __DIR__ . DIRECTORY_SEPARATOR . (PHP_OS_FAMILY === 'Windows' ? 'csv_reader.dll' : 'csv_reader.so');
    if (!file_exists($lib)) {
        $GLOBALS['CSV_READER_FFI_ERROR'] = 'No se encontró la librería compartida: ' . $lib;
        return null;
    }

    try {
        $ffi = FFI::cdef(
            'char *csv_read_chunk(const char *path, long offset, long limit, int allow_partial_final_row);
             void  csv_free(char *ptr);',
            $lib
        );
    } catch (Throwable $e) {
        $GLOBALS['CSV_READER_FFI_ERROR'] = $e->getMessage();
        $ffi = null;
    }

    return $ffi;
}

function getCSVReaderFFILastError(): ?string
{
    $value = $GLOBALS['CSV_READER_FFI_ERROR'] ?? null;
    return is_string($value) ? $value : null;
}

/**
 * Read a chunk from a CSV file using the FFI-loaded C library.
 * Returns the same shape as readCsvChunk().
 */
function readCsvChunkFFI(FFI $ffi, string $filePath, int $offset, int $limit, bool $allowPartialFinalRow = true): array
{
    // Delega parseo CSV al motor C y transforma la salida JSON a array PHP.
    $json = readCsvChunkRawFFI($ffi, $filePath, $offset, $limit, $allowPartialFinalRow);

    $result = json_decode($json, true);
    if (!is_array($result)) {
        throw new RuntimeException('csv_read_chunk (FFI) devolvió JSON inválido.');
    }

    return [
        'headers'     => $result['headers']      ?? [],
        'rows'        => $result['rows']         ?? [],
        'next_offset' => (int)($result['next_offset'] ?? 0),
        'has_more'    => (bool)($result['has_more']   ?? false),
    ];
}

function readCsvChunkRawFFI(FFI $ffi, string $filePath, int $offset, int $limit, bool $allowPartialFinalRow = true): string
{
    $ptr = $ffi->csv_read_chunk($filePath, $offset, $limit, $allowPartialFinalRow ? 1 : 0);

    if (FFI::isNull($ptr)) {
        throw new RuntimeException('csv_read_chunk (FFI) devolvió NULL.');
    }

    $json = FFI::string($ptr);
    $ffi->csv_free($ptr);
    return $json;
}

function readCsvChunk(string $filePath, int $startOffset, int $limit, bool $allowPartialFinalRow = true): array
{
    // En modo actual solo se permite backend nativo (sin fallback PHP puro).
    if (substr($filePath, -4) !== '.csv') {
        throw new RuntimeException('FFI-only mode requiere archivos .csv.');
    }

    $ffi = getCSVReaderFFI();
    if ($ffi === null) {
        $detail = getCSVReaderFFILastError();
        if ($detail === null || $detail === '') {
            $detail = 'habilita extension=ffi y csv_reader.dll.';
        }
        throw new RuntimeException('FFI no disponible: ' . $detail);
    }

    $chunk = readCsvChunkFFI($ffi, $filePath, $startOffset, $limit, $allowPartialFinalRow);
    $chunk['engine'] = 'ffi';
    return $chunk;
}

function readCsvChunkRaw(string $filePath, int $startOffset, int $limit, bool $allowPartialFinalRow = true): string
{
    if (substr($filePath, -4) !== '.csv') {
        throw new RuntimeException('FFI-only mode requiere archivos .csv.');
    }

    $ffi = getCSVReaderFFI();
    if ($ffi === null) {
        $detail = getCSVReaderFFILastError();
        if ($detail === null || $detail === '') {
            $detail = 'habilita extension=ffi y csv_reader.dll.';
        }
        throw new RuntimeException('FFI no disponible: ' . $detail);
    }

    return readCsvChunkRawFFI($ffi, $filePath, $startOffset, $limit, $allowPartialFinalRow);
}
