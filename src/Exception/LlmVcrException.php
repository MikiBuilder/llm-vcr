<?php

declare(strict_types=1);

namespace MikiBuilder\LlmVcr\Exception;

/**
 * Excepción base del paquete. Permite a quien lo usa capturar
 * cualquier error de llm-vcr con un solo catch.
 */
class LlmVcrException extends \RuntimeException
{
}
