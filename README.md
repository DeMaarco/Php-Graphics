# ETL PHP + C (FFI) para CSV grandes

Visor de CSV grande en tiempo real con backend PHP + parser nativo en C (FFI).

## Estado actual

- Carga de archivos por chunks (subida incremental).
- Parseo CSV en C (`csv_reader.dll`) con API FFI.
- Lectura incremental por SSE (`stream_rows.php`) y fallback por polling (`poll_rows.php`).
- Render virtualizado en frontend para tablas con millones de filas.
- Métricas en UI (`up`, `io`, `ui`) y barra de progreso.

## Estructura

- `index.php`: interfaz, subida chunked, preview local, virtual scroll, SSE/polling.
- `upload_chunk.php`: recibe chunks en orden y mantiene estado de subida en sesión.
- `upload_complete.php`: cierra la subida y crea la marca `.complete`.
- `stream_rows.php`: stream SSE con lectura incremental desde C.
- `poll_rows.php`: endpoint JSON de lote único (fallback/sin SSE largo).
- `csv_chunk.php`: puente PHP ↔ FFI (lectura estructurada y raw JSON).
- `csv_reader.c`: parser CSV nativo exportado para FFI.
- `csv_reader.dll`: binario compilado para Windows.
- `build.ps1`: compilación de la DLL y validación de arquitectura.

## Requisitos

- Windows + PowerShell.
- PHP con FFI habilitado:
  - `extension=ffi`
  - `ffi.enable=true`
- GCC (MinGW) en `PATH` para compilar `csv_reader.dll`.

## Compilar la DLL

```powershell
./build.ps1
```

## Ejecutar en local

### Opción rápida (PHP embebido)

```powershell
php -d upload_max_filesize=2048M -d post_max_size=2048M -d memory_limit=4096M -d max_execution_time=0 -S 127.0.0.1:8080
```

Abrir `http://127.0.0.1:8080`.

### Opción recomendada (Apache / XAMPP)

Para mejor comportamiento de SSE y concurrencia de requests, usar Apache (por ejemplo XAMPP) apuntando el DocumentRoot a esta carpeta.

## Flujo técnico

1. `index.php` divide el archivo en chunks y los envía a `upload_chunk.php`.
2. Backend concatena bytes en un temporal `.csv` por `upload_id`.
3. `upload_complete.php` valida completitud y escribe la marca `.complete`.
4. Frontend arranca lectura incremental:
   - SSE en `stream_rows.php` (preferido), o
   - polling corto en `poll_rows.php` (fallback).
5. `csv_chunk.php` llama a `csv_reader.dll` (`csv_read_chunk`) y devuelve filas.
6. Frontend encola lotes y renderiza solo filas visibles (virtualización).

## Notas de rendimiento

- Ajustes principales en `index.php`:
  - `CHUNK_SIZE`
  - `UPLOAD_CHUNK_BYTES`
  - `MAX_APPEND_PER_FRAME`
- `stream_rows.php` usa passthrough de JSON crudo del parser C para reducir overhead en PHP.
- El parser soporta lectura segura durante subida activa (sin perder última fila parcial).

## Solución de problemas

- **Error FFI no disponible**:
  - Confirmar `extension=ffi` y `ffi.enable=true` en `php.ini`.
  - Verificar que `csv_reader.dll` exista en la raíz del proyecto.
- **No carga la DLL**:
  - Revisar arquitectura: PHP y DLL deben ser ambos x64 o ambos x86.
  - Recompilar con `./build.ps1`.
- **SSE inestable en `php -S`**:
  - Probar Apache/XAMPP o usar fallback polling.
- **Sesión expirada/no encontrada**:
  - Reintentar la carga; los temporales tienen TTL y se limpian automáticamente.
