<?php

declare(strict_types=1);

namespace MikiBuilder\LlmVcr\Exception;

/**
 * Fallo al hablar con el proveedor real (red, HTTP, respuesta inválida).
 */
final class TransportException extends LlmVcrException
{
}
