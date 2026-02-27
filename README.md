# ETL PHP + C (FFI) para CSV grandes

Proyecto mínimo para cargar un CSV grande desde navegador, parsearlo con una DLL en C vía FFI y visualizarlo en streaming.

## Qué hace

- Sube CSV por chunks (`upload_chunk.php`) para evitar límites de tamaño típicos.
- Finaliza la carga (`upload_complete.php`) cuando todos los chunks llegaron.
- Lee el CSV desde C (`csv_reader.dll`) por bloques y lo envía por SSE (`stream_rows.php`).
- Renderiza la tabla en frontend con virtualización (`index.php`) para mantener rendimiento.

## Estructura actual (limpia)

- `index.php`: UI + lógica de carga chunked + render virtualizado + consumo SSE.
- `upload_chunk.php`: recibe y valida chunks en orden.
- `upload_complete.php`: marca una subida como completa.
- `stream_rows.php`: transmite filas por `EventSource`.
- `csv_chunk.php`: puente PHP ↔ FFI (`csv_reader.dll`).
- `csv_reader.c`: parser CSV nativo exportado para FFI.
- `csv_reader.dll`: binario compilado usado por PHP.
- `build.ps1`: script para recompilar la DLL en Windows.

## Requisitos

- Windows + PowerShell.
- PHP con:
  - `extension=ffi`
  - `ffi.enable=true`
- GCC (MinGW) en PATH para compilar `csv_reader.dll`.

## Compilar la DLL

```powershell
./build.ps1
```

## Ejecutar servidor local

```powershell
php -d upload_max_filesize=2048M -d post_max_size=2048M -d memory_limit=4096M -d max_execution_time=0 -S 127.0.0.1:8080
```

Luego abre:

- http://127.0.0.1:8080

## Flujo técnico resumido

1. Frontend divide el archivo en chunks y los envía secuencialmente.
2. Backend acumula bytes en archivo temporal de sesión.
3. Al finalizar, se crea bandera `.complete`.
4. SSE lee chunks de filas desde C (`csv_read_chunk`) y envía eventos `rows`.
5. Frontend encola filas y renderiza solo las visibles.

## Notas importantes

- El proyecto está en modo **FFI obligatorio** (sin fallback a parser PHP).
- Cada subida vive en sesión con TTL; al terminar se limpia archivo temporal.
- Si falla FFI, verifica `csv_reader.dll` y configuración de `php.ini`.
