# llm-vcr

**[English](README.md)** · **Español**

**Record & replay semántico para features de IA en PHP.**

Graba las respuestas reales de tu LLM, reprodúcelas en CI sin red ni API key, y entérate
cuando el proveedor cambie el modelo por debajo y rompa tus DTOs.

[![CI](https://github.com/MikiBuilder/llm-vcr/actions/workflows/ci.yml/badge.svg)](https://github.com/MikiBuilder/llm-vcr/actions/workflows/ci.yml)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%209-brightgreen)](https://phpstan.org/)
[![License](https://img.shields.io/badge/license-MIT-blue)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%3E%3D8.2-777bb4)](https://www.php.net/)

---

## El problema

Tienes un servicio que clasifica tickets con un LLM. Quieres testearlo. Y entonces:

```php
$result = $this->analyzer->analyze('No puedo acceder a mi cuenta');

$this->assertSame('acceso', $result->categoria); // 🎲 a veces pasa, a veces no
```

1. **No es determinista.** El mismo prompt devuelve texto distinto cada vez.
2. **Cuesta dinero.** 200 tests × cada push × cada desarrollador.
3. **Es lento.** Entre 0,5 y 3 segundos por llamada. Tu suite pasa de segundos a minutos.
4. **Necesita red y una API key de producción en CI.** Si el proveedor tiene un incidente, tu build
   se pone rojo sin que tú hayas roto nada.
5. **Y lo peor: la deriva silenciosa.** El proveedor actualiza el modelo, `urgencia` empieza a llegar
   como `"alta"` en vez de `4`, tu DTO tipado revienta en producción — **y tú no has tocado una sola
   línea de código.** Ningún test lo detecta, porque tus mocks tienen congelado el valor viejo.

## La solución

```php
$platform = new RecordingPlatform(
    inner: new GroqPlatform($apiKey),   // tu proveedor real
    cassetteDir: __DIR__ . '/cassettes',
    mode: Mode::fromEnv(),              // record en local, replay en CI
);
```

Ya está. Tu código no cambia: `RecordingPlatform` implementa la misma interfaz.

- **En local** graba las respuestas reales en ficheros JSON versionables.
- **En CI** las reproduce desde disco: cero red, cero API key, cero coste.
- **Cada noche** las reproduce contra el proveedor real y te avisa si algo cambió.

## Qué lo hace distinto

|  | `php-vcr` | Mocks a mano | **llm-vcr** |
|---|---|---|---|
| Determinismo en CI | ✅ | ✅ | ✅ |
| Tolera prompts que cambian | ❌ hash exacto | — | ✅ **similitud semántica** |
| La respuesta es la real del modelo | ✅ | ❌ te la inventas | ✅ |
| Redacta secretos y PII | ❌ | — | ✅ **por defecto** |
| Detecta deriva del proveedor | ❌ | ❌ imposible | ✅ |
| Entiende de modelos y tokens | ❌ | — | ✅ |

> **La clave:** `php-vcr` casa peticiones por hash exacto. Un prompt real lleva timestamps, UUIDs e
> IDs que cambian en cada ejecución, así que la cassette se invalida en cuanto tocas una coma.
> `llm-vcr` normaliza ese ruido y compara por **similitud de coseno**.

---

## Instalación

```bash
composer require --dev mikibuilder/llm-vcr
```

Requiere PHP 8.2+ con `ext-json` y `ext-mbstring`. Sin dependencias de runtime.

---

## Empezar en 2 minutos (sin registrarte en nada)

```bash
git clone https://github.com/MikiBuilder/llm-vcr.git
cd llm-vcr
composer install
php examples/demo.php
```

La demo enseña los seis comportamientos con una plataforma simulada. Sin API key, sin red.

### Con un LLM real y gratuito

[Groq](https://console.groq.com/keys) da una clave gratis **sin tarjeta de crédito**
(30 req/min, ~1.000 al día en el free tier).

```bash
cp .env.example .env      # pega tu GROQ_API_KEY
php examples/groq_record.php   # primera vez: llama a la API
php examples/groq_record.php   # segunda: desde la cassette, sin red
```

### Con Docker

```bash
make build && make up && make install
make demo     # demo sin API key
make test     # 45 tests
make check    # PHPStan nivel 9 + tests
```

---

## Integración con Symfony

El bundle añade configuración declarativa y un **panel en el Web Profiler** con
las métricas de cada petición.

Symfony es una dependencia **opcional**: si solo usas PHPUnit o Pest, no
arrastras nada.

```php
// config/bundles.php
return [
    // ...
    MikiBuilder\LlmVcr\Bridge\Symfony\LlmVcrBundle::class => ['all' => true],
];
```

```yaml
# config/packages/llm_vcr.yaml
llm_vcr:
    cassette_dir: '%kernel.project_dir%/tests/cassettes'
    mode: record

    matcher:
        strategy: semantic     # semantic | placeholder | exact
        threshold: 0.82

    redaction:
        pii: true              # las credenciales se redactan SIEMPRE
```

```yaml
# config/packages/test/llm_vcr.yaml
# En tests nunca se toca la red: si falta una cassette, el test falla.
llm_vcr:
    mode: replay
```

Y en tu servicio:

```php
use MikiBuilder\LlmVcr\Bridge\Symfony\PlatformFactory;

final class TicketAnalyzer
{
    public function __construct(
        private PlatformFactory $vcr,
        private MiClienteLlm $cliente,
    ) {}

    public function analyze(string $texto): TicketDto
    {
        $platform = $this->vcr->wrap($this->cliente, cassette: 'tickets');

        $result = $platform->invoke('llama-3.1-8b-instant', [
            ['role' => 'system', 'content' => 'Clasifica tickets. Responde JSON.'],
            ['role' => 'user',   'content' => $texto],
        ]);

        return TicketDto::fromArray($result->asStructured() ?? []);
    }
}
```

### El panel del Profiler

En la barra de depuración verás de un vistazo cuántas invocaciones vinieron de
disco y cuántas tocaron la API. El panel desglosa:

| Métrica | Qué te dice |
|---|---|
| **Modo** | `record`, `replay`, `bypass` o `refresh` |
| **Desde cassette** / **Llamadas reales** | Si esta petición gastó cuota |
| **Hit rate** | Porcentaje servido desde disco |
| **Tokens no gastados** | Ahorro acumulado |
| **Latencia evitada** | Milisegundos que no esperaste |

El badge se pone **rojo** si se hicieron llamadas reales estando en modo
`replay`: normalmente significa que falta grabar una cassette.

### Comando de consola

```bash
bin/console llm-vcr:drift              # ¿ha cambiado el modelo del proveedor?
bin/console llm-vcr:drift --markdown   # tabla para pegar en una PR
```

Devuelve código de salida 1 si detecta deriva ALTA o CRÍTICA, así que puedes
encadenarlo en un cron nocturno y romper el build.

Necesita que tu cliente LLM esté registrado con el alias
`llm_vcr.live_platform`:

```yaml
services:
    llm_vcr.live_platform:
        alias: App\Llm\MiClienteLlm
```

---

## Integración con PHPUnit y Pest

El objetivo es que montar un test con LLM sea **una línea**, y que las aserciones hablen el idioma
del problema en vez de obligarte a escribir plomería.

### PHPUnit — el trait `InteractsWithLlm`

```php
use MikiBuilder\LlmVcr\Testing\InteractsWithLlm;

final class TicketTest extends TestCase
{
    use InteractsWithLlm;

    public function testClasificaUnProblemaDeAcceso(): void
    {
        $platform = $this->recordLlm(GroqPlatform::fromEnv());

        $result = $platform->invoke('llama-3.1-8b-instant', [
            ['role' => 'system', 'content' => 'Clasifica tickets. Responde JSON.'],
            ['role' => 'user',   'content' => 'No puedo acceder a mi cuenta.'],
        ]);

        $this->assertNoLiveLlmCalls();
        $this->assertLlmJsonShape([
            'categoria' => 'string',
            'urgencia'  => 'int',
        ], $result);
    }
}
```

Sin rutas que configurar: las cassettes van a `<directorio-del-test>/cassettes/` y el nombre sale
de la clase y el método (`ticket--clasifica-un-problema-de-acceso.json`).

| Aserción | Qué comprueba |
|---|---|
| `assertNoLiveLlmCalls()` | El test **no ha tocado la red**. Ponla en tu suite y CI te avisará el día que alguien queme cuota sin querer |
| `assertLlmJsonShape([...], $r)` | La **forma** del JSON: claves y tipos. Admite `'float\|null'` y rutas `'meta.score'` |
| `assertLlmValueIn([...], 'campo', $r)` | El valor está en un dominio cerrado (enums del modelo) |
| `assertLlmJson($r)` | Es JSON válido, y te lo devuelve como array |
| `assertResultCameFromCassette($r)` | La respuesta vino de disco, no de la API |
| `assertLlmCallsWereReplayed(n)` | Se reprodujeron exactamente `n` interacciones |

### Pest — expectativas nativas

```php
use function MikiBuilder\LlmVcr\Testing\recordLlm;

it('clasifica un problema de acceso', function () {
    $platform = recordLlm(GroqPlatform::fromEnv(), cassette: 'tickets');

    $result = $platform->invoke('llama-3.1-8b-instant', [...]);

    expect($platform)->toHaveMadeNoLiveCalls()->toHaveReplayed(1);
    expect($result)->toBeLlmJson()
                   ->toMatchLlmShape(['categoria' => 'string', 'urgencia' => 'int'])
                   ->toHaveLlmValueIn(['acceso', 'facturacion'], 'categoria');
});
```

Se registran solas al instalar el paquete: no hay que tocar `Pest.php`.
Disponibles: `toBeLlmJson()`, `toMatchLlmShape()`, `toHaveLlmValueIn()`, `toComeFromCassette()`,
`toHaveMadeNoLiveCalls()`, `toHaveReplayed()`.

> **Por qué validar la forma y no el valor:** el valor que devuelve un LLM no es determinista, pero
> el **contrato** sí debe serlo. `toMatchLlmShape()` falla cuando `urgencia` pasa de `int` a `string`
> — que es exactamente el bug que rompe tu DTO en producción.

### Prompts con parámetros dinámicos

Tres estrategias, de más estricta a más tolerante:

```php
// 1. Exacta — el prompt no varía nunca
new ExactMatcher();

// 2. Placeholders — TÚ declaras qué es variable. Cero falsos positivos.
new PlaceholderMatcher([
    'order_id' => '/PED-\d+/',
    'importe'  => '/\d+,\d{2} ?€/',
]);
// "Revisa el pedido PED-4417 por 89,90 €"
// "Revisa el pedido PED-9902 por 12,50 €"  → misma cassette

// 3. Semántica — tolera cambios de redacción (por defecto)
new SemanticMatcher(threshold: 0.82);
```

`PlaceholderMatcher` es el punto medio que suele querer la gente: sigue siendo una comparación
**exacta y determinista**, revisable en una PR, pero inmune a los datos que tú marques como
variables. Si cambia algo que *no* declaraste, no casa — y eso es lo correcto.
Ya trae fechas, horas y UUIDs cubiertos por defecto.

---

## Uso

### Los cuatro modos

| Modo | Cuándo | Comportamiento |
|---|---|---|
| `Mode::Record` | Desarrollo local | Graba si no existe; si existe, reproduce |
| `Mode::Replay` | **CI** | Solo reproduce. Si falta, **falla con un mensaje que explica cómo arreglarlo** |
| `Mode::Bypass` | Depuración | Ignora cassettes, siempre API real |
| `Mode::Refresh` | Actualizar fixtures | Regraba todo desde cero |

```php
$mode = Mode::fromEnv();                    // lee LLM_VCR_MODE
$mode = Mode::fromEnv(default: Mode::Replay);
```

### En PHPUnit

```php
final class TicketAnalyzerTest extends TestCase
{
    private RecordingPlatform $platform;

    protected function setUp(): void
    {
        $this->platform = new RecordingPlatform(
            inner: GroqPlatform::fromEnv(),
            cassetteDir: __DIR__ . '/cassettes',
            mode: Mode::fromEnv(default: Mode::Replay),
        );
    }

    public function testClasificaUnProblemaDeAcceso(): void
    {
        $analyzer = new TicketAnalyzer($this->platform);

        $result = $analyzer->analyze('No puedo acceder a mi cuenta desde ayer');

        self::assertSame('acceso', $result->categoria);
        self::assertGreaterThanOrEqual(3, $result->urgencia);
    }
}
```

Ejecuta una vez con `LLM_VCR_MODE=record`, commitea las cassettes, y a partir de ahí CI corre gratis.

### Detección de deriva

```bash
php bin/llm-vcr drift               # informe en consola
php bin/llm-vcr drift --markdown    # tabla para pegar en una PR
php bin/llm-vcr stats               # resumen de las cassettes
```

Sale con **código 1** si detecta deriva ALTA o CRÍTICA, así que rompe el build.
El workflow `.github/workflows/drift.yml` lo ejecuta cada noche y abre un issue automáticamente.

Ejemplo de salida real:

```
🔴  CRITICA   sim 0.79  cambio de tipo en "urgencia": int -> string | campo nuevo: "confianza" (float)
🟢  OK        sim 1.00  sin cambios de esquema
🟡  MEDIA     sim 0.60  sin cambios de esquema
```

Ese `int -> string` es el bug que te habría costado una guardia a las 3 de la mañana.

---

## Configuración

### Matchers

```php
new SemanticMatcher(threshold: 0.82);  // por defecto
new SemanticMatcher(threshold: 0.95);  // más estricto
new ExactMatcher();                    // hash exacto, tolerancia cero

// Normalizar ruido propio de tu dominio
new SemanticMatcher(extraNoise: ['/\bPED-\d+\b/' => '<pedido>']);
```

### Redacción

Activada **por defecto**, porque las cassettes se commitean a git.

Detecta: claves de OpenAI/Groq/GitHub/AWS, JWT, Bearer tokens, emails, teléfonos españoles,
DNI, NIE, IBAN y números de tarjeta.

```php
new Redactor();                    // credenciales + PII
Redactor::credentialsOnly();       // solo credenciales
new Redactor(customRules: ['/\bEXP-\d{4}\b/' => '<REDACTED:EXPEDIENTE>']);
```

### Otros proveedores

`GroqPlatform` habla el dialecto OpenAI, así que sirve para cualquier endpoint compatible:

```php
new GroqPlatform($key, baseUrl: 'https://openrouter.ai/api/v1');
new GroqPlatform('ollama', baseUrl: 'http://localhost:11434/v1');
```

Para cualquier otro, implementa `PlatformInterface` — son tres líneas.

---

## Cómo funciona

```
┌─────────────────────────────────────────────────────────┐
│  Tu código  →  TicketAnalyzer                           │
│                      │                                  │
│                      ▼                                  │
│          ┌───────────────────────┐                      │
│          │  RecordingPlatform    │  ← decorador         │
│          │  (PlatformInterface)  │                      │
│          └───────────┬───────────┘                      │
│                      │                                  │
│      ┌───────────────┼───────────────┐                  │
│      ▼               ▼               ▼                  │
│  SemanticMatcher  Redactor      Cassette (.json)        │
│  coseno +         claves, PII   versionable en git      │
│  normalización                                          │
│      │                                                  │
│      ▼  (miss)                                          │
│  GroqPlatform → API real                                │
└─────────────────────────────────────────────────────────┘
```

Es un **decorador**, no un fork. Sustitución de Liskov pura: envuelve cualquier implementación de
`PlatformInterface` sin que el código de negocio se entere.

### Una cassette por dentro

```json
{
  "cassette": "clasifica-tickets-de-soporte-9a89bea9",
  "version": 1,
  "interactions": [
    {
      "fingerprint": "cc915e9e1164167c",
      "request": {
        "model": "llama-3.1-8b-instant",
        "messages": [
          { "role": "system", "content": "Clasifica tickets de soporte en JSON." },
          { "role": "user", "content": "Soy <REDACTED:EMAIL>, tel <REDACTED:PHONE>, no puedo acceder." }
        ]
      },
      "response": {
        "text": "{\"categoria\":\"acceso\",\"urgencia\":4}",
        "input_tokens": 27,
        "output_tokens": 15
      }
    }
  ]
}
```

Legible, diffable en una PR, y sin un solo secreto.

---

## Preguntas frecuentes

**¿Debo commitear las cassettes?**
Sí. Son el fixture del proyecto: sin ellas, CI no puede correr en modo replay.
Por eso la redacción va activada por defecto.

**¿Y si cambio el prompt?**
Si el cambio es menor, el matcher semántico lo absorbe. Si es grande, el test falla con un mensaje
que te dice exactamente qué hacer. Regrabas con `LLM_VCR_MODE=record` y commiteas.

**¿Reemplaza a los evals?**
No, son complementarios. Los evals miden **calidad** (¿la respuesta es buena?).
`llm-vcr` resuelve **determinismo, coste y deriva**. Puedes usar los dos.

**¿Placeholders o similitud semántica?**
Empieza por `PlaceholderMatcher` si sabes exactamente qué partes del prompt varían (IDs, importes,
fechas de negocio): es determinista y no da falsos positivos. Usa `SemanticMatcher` cuando el prompt
se redacta de formas distintas o lo genera otro sistema.

**¿Funciona con Symfony AI?**
Sí. `RecordingPlatform` es un decorador sobre una interfaz mínima, así que basta con un adaptador
de tres líneas. Un bundle nativo está en la hoja de ruta.

**¿Por qué no usar embeddings para el matching?**
Porque requeriría una llamada de red justo en el camino que intenta evitarla. El coseno sobre
bag-of-words normalizado funciona sorprendentemente bien y es instantáneo.
Un `EmbeddingMatcher` opcional está previsto.

---

## Hoja de ruta

- [x] Núcleo: record/replay, matching semántico, redacción, deriva
- [x] CLI `llm-vcr drift` con salida Markdown para PRs
- [x] GitHub Actions: CI en replay + cron nocturno de deriva
- [x] Trait `InteractsWithLlm` para PHPUnit con 6 aserciones
- [x] Expectativas nativas de Pest (verificadas contra Pest 3)
- [x] `PlaceholderMatcher` para prompts con parámetros dinámicos
- [x] `LlmVcrBundle` para Symfony, con panel en el Web Profiler
- [ ] `EmbeddingMatcher` con caché en disco
- [ ] Soporte para respuestas en streaming y tool calls

## Contribuir

Los PRs son bienvenidos. El listón: **PHPStan nivel 9 y tests en verde**.

```bash
composer check     # análisis estático + tests
```

## Licencia

MIT — [MikiBuilder](https://github.com/MikiBuilder)
