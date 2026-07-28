# Probar el bundle en un proyecto Symfony nuevo

Estos pasos están **ejecutados y verificados** en un proyecto Symfony 8.1 real,
instalando `mikibuilder/llm-vcr` desde Packagist como lo haría cualquier usuario.

**Tiempo: unos 10 minutos.**

---

## Antes de empezar

⚠️ **Necesitas la v0.2.1**, no la v0.2.0.

Al hacer esta prueba encontré un bug: **el panel del Profiler no se registraba
nunca**. Está corregido, pero tienes que publicar la nueva versión antes
(ver el último apartado).

---

## PASO 1 · Crea el proyecto

```powershell
cd D:\Proyectos
composer create-project symfony/skeleton demo-llm-vcr
cd demo-llm-vcr
```

**✅ Comprobación:** se crea la carpeta con `bin/`, `config/`, `src/`, `public/`.

---

## PASO 2 · Instala el paquete y el Profiler

```powershell
composer require --dev mikibuilder/llm-vcr
```

```powershell
composer require --dev symfony/web-profiler-bundle symfony/twig-bundle
```

El Profiler no viene en el skeleton, y sin él no hay panel que ver.

**✅ Comprobación:** `composer show mikibuilder/llm-vcr` muestra la versión.

---

## PASO 3 · Registra el bundle

Abre `config/bundles.php` y añade la línea:

```php
return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    // ...
    MikiBuilder\LlmVcr\Bridge\Symfony\LlmVcrBundle::class => ['all' => true],
];
```

Crea `config/packages/llm_vcr.yaml`:

```yaml
llm_vcr:
    cassette_dir: '%kernel.project_dir%/tests/cassettes'
    mode: record
```

**✅ Comprobación:**

```powershell
php bin/console debug:config llm_vcr
```

Debe imprimir la configuración resuelta, con `profiler: true`.

---

## PASO 4 · Comprueba que los servicios están

```powershell
php bin/console debug:container --tag=data_collector
```

**✅ Comprobación:** en la lista aparece `llm_vcr.data_collector`.

> Si **no** aparece, tienes la v0.2.0 con el bug. Ve al último apartado.

---

## PASO 5 · Crea un controlador de prueba

`src/Controller/HomeController.php`:

```php
<?php

namespace App\Controller;

use MikiBuilder\LlmVcr\Bridge\Symfony\PlatformFactory;
use MikiBuilder\LlmVcr\Platform\InMemoryPlatform;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'home')]
    public function index(PlatformFactory $vcr): Response
    {
        $mensajes = [
            ['role' => 'system', 'content' => 'Clasifica tickets de soporte.'],
            ['role' => 'user',   'content' => 'No puedo acceder a mi cuenta'],
        ];

        // Una grabación real + tres reproducciones = 75 % de hit rate.
        //
        // simulatedLatencyMs imita lo que tarda un LLM de verdad (Groq ronda
        // los 200-450 ms). Sin esto, "Tiempo ahorrado" saldría 0 ms y la
        // métrica más vistosa del panel no se vería.
        $llm = new InMemoryPlatform(
            '{"categoria":"acceso","urgencia":4}',
            simulatedLatencyMs: 320.0,
        );

        $vcr->wrap($llm, cassette: 'web')->invoke('llama-3.1-8b-instant', $mensajes);

        for ($i = 0; $i < 3; ++$i) {
            $vcr->wrap($llm, cassette: 'web')->invoke('llama-3.1-8b-instant', $mensajes);
        }

        return new Response('<html><body><h1>Demo llm-vcr</h1>'
            . '<p>Mira la barra de depuración, abajo.</p></body></html>');
    }
}
```

> En un proyecto real, `new InMemoryPlatform(...)` sería tu cliente LLM
> (`GroqPlatform::fromEnv()`, por ejemplo). Aquí se simula para que la demo
> no gaste cuota.

---

> ⚠️ **Si ya habías cargado la página antes**, borra la cassette vieja:
>
> ```powershell
> Remove-Item tests\cassettes\*.json
> ```
>
> La latencia se guarda **dentro** de la cassette al grabarla. Si la grabaste
> con una versión anterior del controlador (sin `simulatedLatencyMs`), seguirá
> mostrando `0 ms` por muchas veces que recargues: al reproducir se lee del
> fichero, no del código.

---

## PASO 6 · Arranca y mira el panel

```powershell
php -S 127.0.0.1:8000 -t public
```

Abre <http://127.0.0.1:8000> en el navegador.

**✅ Comprobación:** en la barra de depuración de abajo aparece el icono de
llm-vcr con `3 cassette / 1 API`. Pincha en él para abrir el panel.

Deberías ver exactamente esto (verificado):

| Métrica | Valor |
|---|---|
| Modo | record |
| Desde cassette | 3 |
| Llamadas reales | 1 |
| **Hit rate** | **75 %** |
| Tokens no gastados | 45 |
| Cassettes en disco | 1 |

**Esa es la captura de pantalla que se comparte en redes.**

---

## PASO 7 · Prueba el aviso en rojo

Cambia el modo a `replay` en `config/packages/llm_vcr.yaml`, recarga la página
y verás el badge en rojo con el aviso de que se hicieron llamadas reales
estando en modo replay. Es el comportamiento correcto: te avisa de que falta
grabar una cassette.

---

## Publicar la v0.2.1 con el bug corregido

Descomprime el ZIP nuevo encima de `D:\Proyectos\llm-vcr` y:

```powershell
cd D:\Proyectos\llm-vcr
composer install
php vendor/bin/phpunit --testsuite=unit
```

Debe dar `OK (114 tests, 273 assertions)`.

```powershell
git add .
git commit -m "fix: el panel del Profiler no se registraba por un parametro sin resolver"
git push
git tag -a v0.2.1 -m "v0.2.1 - corregido el registro del panel del Profiler"
git push origin v0.2.1
```

---

## Qué hacer si algo falla

**El panel no aparece en la barra**
Comprueba que `symfony/web-profiler-bundle` está instalado y que
`php bin/console debug:container --tag=data_collector` lista
`llm_vcr.data_collector`.

**`Cannot autowire PlatformFactory`**
Limpia la caché: `php bin/console cache:clear`.

**La página da error 500**
Mira `var/log/dev.log` y pásame el error.
