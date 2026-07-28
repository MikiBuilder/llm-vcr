# Guía paso a paso — de cero a Packagist

**Escrita para Windows y PowerShell**, que es lo que estás usando.
Al final de cada paso hay una comprobación: si sale bien, avanzas. Si falla, para y dímelo.

**Tiempo total: unos 30 minutos.** Todo gratuito, sin tarjeta de crédito.

---

## Antes de empezar: dos reglas

**1. Copia solo el comando, nunca el prompt.**

Cuando veas un bloque como este, copia únicamente la línea del comando:

```powershell
composer install
```

Si copias también el `PS D:\llm-vcr>` del principio, PowerShell te dará
`Get-Process : No se encuentra ningún parámetro de posición`. Es inofensivo,
pero confunde.

**2. Un comando cada vez.** Ejecuta, mira el resultado, y solo entonces pasa al
siguiente.

---

## Sobre Docker: no lo necesitas

Docker aquí sería solo para *ti* desarrollando, y **solo si no tuvieras PHP**.
Como ya tienes PHP y Composer funcionando, no aporta nada.

Quien use tu paquete tampoco lo necesita: es una librería, se instala con
`composer require`. El `Dockerfile` está en el repo como cortesía para
colaboradores sin PHP.

**Sáltatelo.** Es el paso 8 y es opcional.

---

## PASO 1 · Verifica que todo funciona

Abre PowerShell en la carpeta del proyecto:

```powershell
cd D:\llm-vcr
```

Si aún no lo hiciste (o si acabas de descomprimir el ZIP nuevo encima):

```powershell
composer install
```

Ahora comprueba que el código está sano:

```powershell
php vendor/bin/phpstan analyse --no-progress
```

```powershell
php vendor/bin/phpunit --testsuite=unit
```

**✅ Comprobación:** debes ver `[OK] No errors` y `OK (79 tests, 189 assertions)`.

<details>
<summary>Opcional: el verificador completo</summary>

```powershell
.\bin\verificar.ps1
```

Si sale un error de directivas de ejecución (protección por defecto de Windows):

```powershell
Set-ExecutionPolicy -Scope Process -ExecutionPolicy Bypass
```

Solo afecta a esa ventana. Nada queda cambiado en tu sistema.

**Aviso honesto:** este script lo escribí sin poder ejecutarlo (no tengo
PowerShell en mi entorno). Los dos comandos de arriba son lo que de verdad
importa; el script es solo comodidad.

</details>

<details>
<summary>Si algo falla</summary>

- **`php: command not found`** → instala PHP 8.2+ y añádelo al PATH
- **`composer: command not found`** → <https://getcomposer.org/download/>
- **`falta la extensión dom`** → abre tu `php.ini` y quita el `;` de `extension=dom`
- **Tests en rojo** → no sigas, pásame el error

</details>

---

## PASO 2 · Configura git (ANTES del primer commit)

Esto es importante hacerlo **ahora**: si commiteas primero, tu email real queda
grabado en el historial de git, que es público y muy incómodo de borrar después.

```powershell
git init
```

```powershell
git config user.name "MikiBuilder"
```

```powershell
git config user.email "264189149+MikiBuilder@users.noreply.github.com"
```

**✅ Comprobación:**

```powershell
git config user.email
```

Debe imprimir `264189149+MikiBuilder@users.noreply.github.com`.

> Sin `--global`, esta configuración aplica **solo a este proyecto**. Tus otros
> repositorios no se ven afectados.

---

## PASO 3 · Crea el repositorio en GitHub

Esto es en el navegador, no en la consola.

1. Ve a <https://github.com/new>
2. **Repository name:** `llm-vcr`
3. **Description:**
   > Record & replay semántico para features de IA en PHP. Testea tus LLMs sin red, sin API key y sin gastar un céntimo.
4. Marca **Public**
5. ⚠️ **NO marques nada más.** Ni "Add a README", ni ".gitignore", ni "license".
   Ya los tenemos en la carpeta, y si GitHub los crea tendrás un conflicto al
   subir.
6. Pulsa **Create repository**

GitHub te mostrará una página con instrucciones. **Ignórala**, usa las del paso 4.

**✅ Comprobación:** existe `https://github.com/MikiBuilder/llm-vcr` y aparece vacío.

---

## PASO 4 · Sube el código

Vuelve a PowerShell, en `D:\llm-vcr`.

Primero mira qué se va a subir:

```powershell
git status
```

**⚠️ Importante:** en esa lista **no debe aparecer `.env` ni `vendor/`**. Si
aparecen, para y avísame.

Ahora sí:

```powershell
git add .
```

```powershell
git commit -m "feat: llm-vcr v0.1.0 - record & replay semantico para LLMs en PHP"
```

```powershell
git branch -M main
```

```powershell
git remote add origin https://github.com/MikiBuilder/llm-vcr.git
```

```powershell
git push -u origin main
```

<details>
<summary>Si te pide usuario y contraseña</summary>

GitHub ya no acepta la contraseña de la cuenta. Necesitas un token:

1. <https://github.com/settings/tokens?type=beta>
2. **Generate new token** → *Fine-grained*
3. **Repository access** → Only select repositories → `llm-vcr`
4. **Permissions** → Repository permissions → **Contents: Read and write**
5. **Generate token** y cópialo
6. Cuando git pida la contraseña, **pega el token** (no se verá al escribir, es normal)

</details>

**✅ Comprobación:** recarga `https://github.com/MikiBuilder/llm-vcr`. Verás
tus ficheros y el README renderizado.

---

## PASO 5 · Mira cómo el CI se ejecuta solo

Ve a la pestaña **Actions** de tu repositorio.

Verás un workflow ejecutándose automáticamente. No hay que configurar nada:
el fichero `.github/workflows/ci.yml` ya viene en el repo, y GitHub Actions es
**gratis e ilimitado en repositorios públicos**.

Tarda 1–2 minutos. Al terminar verás tres jobs:

```
✓ PHP 8.2      ✓ PHP 8.3      ✓ PHP 8.4
```

**✅ Comprobación:** los tres en verde.

**Párate aquí un momento.** Esto es lo que convierte una carpeta en un proyecto
real: cualquiera del mundo puede comprobar que tus 79 tests pasan en tres
versiones de PHP.

<details>
<summary>Si algún job sale en rojo</summary>

Pincha en el job rojo, abre el paso que falló y cópiame el error. Suele ser una
diferencia de entorno, no un fallo del código.

</details>

---

## PASO 6 · Graba cassettes reales con Groq

Hasta ahora todo funciona con una plataforma simulada. Vamos a probarlo contra
un LLM de verdad.

1. Entra en <https://console.groq.com/keys>
2. Inicia sesión con Google o GitHub (**no pide tarjeta**)
3. **Create API Key** → cópiala (empieza por `gsk_`)

En PowerShell:

```powershell
Copy-Item .env.example .env
```

```powershell
notepad .env
```

Pega tu clave después de `GROQ_API_KEY=`, guarda y cierra.

Ahora graba:

```powershell
php examples/groq_record.php
```

La primera vez llama a la API real. Ejecútalo otra vez:

```powershell
php examples/groq_record.php
```

**✅ Comprobación:** la segunda vez debe decir `API real: 0` y `Hit rate: 100%`.
**Eso es el paquete funcionando**: la misma respuesta, sin tocar la red.

Antes de subir nada, comprueba que no se cuela ningún secreto:

```powershell
Select-String -Path cassettes\*.json -Pattern "gsk_"
```

**No debe devolver nada.** Si devuelve algo, para y avísame.

Súbelas:

```powershell
git add cassettes/
```

```powershell
git commit -m "test: cassettes grabadas contra Groq llama-3.1-8b-instant"
```

```powershell
git push
```

> ⚠️ `.env` está en `.gitignore`. **Nunca lo subas.** Si `git status` lo
> menciona alguna vez, para y dímelo.

---

## PASO 7 · Publica en Packagist

Hazlo **solo si el paso 5 está en verde**.

Crea la versión:

```powershell
git tag -a v0.1.0 -m "v0.1.0 - primera version publica"
```

```powershell
git push origin v0.1.0
```

Ahora en el navegador:

1. <https://packagist.org> → **Sign in with GitHub**
2. Pulsa **Submit** (arriba a la derecha)
3. Pega: `https://github.com/MikiBuilder/llm-vcr`
4. **Check** → **Submit**

Packagist te ofrecerá activar el hook de GitHub para actualizarse solo en cada
push. Actívalo.

**✅ Comprobación:** desde otra carpeta cualquiera:

```powershell
composer require --dev mikibuilder/llm-vcr
```

Si se instala, **tu paquete está publicado y cualquiera en el mundo puede
usarlo.**

---

## PASO 8 · Docker (OPCIONAL — sáltalo)

Solo si algún día quieres el entorno en contenedor:

```powershell
docker compose build
docker compose up -d
docker compose exec php composer install
docker compose exec php php vendor/bin/phpunit
```

> Esta es la parte que no he podido verificar (no tengo Docker en mi entorno).
> Si falla, mándame el error completo.

---

## PASO 9 · Activa la detección de deriva nocturna

Para que el cron pueda hablar con Groq necesita tu clave como secreto:

1. Tu repo → **Settings** → **Secrets and variables** → **Actions**
2. **New repository secret**
3. **Name:** `GROQ_API_KEY`
4. **Secret:** tu clave
5. **Add secret**

Cada noche a las 4:00 reproducirá tus cassettes contra Groq y **abrirá un issue
automáticamente** si el modelo ha cambiado de comportamiento.

Para probarlo ya: **Actions** → *Detección de deriva* → **Run workflow**.

---

## Resumen

| Paso | Qué consigues | ¿Obligatorio? |
|---|---|---|
| 1 | Entorno verificado | Sí |
| 2 | Git configurado sin exponer tu email | Sí |
| 3–4 | Código público en GitHub | Sí |
| 5 | **CI en verde: el proyecto es demostrablemente real** | Sí |
| 6 | Funciona contra un LLM de verdad | Recomendado |
| 7 | **Publicado en Packagist** | Cuando estés listo |
| 8 | Docker | No |
| 9 | Vigilancia automática de deriva | Recomendado |

---

## Y después

Por orden de impacto:

1. **Bundle de Symfony con panel en el Web Profiler** ← el mayor multiplicador.
   Esa captura de pantalla es lo que se comparte en redes.
2. **Artículo:** *"Cómo testear features de IA en PHP sin gastar un céntimo"*.
   Empieza por el dolor, no por la solución.
3. **Charla en PHP Barcelona.** Lo tienes en casa y el tema es actual.

---

## Chuleta de comandos

```powershell
# Comprobar que todo sigue bien
php vendor/bin/phpunit --testsuite=unit
php vendor/bin/phpstan analyse --no-progress

# Demo sin API key
php examples/demo.php

# Grabar contra Groq
php examples/groq_record.php

# Ver estado de las cassettes
php bin/llm-vcr stats

# Comprobar deriva del modelo
php bin/llm-vcr drift

# Subir cambios
git add .
git commit -m "descripcion del cambio"
git push
```
