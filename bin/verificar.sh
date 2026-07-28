#!/usr/bin/env bash
#
# Comprobación previa a publicar.
#
#   bash bin/verificar.sh
#
# Verifica que el entorno es correcto y que el proyecto está listo
# para subir a GitHub. No modifica nada.

set -uo pipefail

VERDE='\033[0;32m'; ROJO='\033[0;31m'; AMBAR='\033[1;33m'; AZUL='\033[1;36m'; OFF='\033[0m'
FALLOS=0
AVISOS=0

titulo() { echo -e "\n${AZUL}$1${OFF}"; echo "────────────────────────────────────────────────────────"; }
ok()     { echo -e "  ${VERDE}✓${OFF} $1"; }
error()  { echo -e "  ${ROJO}✗${OFF} $1"; FALLOS=$((FALLOS+1)); }
aviso()  { echo -e "  ${AMBAR}!${OFF} $1"; AVISOS=$((AVISOS+1)); }

cd "$(dirname "$0")/.." || exit 1

titulo "1 · Requisitos del sistema"

if command -v php >/dev/null 2>&1; then
    VERSION=$(php -r 'echo PHP_VERSION;')
    if php -r 'exit(PHP_VERSION_ID >= 80200 ? 0 : 1);'; then
        ok "PHP $VERSION"
    else
        error "PHP $VERSION — se necesita 8.2 o superior"
    fi
else
    error "PHP no está instalado"
fi

# Se cachea la lista de extensiones en una variable: llamar a `php -m`
# repetidamente dentro de condicionales es frágil y lento.
MODULOS=""
if command -v php >/dev/null 2>&1; then
    MODULOS=$(php -m 2>/dev/null)
fi

tiene_extension() {
    printf '%s\n' "$MODULOS" | grep -qix "$1"
}

for EXT in json mbstring; do
    if tiene_extension "$EXT"; then
        ok "extensión $EXT"
    else
        error "falta la extensión $EXT"
    fi
done

if tiene_extension dom; then
    ok "extensión dom (la necesita PHPUnit)"
else
    aviso "falta la extensión dom — PHPUnit no podrá instalarse"
fi

if command -v composer >/dev/null 2>&1; then
    ok "Composer $(composer --version 2>/dev/null | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -1)"
else
    error "Composer no está instalado — https://getcomposer.org/download/"
fi

if command -v git >/dev/null 2>&1; then
    ok "Git $(git --version | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -1)"
else
    error "Git no está instalado"
fi

titulo "2 · Dependencias"

if [ -d vendor ]; then
    ok "vendor/ presente"
else
    aviso "falta vendor/ — ejecuta: composer install"
fi

titulo "3 · Calidad del código"

if [ -f vendor/bin/phpstan ]; then
    if php vendor/bin/phpstan analyse --no-progress --quiet >/dev/null 2>&1; then
        ok "PHPStan nivel 9 sin errores"
    else
        error "PHPStan encuentra errores — ejecuta: php vendor/bin/phpstan analyse"
    fi
else
    aviso "PHPStan no instalado"
fi

if [ -f vendor/bin/phpunit ]; then
    SALIDA=$(php vendor/bin/phpunit --testsuite=unit 2>&1)
    if echo "$SALIDA" | grep -q "^OK"; then
        ok "$(echo "$SALIDA" | grep '^OK' | head -1)"
    else
        error "Hay tests en rojo — ejecuta: php vendor/bin/phpunit"
    fi
else
    aviso "PHPUnit no instalado"
fi

titulo "4 · Seguridad antes de publicar"

if [ -f .env ]; then
    if git check-ignore .env >/dev/null 2>&1; then
        ok ".env existe y está ignorado por git"
    else
        error ".env NO está ignorado — tu clave acabaría en GitHub"
    fi
else
    aviso ".env no existe todavía (normal si aún no usas Groq)"
fi

FUGAS=0
if [ -d cassettes ] && compgen -G "cassettes/*.json" >/dev/null 2>&1; then
    if grep -rqE '(gsk_[A-Za-z0-9]{20,}|sk-[A-Za-z0-9_-]{16,})' cassettes/ 2>/dev/null; then
        error "¡HAY UNA CLAVE EN LAS CASSETTES! No hagas push."
        FUGAS=1
    fi
    if [ "$FUGAS" -eq 0 ]; then
        ok "cassettes sin credenciales ($(ls cassettes/*.json 2>/dev/null | wc -l | tr -d ' ') ficheros)"
    fi
else
    aviso "aún no hay cassettes grabadas (paso 2 de EMPEZAR-AQUI.md)"
fi

# Las claves de ejemplo de los tests del Redactor son deliberadamente falsas.
# Se excluyen para no dar una alarma que el lector aprendería a ignorar,
# que es la peor consecuencia posible en una comprobación de seguridad.
SOSPECHOSAS=$(grep -rnE 'gsk_[A-Za-z0-9]{20,}' \
    --include='*.php' --include='*.md' --include='*.json' . 2>/dev/null \
    | grep -v '/vendor/' \
    | grep -v 'gsk_aB3dEf5hIj7kLm9nOp1qRs3tUv5w')

if [ -n "$SOSPECHOSAS" ]; then
    error "Hay algo que parece una clave de Groq real en el código:"
    echo "$SOSPECHOSAS" | head -3 | sed 's/^/      /'
else
    ok "sin claves reales en el código fuente"
fi

titulo "5 · Ficheros para publicar"

for F in composer.json README.md LICENSE CHANGELOG.md .gitignore .gitattributes; do
    [ -f "$F" ] && ok "$F" || error "falta $F"
done

if [ -f composer.json ] && command -v composer >/dev/null 2>&1; then
    if composer validate --strict --no-check-publish >/dev/null 2>&1; then
        ok "composer.json válido"
    else
        aviso "composer validate da avisos"
    fi
fi

titulo "Resumen"

if [ "$FALLOS" -eq 0 ] && [ "$AVISOS" -eq 0 ]; then
    echo -e "  ${VERDE}Todo correcto. Listo para el paso 1 de EMPEZAR-AQUI.md${OFF}\n"
    exit 0
elif [ "$FALLOS" -eq 0 ]; then
    echo -e "  ${VERDE}Sin errores${OFF}, ${AMBAR}${AVISOS} aviso(s)${OFF} — puedes continuar.\n"
    exit 0
else
    echo -e "  ${ROJO}${FALLOS} error(es)${OFF} y ${AMBAR}${AVISOS} aviso(s)${OFF} — corrígelos antes de publicar.\n"
    exit 1
fi
