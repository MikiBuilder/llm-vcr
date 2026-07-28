# Empezar aquí — guía paso a paso

Esto es lo que ya está construido y lo que tienes que hacer tú.
**Todo con recursos gratuitos: sin tarjeta de crédito en ningún paso.**

> **¿Vas bien de tiempo?** Sí. No has perdido nada por no haber creado aún el repositorio:
> el código está completo y verificado aquí. Los pasos 1 y 2 son 15 minutos en total.

---

## Estado actual

| | |
|---|---|
| Paquete | `mikibuilder/llm-vcr` |
| Namespace | `MikiBuilder\LlmVcr\` |
| Tests | **79 en verde**, 189 aserciones (+ 4 tests Pest verificados aparte) |
| Análisis estático | **PHPStan nivel 9, sin errores** |
| PHP soportado | 8.2, 8.3, 8.4 |
| Dependencias de runtime | **ninguna** (solo `ext-json` y `ext-mbstring`) |
| Licencia | MIT |

---

## PASO 0 · Descarga el proyecto (2 min)

Todo vive en la carpeta `llm-vcr/` de este workspace. Descárgala a tu máquina y colócala
donde guardes tus proyectos. Luego:

```bash
cd llm-vcr
composer install
composer check      # PHPStan nivel 9 + los 79 tests
php examples/demo.php
```

Si `composer check` sale en verde, tienes exactamente lo mismo que hay aquí.

> **Nota sobre Docker:** el `Dockerfile` y el `docker-compose.yml` están escritos pero **no he
> podido ejecutarlos** (no hay Docker en este entorno). Todo lo demás sí está verificado
> ejecutándose. Si al hacer `make build` algo falla, dímelo y lo corrijo.

---

## PASO 1 · Crea el repositorio en GitHub (5 min, gratis)

1. Entra en <https://github.com/new>
2. Nombre: **`llm-vcr`**
3. Descripción:
   > Record & replay semántico para features de IA en PHP. Testea tus LLMs sin red, sin API key y sin gastar un céntimo.
4. **Público**
5. **No** marques "Add README", ".gitignore" ni licencia — ya los tenemos.

Después, desde la carpeta del proyecto:

```bash
cd llm-vcr
git init
git add .
git commit -m "feat: núcleo de llm-vcr con integración PHPUnit y Pest"
git branch -M main
git remote add origin https://github.com/MikiBuilder/llm-vcr.git
git push -u origin main
```

> Si te pide contraseña: GitHub ya no acepta la de la cuenta. Crea un token en
> Settings → Developer settings → Personal access tokens → Fine-grained,
> con permiso `Contents: Read and write`.

En cuanto hagas push, **el CI se ejecuta solo** (GitHub Actions es gratis en repos públicos)
y verás PHP 8.2, 8.3 y 8.4 en verde.

**Este es el momento de parar y mirar.** Si los tres badges están verdes, el proyecto es real.

---

## PASO 2 · Consigue tu clave de Groq (2 min, sin tarjeta)

1. <https://console.groq.com/keys> → entra con Google o GitHub
2. *Create API Key* → cópiala (empieza por `gsk_`)
3. En el proyecto:

```bash
cp .env.example .env
# pega la clave en GROQ_API_KEY
```

Graba tus primeras cassettes **reales**:

```bash
php examples/groq_record.php   # 1ª vez: llama a la API
php examples/groq_record.php   # 2ª vez: desde disco, sin red
```

**Free tier:** 30 peticiones/min y ~1.000/día con `llama-3.1-8b-instant`.

⚠️ **`.env` está en `.gitignore`. Nunca lo commitees.**

Cuando tengas cassettes reales grabadas, commitéalas: son la prueba de que funciona contra
un modelo de verdad.

```bash
git add cassettes/
git commit -m "test: cassettes grabadas contra Groq"
git push
```

---

## PASO 3 · Publica en Packagist (10 min, gratis)

Hazlo **cuando el CI esté en verde y tengas cassettes reales.**

```bash
git tag -a v0.1.0 -m "v0.1.0 — primera versión pública"
git push origin v0.1.0
```

1. <https://packagist.org> → *Sign in with GitHub*
2. *Submit* → pega `https://github.com/MikiBuilder/llm-vcr`
3. Activa el hook de GitHub para que se actualice solo

A partir de ahí, cualquiera podrá:

```bash
composer require --dev mikibuilder/llm-vcr
```

---

## PASO 4 · Configura el secreto para la detección de deriva

Para que el cron nocturno funcione:

1. Tu repo → Settings → Secrets and variables → Actions
2. *New repository secret*
3. Nombre: `GROQ_API_KEY` · Valor: tu clave

Cada noche a las 4:00 reproduce las cassettes contra Groq y **abre un issue automáticamente**
si detecta deriva.

---

## Qué falta por construir

Por orden de impacto:

### 1. Bundle de Symfony (~1 semana) ← **el mayor multiplicador**
Configuración declarativa, `services_test.yaml` que active replay automáticamente, y sobre todo
un **panel en el Web Profiler** con hits/misses y tokens ahorrados.
Ese panel es la captura de pantalla que se comparte en redes.

### 2. Tests de integración con cassettes reales
`tests/Integration/` está vacío a propósito. Cuando grabes con Groq, mete esas cassettes como
fixtures del paquete.

### 3. `EmbeddingMatcher` con caché
Para prompts largos donde el bag-of-words se queda corto.

---

## Cómo lo damos a conocer

Aquí tus 20 años formando gente valen más que el código.

1. **Artículo:** *"Cómo testear features de IA en Symfony sin gastar un céntimo"*.
   Empieza por el dolor, no por la solución. dev.to y LinkedIn.
2. **Vídeo de 3 minutos** enseñando la detección de deriva.
3. **Issue en `symfony/ai`** presentando el paquete.
4. **Charla en PHP Barcelona.** Lo tienes en casa.
5. **r/PHP y Symfony Slack.** Sin vender: cuenta el problema.

---

## Decisiones de diseño que conviene saber defender

**¿Por qué un decorador y no un fork?**
Sustitución de Liskov: envuelve cualquier `PlatformInterface` sin tocar el código de negocio.
Cuando Symfony AI saque la 1.0, un adaptador de tres líneas y listo.

**¿Por qué coseno y no embeddings?**
Los embeddings requerirían una llamada de red justo en el camino que intenta evitarla.

**¿Por qué la redacción va activada por defecto?**
Porque las cassettes se commitean. Si fuese opt-in, sería un generador de filtraciones.

**¿Por qué el matching se hace sobre texto ya redactado?**
Bug real que encontramos: la cassette guarda `<REDACTED:EMAIL>`, así que comparar contra el
prompt en crudo fallaba justo en las peticiones con PII. Hay test de regresión.

**¿Por qué el registro de Pest usa `class_exists()`?**
Otro bug real: Composer carga los ficheros `files` en orden de dependencias y este paquete no
depende de Pest, así que se ejecutaba antes de que sus funciones globales existieran. Con
`function_exists()` las expectativas no se registraban **nunca, en silencio**. Hay un test que
lee el código fuente y falla si alguien lo vuelve a poner mal.

**¿Por qué escritura atómica de cassettes?**
Fichero temporal + `rename()`. Si dos tests en paralelo graban a la vez, nunca queda un JSON
a medias.
