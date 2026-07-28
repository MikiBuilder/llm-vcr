# Comprobación previa a publicar — versión para Windows / PowerShell.
#
#   .\bin\verificar.ps1
#
# Equivalente a bin/verificar.sh. Verifica el entorno y que el proyecto
# esté listo para subir a GitHub. No modifica nada.

$ErrorActionPreference = 'Continue'

$script:Fallos = 0
$script:Avisos = 0

function Titulo($texto) {
    Write-Host ""
    Write-Host $texto -ForegroundColor Cyan
    Write-Host ("-" * 56)
}
function Ok($texto)    { Write-Host "  [OK] $texto" -ForegroundColor Green }
function Fallo($texto) { Write-Host "  [X]  $texto" -ForegroundColor Red;    $script:Fallos++ }
function Aviso($texto) { Write-Host "  [!]  $texto" -ForegroundColor Yellow; $script:Avisos++ }

# Situarse en la raíz del proyecto, venga de donde venga la llamada.
Set-Location (Split-Path $PSScriptRoot -Parent)

Titulo "1 - Requisitos del sistema"

$php = Get-Command php -ErrorAction SilentlyContinue
if ($php) {
    $version = (& php -r "echo PHP_VERSION;") 2>$null
    $suficiente = (& php -r "exit(PHP_VERSION_ID >= 80200 ? 0 : 1);") 2>$null
    if ($LASTEXITCODE -eq 0) { Ok "PHP $version" }
    else { Fallo "PHP $version - se necesita 8.2 o superior" }

    # Se cachea la lista de módulos: llamar a php -m repetidamente es lento.
    $modulos = (& php -m) 2>$null

    foreach ($ext in @('json', 'mbstring')) {
        if ($modulos -contains $ext) { Ok "extension $ext" }
        else { Fallo "falta la extension $ext" }
    }
    if ($modulos -contains 'dom') { Ok "extension dom (la necesita PHPUnit)" }
    else { Aviso "falta la extension dom - PHPUnit no podra instalarse" }
} else {
    Fallo "PHP no esta instalado o no esta en el PATH"
}

if (Get-Command composer -ErrorAction SilentlyContinue) { Ok "Composer instalado" }
else { Fallo "Composer no esta instalado - https://getcomposer.org/download/" }

if (Get-Command git -ErrorAction SilentlyContinue) { Ok "Git instalado" }
else { Fallo "Git no esta instalado - https://git-scm.com/download/win" }

Titulo "2 - Dependencias"

if (Test-Path 'vendor') { Ok "vendor/ presente" }
else { Aviso "falta vendor/ - ejecuta: composer install" }

Titulo "3 - Calidad del codigo"

if (Test-Path 'vendor/bin/phpstan') {
    & php vendor/bin/phpstan analyse --no-progress --quiet 2>&1 | Out-Null
    if ($LASTEXITCODE -eq 0) { Ok "PHPStan nivel 9 sin errores" }
    else { Fallo "PHPStan encuentra errores - ejecuta: php vendor/bin/phpstan analyse" }
} else {
    Aviso "PHPStan no instalado"
}

if (Test-Path 'vendor/bin/phpunit') {
    $salida = (& php vendor/bin/phpunit --testsuite=unit 2>&1) -join "`n"
    $resumen = ($salida -split "`n" | Where-Object { $_ -match '^OK' } | Select-Object -First 1)
    if ($resumen) { Ok $resumen.Trim() }
    else { Fallo "Hay tests en rojo - ejecuta: php vendor/bin/phpunit" }
} else {
    Aviso "PHPUnit no instalado"
}

Titulo "4 - Seguridad antes de publicar"

if (Test-Path '.env') {
    & git check-ignore .env 2>&1 | Out-Null
    if ($LASTEXITCODE -eq 0) { Ok ".env existe y esta ignorado por git" }
    else { Fallo ".env NO esta ignorado - tu clave acabaria en GitHub" }
} else {
    Aviso ".env no existe todavia (normal si aun no usas Groq)"
}

$cassettes = @(Get-ChildItem -Path 'cassettes' -Filter '*.json' -ErrorAction SilentlyContinue)
if ($cassettes.Count -gt 0) {
    $fuga = Select-String -Path 'cassettes\*.json' -Pattern 'gsk_[A-Za-z0-9]{20,}|sk-[A-Za-z0-9_-]{16,}' -ErrorAction SilentlyContinue
    if ($fuga) { Fallo "HAY UNA CLAVE EN LAS CASSETTES. No hagas push." }
    else { Ok "cassettes sin credenciales ($($cassettes.Count) ficheros)" }
} else {
    Aviso "aun no hay cassettes grabadas (paso 6 de la guia)"
}

# Las claves de ejemplo de los tests son deliberadamente falsas y se excluyen,
# para no dar una alarma que el lector aprenderia a ignorar.
$sospechosas = Get-ChildItem -Recurse -Include '*.php','*.md','*.json' -ErrorAction SilentlyContinue |
    Where-Object { $_.FullName -notmatch '\\vendor\\' } |
    Select-String -Pattern 'gsk_[A-Za-z0-9]{20,}' -ErrorAction SilentlyContinue |
    Where-Object { $_.Line -notmatch 'gsk_aB3dEf5hIj7kLm9nOp1qRs3tUv5w' }

if ($sospechosas) {
    Fallo "Hay algo que parece una clave de Groq real en el codigo:"
    $sospechosas | Select-Object -First 3 | ForEach-Object { Write-Host "       $($_.Path):$($_.LineNumber)" }
} else {
    Ok "sin claves reales en el codigo fuente"
}

Titulo "5 - Ficheros para publicar"

foreach ($f in @('composer.json','README.md','LICENSE','CHANGELOG.md','.gitignore','.gitattributes')) {
    if (Test-Path $f) { Ok $f } else { Fallo "falta $f" }
}

if ((Test-Path 'composer.json') -and (Get-Command composer -ErrorAction SilentlyContinue)) {
    & composer validate --strict --no-check-publish 2>&1 | Out-Null
    if ($LASTEXITCODE -eq 0) { Ok "composer.json valido" }
    else { Aviso "composer validate da avisos" }
}

Titulo "Resumen"

if ($script:Fallos -eq 0 -and $script:Avisos -eq 0) {
    Write-Host "  Todo correcto. Listo para el paso 2 de GUIA-PASO-A-PASO.md" -ForegroundColor Green
    Write-Host ""
    exit 0
} elseif ($script:Fallos -eq 0) {
    Write-Host "  Sin errores, $($script:Avisos) aviso(s) - puedes continuar." -ForegroundColor Green
    Write-Host ""
    exit 0
} else {
    Write-Host "  $($script:Fallos) error(es) y $($script:Avisos) aviso(s) - corrigelos antes de publicar." -ForegroundColor Red
    Write-Host ""
    exit 1
}
