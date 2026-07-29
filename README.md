# llm-vcr

**English** · **[Español](README.es.md)**

**Semantic record & replay for AI features in PHP.**

Record what your LLM actually returns, replay it in CI with no network and no API key,
and find out when your provider silently changes the model and breaks your DTOs.

[![CI](https://github.com/MikiBuilder/llm-vcr/actions/workflows/ci.yml/badge.svg)](https://github.com/MikiBuilder/llm-vcr/actions/workflows/ci.yml)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%209-brightgreen)](https://phpstan.org/)
[![License](https://img.shields.io/badge/license-MIT-blue)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%3E%3D8.2-777bb4)](https://www.php.net/)

---

## The problem

You have a service that classifies support tickets with an LLM. You want to test it.
And then:

```php
$result = $this->analyzer->analyze('I cannot access my account');

$this->assertSame('access', $result->category); // 🎲 passes sometimes
```

1. **It is not deterministic.** The same prompt returns different text every time.
2. **It costs money.** 200 tests × every push × every developer.
3. **It is slow.** Between 0.5 and 3 seconds per call. Your suite goes from seconds to minutes.
4. **It needs network access and a production API key in CI.** If your provider has an
   incident, your build turns red without you breaking anything.
5. **And the worst one: silent drift.** The provider updates the model, `urgency` starts
   coming back as `"high"` instead of `4`, your typed DTO blows up in production — **and
   you never touched a single line of code.** No test catches it, because your mocks have
   the old value frozen in.

## The solution

```php
$platform = new RecordingPlatform(
    inner: new GroqPlatform($apiKey),   // your real provider
    cassetteDir: __DIR__ . '/cassettes',
    mode: Mode::fromEnv(),              // record locally, replay in CI
);
```

That is it. Your code does not change: `RecordingPlatform` implements the same interface.

- **Locally** it records real responses into versionable JSON files.
- **In CI** it replays them from disk: no network, no API key, no cost.
- **Every night** it replays them against the real provider and warns you if anything changed.

## What makes it different

|  | `php-vcr` | Hand-written mocks | **llm-vcr** |
|---|---|---|---|
| Deterministic in CI | ✅ | ✅ | ✅ |
| Tolerates changing prompts | ❌ exact hash | — | ✅ **semantic similarity** |
| The response is the model's real one | ✅ | ❌ you make it up | ✅ |
| Redacts secrets and PII | ❌ | — | ✅ **by default** |
| Detects provider drift | ❌ | ❌ impossible | ✅ |
| Understands models and tokens | ❌ | — | ✅ |

> **The key insight:** `php-vcr` matches requests by exact hash. A real prompt carries
> timestamps, UUIDs and IDs that change on every run, so the cassette is invalidated the
> moment you touch a comma. `llm-vcr` normalises that noise and compares using
> **cosine similarity**.

---

## Installation

```bash
composer require --dev mikibuilder/llm-vcr
```

Requires PHP 8.2+ with `ext-json` and `ext-mbstring`. No runtime dependencies.

---

## Get started in 2 minutes (no sign-up required)

```bash
git clone https://github.com/MikiBuilder/llm-vcr.git
cd llm-vcr
composer install
php examples/demo.php
```

The demo shows all six behaviours using a simulated platform. No API key, no network.

### With a real, free LLM

[Groq](https://console.groq.com/keys) gives you a free API key **with no credit card**
(30 req/min, ~1,000 per day on the free tier).

```bash
cp .env.example .env      # paste your GROQ_API_KEY
php examples/groq_record.php   # first run: hits the API
php examples/groq_record.php   # second run: from the cassette, no network
```

### With Docker

```bash
make build && make up && make install
make demo     # demo without an API key
make test     # 116 tests
make check    # PHPStan level 9 + tests
```

---

## Symfony integration

The bundle adds declarative configuration and a **Web Profiler panel** with per-request
metrics.

Symfony is an **optional** dependency: if you only use PHPUnit or Pest, you pull in nothing.

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
        pii: true              # credentials are ALWAYS redacted
```

```yaml
# config/packages/test/llm_vcr.yaml
# Tests never touch the network: if a cassette is missing, the test fails.
llm_vcr:
    mode: replay
```

And in your service:

```php
use MikiBuilder\LlmVcr\Bridge\Symfony\PlatformFactory;

final class TicketAnalyzer
{
    public function __construct(
        private PlatformFactory $vcr,
        private MyLlmClient $client,
    ) {}

    public function analyze(string $text): TicketDto
    {
        $platform = $this->vcr->wrap($this->client, cassette: 'tickets');

        $result = $platform->invoke('llama-3.1-8b-instant', [
            ['role' => 'system', 'content' => 'Classify tickets. Respond with JSON.'],
            ['role' => 'user',   'content' => $text],
        ]);

        return TicketDto::fromArray($result->asStructured() ?? []);
    }
}
```

### The Profiler panel

The debug toolbar shows at a glance how many invocations came from disk and how many hit
the API. The panel breaks it down:

| Metric | What it tells you |
|---|---|
| **Mode** | `record`, `replay`, `bypass` or `refresh` |
| **From cassette** / **Live calls** | Whether this request burned quota |
| **Hit rate** | Percentage served from disk |
| **Tokens saved** | Cumulative savings |
| **Latency avoided** | Milliseconds you did not wait for |

The badge turns **red** if live calls were made while in `replay` mode: usually it means
a cassette is missing.

### Console command

```bash
bin/console llm-vcr:drift              # has the provider's model changed?
bin/console llm-vcr:drift --markdown   # table to paste into a PR
```

It exits with code 1 if it detects HIGH or CRITICAL drift, so you can chain it into a
nightly cron and break the build.

It needs your LLM client registered under the `llm_vcr.live_platform` alias:

```yaml
services:
    llm_vcr.live_platform:
        alias: App\Llm\MyLlmClient
```

---

## PHPUnit and Pest integration

The goal is that setting up an LLM test takes **one line**, and that assertions speak the
language of the problem instead of forcing you to write plumbing.

### PHPUnit — the `InteractsWithLlm` trait

```php
use MikiBuilder\LlmVcr\Testing\InteractsWithLlm;

final class TicketTest extends TestCase
{
    use InteractsWithLlm;

    public function testClassifiesAnAccessProblem(): void
    {
        $platform = $this->recordLlm(GroqPlatform::fromEnv());

        $result = $platform->invoke('llama-3.1-8b-instant', [
            ['role' => 'system', 'content' => 'Classify tickets. Respond with JSON.'],
            ['role' => 'user',   'content' => 'I cannot access my account.'],
        ]);

        $this->assertNoLiveLlmCalls();
        $this->assertLlmJsonShape([
            'category' => 'string',
            'urgency'  => 'int',
        ], $result);
    }
}
```

No paths to configure: cassettes go to `<test-directory>/cassettes/` and the name is
derived from the class and method (`ticket--classifies-an-access-problem.json`).

| Assertion | What it checks |
|---|---|
| `assertNoLiveLlmCalls()` | The test **did not touch the network**. Put it in your suite and CI will warn you the day someone burns quota by accident |
| `assertLlmJsonShape([...], $r)` | The JSON **shape**: keys and types. Supports `'float\|null'` and paths like `'meta.score'` |
| `assertLlmValueIn([...], 'field', $r)` | The value belongs to a closed set (model enums) |
| `assertLlmJson($r)` | It is valid JSON, returned to you as an array |
| `assertResultCameFromCassette($r)` | The response came from disk, not the API |
| `assertLlmCallsWereReplayed(n)` | Exactly `n` interactions were replayed |

### Pest — native expectations

```php
use function MikiBuilder\LlmVcr\Testing\recordLlm;

it('classifies an access problem', function () {
    $platform = recordLlm(GroqPlatform::fromEnv(), cassette: 'tickets');

    $result = $platform->invoke('llama-3.1-8b-instant', [...]);

    expect($platform)->toHaveMadeNoLiveCalls()->toHaveReplayed(1);
    expect($result)->toBeLlmJson()
                   ->toMatchLlmShape(['category' => 'string', 'urgency' => 'int'])
                   ->toHaveLlmValueIn(['access', 'billing'], 'category');
});
```

They register themselves when you install the package: no need to touch `Pest.php`.
Available: `toBeLlmJson()`, `toMatchLlmShape()`, `toHaveLlmValueIn()`,
`toComeFromCassette()`, `toHaveMadeNoLiveCalls()`, `toHaveReplayed()`.

> **Why assert on shape instead of value:** the value an LLM returns is not deterministic,
> but the **contract** must be. `toMatchLlmShape()` fails when `urgency` goes from `int` to
> `string` — which is exactly the bug that breaks your DTO in production.

### Prompts with dynamic parameters

Three strategies, from strictest to most tolerant:

```php
// 1. Exact — the prompt never varies
new ExactMatcher();

// 2. Placeholders — YOU declare what varies. Zero false positives.
new PlaceholderMatcher([
    'order_id' => '/ORD-\d+/',
    'amount'   => '/\d+\.\d{2} ?€/',
]);
// "Review order ORD-4417 for 89.90 €"
// "Review order ORD-9902 for 12.50 €"  → same cassette

// 3. Semantic — tolerates rewording (default)
new SemanticMatcher(threshold: 0.82);
```

`PlaceholderMatcher` is the middle ground most people actually want: still an **exact,
deterministic** comparison, reviewable in a PR, but immune to the data you mark as
variable. If something you did *not* declare changes, it does not match — and that is the
correct behaviour. Dates, times and UUIDs are covered out of the box.

---

## Usage

### The four modes

| Mode | When | Behaviour |
|---|---|---|
| `Mode::Record` | Local development | Records if missing; replays if present |
| `Mode::Replay` | **CI** | Replay only. If missing, **fails with a message explaining how to fix it** |
| `Mode::Bypass` | Debugging | Ignores cassettes, always hits the real API |
| `Mode::Refresh` | Updating fixtures | Re-records everything from scratch |

```php
$mode = Mode::fromEnv();                    // reads LLM_VCR_MODE
$mode = Mode::fromEnv(default: Mode::Replay);
```

Run once with `LLM_VCR_MODE=record`, commit the cassettes, and from then on CI runs for free.

### Drift detection

```bash
php bin/llm-vcr drift               # console report
php bin/llm-vcr drift --markdown    # table to paste into a PR
php bin/llm-vcr stats               # summary of recorded cassettes
```

Real output:

```
🔴  CRITICAL  sim 0.79  type change in "urgency": int -> string | new field: "confidence" (float)
🟢  OK        sim 1.00  no schema changes
🟡  MEDIUM    sim 0.60  no schema changes
```

That `int -> string` is the bug that would have cost you a 3 a.m. page.

The `.github/workflows/drift.yml` workflow runs it nightly and **opens an issue
automatically**.

---

## Configuration

### Redaction

Enabled **by default**, because cassettes get committed to git.

Detects: OpenAI/Groq/GitHub/AWS keys, JWTs, Bearer tokens, emails, phone numbers,
national ID numbers, IBANs and card numbers.

```php
new Redactor();                    // credentials + PII
Redactor::credentialsOnly();       // credentials only
new Redactor(customRules: ['/\bCASE-\d{4}\b/' => '<REDACTED:CASE>']);
```

### Other providers

`GroqPlatform` speaks the OpenAI dialect, so it works with any compatible endpoint:

```php
new GroqPlatform($key, baseUrl: 'https://openrouter.ai/api/v1');
new GroqPlatform('ollama', baseUrl: 'http://localhost:11434/v1');
```

For anything else, implement `PlatformInterface` — it is three lines.

---

## How it works

```
┌─────────────────────────────────────────────────────────┐
│  Your code  →  TicketAnalyzer                           │
│                      │                                  │
│                      ▼                                  │
│          ┌───────────────────────┐                      │
│          │  RecordingPlatform    │  ← decorator         │
│          │  (PlatformInterface)  │                      │
│          └───────────┬───────────┘                      │
│                      │                                  │
│      ┌───────────────┼───────────────┐                  │
│      ▼               ▼               ▼                  │
│  SemanticMatcher  Redactor      Cassette (.json)        │
│  cosine +         keys, PII     versioned in git        │
│  normalisation                                          │
│      │                                                  │
│      ▼  (miss)                                          │
│  GroqPlatform → real API                                │
└─────────────────────────────────────────────────────────┘
```

It is a **decorator**, not a fork. Pure Liskov substitution: it wraps any implementation
of `PlatformInterface` without your business code noticing.

### A cassette from the inside

```json
{
  "cassette": "classify-support-tickets-9a89bea9",
  "version": 1,
  "interactions": [
    {
      "fingerprint": "cc915e9e1164167c",
      "request": {
        "model": "llama-3.1-8b-instant",
        "messages": [
          { "role": "system", "content": "Classify support tickets as JSON." },
          { "role": "user", "content": "I'm <REDACTED:EMAIL>, tel <REDACTED:PHONE>, cannot log in." }
        ]
      },
      "response": {
        "text": "{\"category\":\"access\",\"urgency\":4}",
        "input_tokens": 27,
        "output_tokens": 15
      }
    }
  ]
}
```

Readable, diffable in a PR, and without a single secret.

---

## FAQ

**Should I commit the cassettes?**
Yes. They are the project's fixtures: without them, CI cannot run in replay mode. That is
exactly why redaction is on by default.

**What if I change the prompt?**
If the change is small, the semantic matcher absorbs it. If it is large, the test fails
with a message telling you exactly what to do. Re-record with `LLM_VCR_MODE=record` and
commit.

**Does it replace evals?**
No, they are complementary. Evals measure **quality** (is the answer good?). `llm-vcr`
solves **determinism, cost and drift**. You can use both.

**Placeholders or semantic similarity?**
Start with `PlaceholderMatcher` if you know exactly which parts of the prompt vary (IDs,
amounts, business dates): it is deterministic and produces no false positives. Use
`SemanticMatcher` when the prompt is worded differently each time or generated by another
system.

**Does it work with Symfony AI?**
Yes. `RecordingPlatform` is a decorator over a minimal interface, so a three-line adapter
is enough. A native bundle is included for the rest of the integration.

**Why not use embeddings for matching?**
Because it would require a network call on the very path that is trying to avoid one.
Cosine similarity over normalised bag-of-words works surprisingly well and is instant.
An optional `EmbeddingMatcher` is on the roadmap.

---

## Roadmap

- [x] Core: record/replay, semantic matching, redaction, drift detection
- [x] `llm-vcr drift` CLI with Markdown output for PRs
- [x] GitHub Actions: CI in replay mode + nightly drift cron
- [x] `InteractsWithLlm` trait for PHPUnit with 6 assertions
- [x] Native Pest expectations (verified against Pest 3)
- [x] `PlaceholderMatcher` for prompts with dynamic parameters
- [x] `LlmVcrBundle` for Symfony, with Web Profiler panel
- [ ] `EmbeddingMatcher` with on-disk cache
- [ ] Support for streaming responses and tool calls

## Contributing

PRs are welcome. The bar: **PHPStan level 9 and green tests**.

```bash
composer check     # static analysis + tests
```

## License

MIT — [MikiBuilder](https://github.com/MikiBuilder)
