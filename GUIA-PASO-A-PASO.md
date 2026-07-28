# Guía paso a paso — de la carpeta descargada a Packagist

Sigue los pasos **en orden**. Cada uno tiene una comprobación al final: si esa
comprobación sale bien, pasa al siguiente. Si falla, para y dímelo.

**Tiempo total: unos 30 minutos.** Todo gratuito, sin tarjeta de crédito.

---

## Primero, la duda que planteaste: ¿Docker sí o no?

**Respuesta corta: no lo necesitas para publicar. Es opcional.**

Docker en este proyecto sirve para dos cosas distintas, y conviene no confundirlas:

| | |
|---|---|
| **Para TI, desarrollando** | Opcional. Si ya tienes PHP 8.2+ y Composer en tu máquina, no aporta nada. |
| **Para QUIEN USE tu paquete** | Irrelevante. Nadie necesita Docker para hacer `composer require`. Es una librería, no una app. |

El `Dockerfile` está en el repo porque a un colaborador que no tenga PHP le
viene bien poder contribuir sin instalar nada. Es cortesía, no requisito.

**Mi recomendación:** haz los pasos 1–4 **sin Docker**. Es más rápido y menos
piezas que puedan fallar. El paso 7 es opcional y lo dejas para cuando quieras.

> **Aviso honesto:** no he podido ejecutar el Dockerfile (no hay Docker en mi
> entorno). Revisándolo encontré y corregí dos fallos: le faltaba la extensión
> `dom` (PHPUnit no habría arrancado) y no existía `.dockerignore` (habría
> copiado tu `vendor/` y tu `.env` dentro de la imagen). Ahora debería
> funcionar, pero es la única parte no verificada por mí.

---

## PASO 1 · Comprueba que todo funciona en tu máquina

Abre una terminal en la carpeta que descomprimiste.

```bash
cd llm-vcr
composer install
```

Luego, según tu sistema:

**Windows (PowerShell):**

```powershell
.\bin\verificar.ps1
```

**Linux, macOS o Git Bash:**

```bash
bash bin/verificar.sh
```

**✅ Comprobación:** debe terminar con
`Sin errores, 2 aviso(s) — puedes continuar.`

Los 2 avisos son normales (aún no tienes `.env` ni cassettes).

> **Nota para Windows:** si al ejecutar el `.ps1` sale un error de directivas
> de ejecución, es la protección por defecto de PowerShell. Ejecuta esto una
> vez en esa misma ventana y vuelve a intentarlo:
>
> ```powershell
> Set-ExecutionPolicy -Scope Process -ExecutionPolicy Bypass
> ```
>
> Solo afecta a esa ventana; nada queda cambiado en tu sistema.
>
> Alternativa sin scripts, escribiendo los dos comandos a mano:
>
> ```powershell
> php vendor/bin/phpstan analyse --no-progress
> php vendor/bin/phpunit --testsuite=unit
> ```

<details>
<summary>Errores frecuentes al copiar y pegar</summary>

**`Get-Process : No se encuentra ningún parámetro de posición...`**
Has copiado el prompt (`PS D:\llm-vcr>`) junto con el comando. Copia solo el
comando, sin el `PS ...>` del principio.

**`El término 'Loading' no se reconoce...`**
Has pegado la *salida* de un comando anterior en vez de un comando. Es
inofensivo: PowerShell intenta ejecutar el texto como si fuera una orden.

**`execvpe(/bin/bash) failed: No such file or directory`**
No tienes bash. Usa `.\bin\verificar.ps1` en su lugar.

</details>

<details>
<summary>Si falla algo de verdad</summary>

- **`composer: command not found`** → instala Composer: <https://getcomposer.org/download/>
- **`php: command not found`** → instala PHP 8.2 o superior
- **`falta la extensión dom`** → en Windows, descomenta `extension=dom` en tu
  `php.ini` · en Ubuntu/Debian: `sudo apt install php-xml`
- **Errores en los tests** → no sigas, dímelo

</details>

---

## PASO 2 · Configura git con tu email de GitHub

**Hazlo ANTES del primer commit.** Si no, tu email real queda grabado en el
historial, que es público y muy incómodo de borrar después.

```bash
git init
git config user.name "MikiBuilder"
git config user.email "264189149+MikiBuilder@users.noreply.github.com"
```

**✅ Comprobación:**

```bash
git config user.email
```

Debe imprimir el email `...@users.noreply.github.com`.

> Nota: `git config` sin `--global` aplica **solo a este proyecto**. Tus otros
> repos mantienen su configuración.

---

## PASO 3 · Crea el repositorio en GitHub

1. Entra en <https://github.com/new>
2. **Repository name:** `llm-vcr`
3. **Description:**
   > Record & replay semántico para features de IA en PHP. Testea tus LLMs sin red, sin API key y sin gastar un céntimo.
4. Marca **Public**
5. **NO marques nada más.** Ni README, ni .gitignore, ni licencia — ya los
   tenemos, y si los creas ahí tendrás un conflicto al hacer push.
6. Pulsa **Create repository**

GitHub te enseñará una página con instrucciones. **Ignórala**, usa las de abajo.

---

## PASO 4 · Sube el código

```bash
git add .
git commit -m "feat: llm-vcr v0.1.0 — record & replay semántico para LLMs en PHP"
git branch -M main
git remote add origin https://github.com/MikiBuilder/llm-vcr.git
git push -u origin main
```

<details>
<summary>Si te pide usuario y contraseña</summary>

GitHub ya no acepta la contraseña de la cuenta. Necesitas un token:

1. <https://github.com/settings/tokens?type=beta>
2. **Generate new token** → *Fine-grained*
3. **Repository access:** Only select repositories → `llm-vcr`
4. **Permissions** → Repository permissions → **Contents: Read and write**
5. Genera, copia el token
6. Cuando git pida la contraseña, **pega el token**

</details>

**✅ Comprobación:** recarga `https://github.com/MikiBuilder/llm-vcr` y verás
tus 50 ficheros con el README renderizado.

---

## PASO 5 · Mira cómo el CI se ejecuta solo

Ve a la pestaña **Actions** de tu repo. Verás un workflow corriendo.

GitHub Actions es **gratis e ilimitado en repos públicos**. No hay que
configurar nada: el fichero `.github/workflows/ci.yml` ya está en el repo.

Tarda 1–2 minutos. Cuando acabe verás tres jobs en verde:

```
✓ PHP 8.2    ✓ PHP 8.3    ✓ PHP 8.4
```

**✅ Comprobación:** los tres en verde.

**Párate un momento aquí.** Esto es lo que convierte una carpeta en un proyecto
real: cualquiera puede ver que tus 79 tests pasan en tres versiones de PHP.

<details>
<summary>Si algún job sale en rojo</summary>

Pincha en el job rojo y lee el error. Cópiamelo y lo arreglamos. Suele ser una
diferencia de entorno, no un fallo del código.

</details>

---

## PASO 6 · Graba cassettes reales con Groq

Hasta ahora todo funciona con una plataforma simulada. Vamos a probarlo contra
un LLM de verdad.

1. Entra en <https://console.groq.com/keys> (entra con Google o GitHub, **no
   pide tarjeta**)
2. **Create API Key** → cópiala (empieza por `gsk_`)
3. En tu proyecto:

```bash
cp .env.example .env
```

4. Abre `.env` y pega la clave en `GROQ_API_KEY=`

Ahora graba:

```bash
php examples/groq_record.php
```

La primera vez llama a la API real. Vuelve a ejecutarlo:

```bash
php examples/groq_record.php
```

**✅ Comprobación:** la segunda vez debe decir `API real: 0` y
`Hit rate: 100%`. Eso es el paquete funcionando.

Antes de subir nada, comprueba que no se te cuela ningún secreto:

```bash
bash bin/verificar.sh
```

Debe decir `✓ cassettes sin credenciales`.

Súbelas:

```bash
git add cassettes/
git commit -m "test: cassettes grabadas contra Groq llama-3.1-8b-instant"
git push
```

> ⚠️ `.env` está en `.gitignore`. **Nunca lo commitees.** Si `git status` lo
> menciona alguna vez, para y avísame.

---

## PASO 7 · Docker (OPCIONAL — sáltalo si no lo necesitas)

Solo si quieres el entorno en contenedor:

```bash
make build      # construye la imagen
make up         # levanta el contenedor
make install    # instala dependencias dentro
make check      # PHPStan + tests dentro del contenedor
```

`make help` lista todos los comandos.

**✅ Comprobación:** `make check` debe dar 79 tests OK.

> Esta es la parte que no pude probar. Si falla, mándame el error completo.

---

## PASO 8 · Publica en Packagist

Hazlo **solo cuando el paso 5 esté en verde y tengas las cassettes del paso 6**.

Primero, crea la versión:

```bash
git tag -a v0.1.0 -m "v0.1.0 — primera versión pública"
git push origin v0.1.0
```

Luego:

1. <https://packagist.org> → **Sign in with GitHub**
2. **Submit** (arriba a la derecha)
3. Pega: `https://github.com/MikiBuilder/llm-vcr`
4. **Check** → **Submit**

Packagist te sugerirá activar el hook de GitHub para que se actualice solo
en cada push. Actívalo.

**✅ Comprobación:** entra desde otra carpeta cualquiera y prueba:

```bash
composer require --dev mikibuilder/llm-vcr
```

Si se instala, **tu paquete está publicado y cualquiera en el mundo puede
usarlo.**

---

## PASO 9 · Activa la detección de deriva nocturna

Para que el cron pueda hablar con Groq:

1. Tu repo → **Settings** → **Secrets and variables** → **Actions**
2. **New repository secret**
3. **Name:** `GROQ_API_KEY`
4. **Secret:** tu clave
5. **Add secret**

Cada noche a las 4:00 reproducirá tus cassettes contra Groq y **abrirá un
issue automáticamente** si el modelo ha cambiado de comportamiento.

Para probarlo ya: **Actions** → *Detección de deriva* → **Run workflow**.

---

## Resumen de dónde estás

| Paso | Qué consigues |
|---|---|
| 1–2 | Entorno verificado y git configurado sin exponer tu email |
| 3–4 | Código público en GitHub |
| 5 | **CI en verde: el proyecto es demostrablemente real** |
| 6 | Funciona contra un LLM de verdad |
| 7 | Docker (opcional) |
| 8 | **Publicado: `composer require mikibuilder/llm-vcr`** |
| 9 | Vigilancia automática de deriva |

---

## Y después

Cuando esto esté hecho, lo siguiente por orden de impacto:

1. **Bundle de Symfony con panel en el Web Profiler** ← el mayor multiplicador.
   Esa captura de pantalla es lo que se comparte en redes.
2. **Artículo:** *"Cómo testear features de IA en PHP sin gastar un céntimo"*.
   Empieza por el dolor, no por la solución.
3. **Charla en PHP Barcelona.** Lo tienes en casa y el tema es actual.
