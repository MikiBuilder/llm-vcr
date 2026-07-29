# Changelog

Todos los cambios relevantes de este proyecto se documentan aquí.

El formato sigue [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/)
y el versionado sigue [SemVer](https://semver.org/lang/es/).

## [Sin publicar]

### Cambiado
- **Documentación y mensajes en inglés.** El README principal pasa a inglés
  (lengua franca del open source) con selector de idioma hacia `README.es.md`,
  que se mantiene en castellano. También se traducen la descripción de Packagist,
  los mensajes de excepción, la salida del CLI y el panel del Web Profiler.
  Un artículo en inglés que lleva a un repositorio en español pierde al lector
  en el último paso.

### Añadido
- **`LlmVcrBundle` para Symfony 7.4**: configuración declarativa, servicios
  autoconfigurados y `PlatformFactory` inyectable.
- **Panel en el Web Profiler** con modo, hit rate, tokens ahorrados y latencia
  evitada. El badge se pone en rojo si se toca la red estando en modo replay.
- Comando `llm-vcr:drift` para Symfony Console, con salida Markdown.
- `RecordingPlatform::useCassette()` para fijar el nombre de la cassette.

### Corregido
- **El panel del Profiler no se registraba nunca.** El valor por defecto de
  `profiler` llega a `loadExtension()` como el string `"%kernel.debug%"` sin
  resolver, así que compararlo con `true` daba siempre falso. Detectado al
  instalar el bundle en un proyecto Symfony 8.1 real; hay test de regresión.

### Mejorado
- La barra de depuración lleva ahora un **icono SVG**, igual que el resto de
  collectors de Symfony. Sin él, nuestro bloque desentonaba en la toolbar.

### Mejorado
- El panel explica por qué "Latencia evitada" puede salir 0 ms: el tiempo se
  lee de la cassette, así que una grabada sin latencia no tiene nada que
  mostrar. Sin la nota parecía un fallo del panel.

### Rendimiento
- Los tests del bundle usan `ContainerBuilder` en lugar de arrancar un kernel
  completo en cada caso. Arrancar quince kernels compilaba y escribía el
  contenedor a disco quince veces: en Windows eso convertía la suite en 25
  segundos. Queda un único test con kernel real para cubrir la integración.
  Resultado: 112 tests en 0,18 s.

### Notas técnicas
- Symfony se declara en `suggest`, nunca en `require`: quien use solo PHPUnit
  no arrastra el framework. Hay un job de CI que lo verifica desinstalándolo.
- Los nodos de configuración con claves arbitrarias (`custom_rules`,
  `placeholders`) usan `normalizeKeys(false)`. Con `useAttributeAsKey()`
  Symfony destruía las claves que son expresiones regulares.

### Pendiente
- `EmbeddingMatcher` con caché en disco
- Soporte para respuestas en streaming y tool calls

## [0.1.0] - 2026-07-28

Primera versión pública.

### Añadido

**Núcleo**
- `RecordingPlatform`: decorador que graba y reproduce interacciones LLM
  sin que el código de negocio se entere.
- Cuatro modos de operación (`record`, `replay`, `bypass`, `refresh`) vía
  el enum `Mode`, configurables con la variable `LLM_VCR_MODE`.
- Cassettes en JSON legible y versionable, con escritura atómica
  (fichero temporal + `rename`) para soportar tests en paralelo.

**Coincidencia de prompts**
- `SemanticMatcher`: similitud de coseno sobre bag-of-words con damping
  logarítmico, tolerante a timestamps, UUIDs e IDs cambiantes.
- `PlaceholderMatcher`: sustitución de parámetros dinámicos declarados por
  el usuario. Determinista y sin falsos positivos.
- `ExactMatcher`: comparación estricta.

**Seguridad**
- `Redactor` activado por defecto: claves de OpenAI/Groq/GitHub/AWS, JWT,
  Bearer tokens, emails, teléfonos españoles, DNI, NIE, IBAN y tarjetas.
- El matching opera sobre texto ya redactado, para que las peticiones con
  PII también encuentren su cassette.

**Detección de deriva**
- `DriftDetector`: compara las respuestas grabadas con las actuales del
  proveedor, incluyendo diferencias de **tipo** en el JSON.
- Severidades `CRITICA`, `ALTA`, `MEDIA` y `OK`, con código de salida
  distinto de cero para romper el build.

**Experiencia de desarrollo**
- Trait `InteractsWithLlm` para PHPUnit, con seis aserciones propias.
- Seis expectativas nativas de Pest, registradas automáticamente.
- Nombrado automático de cassettes a partir del test en curso.

**Herramientas**
- CLI `bin/llm-vcr` con los comandos `drift` y `stats`.
- Workflows de GitHub Actions: CI en modo replay y cron nocturno de deriva.
- Entorno Docker y `Makefile`.

### Notas técnicas

Dos errores encontrados durante el desarrollo, ambos con test de regresión:

- **Redacción y matching desalineados.** La cassette guarda
  `<REDACTED:EMAIL>`, así que comparar contra el prompt en crudo hacía
  fallar justo las peticiones con datos personales. Ambos lados de la
  comparación deben estar en el mismo espacio.
- **Registro de las expectativas de Pest.** Composer carga los ficheros
  `files` en orden de dependencias; como el paquete no depende de Pest, se
  ejecutaba antes de que existieran sus funciones globales y el registro
  fallaba en silencio. La guarda correcta es `class_exists()`.

[Sin publicar]: https://github.com/MikiBuilder/llm-vcr/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/MikiBuilder/llm-vcr/releases/tag/v0.1.0
