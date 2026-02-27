# build.ps1 — compila csv_reader.c como DLL (FFI)
# Requiere GCC (MinGW) en el PATH. Instálalo con: winget install GnuWin32.GCC
# o descargándolo desde https://winlibs.com/

$src = Join-Path $PSScriptRoot "csv_reader.c"
$dll = Join-Path $PSScriptRoot "csv_reader.dll"

function Get-PHPBitness {
    $php = Get-Command php -ErrorAction SilentlyContinue
    if (-not $php) { return $null }

    $bits = & php -r "echo PHP_INT_SIZE * 8;"
    if ($LASTEXITCODE -ne 0) { return $null }
    return [string]$bits
}

function Invoke-GCC {
    param([string[]]$GccArgs)
    $gcc = Get-Command gcc -ErrorAction SilentlyContinue
    if (-not $gcc) {
        Write-Error "gcc no encontrado en el PATH. Instala MinGW/GCC antes de continuar."
        exit 1
    }
    Write-Host "gcc $($GccArgs -join ' ')"
    & gcc @GccArgs
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

function Assert-DllCompatibility {
    $phpBits = Get-PHPBitness
    if (-not $phpBits) {
        Write-Warning "No se pudo detectar la arquitectura de PHP; se omite verificación de compatibilidad."
        return
    }

    $gccTarget = (& gcc -dumpmachine 2>$null)
    if ($LASTEXITCODE -ne 0 -or [string]::IsNullOrWhiteSpace($gccTarget)) {
        Write-Warning "No se pudo detectar el target de gcc; se omite verificación de compatibilidad."
        return
    }

    $gccBits = if ($gccTarget -match "64") { "64" } else { "32" }

    if ($phpBits -ne $gccBits) {
        Write-Error "Incompatibilidad de arquitectura: PHP es ${phpBits}-bit y gcc target es '$gccTarget' (${gccBits}-bit). Instala un compilador ${phpBits}-bit para generar csv_reader.dll compatible."
        exit 1
    }
}

Assert-DllCompatibility
Write-Host "Compilando DLL (FFI)..."
Invoke-GCC @($src, "-shared", "-O2", "-o", $dll)
Write-Host "OK: $dll"

Write-Host ""
Write-Host "Listo. Asegúrate de que en php.ini estén habilitadas:"
Write-Host "  extension=ffi"
Write-Host "  ffi.enable=true"
