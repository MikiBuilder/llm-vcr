<?php

declare(strict_types=1);

namespace MikiBuilder\LlmVcr\Redaction;

/**
 * Redacta secretos y PII antes de escribir una cassette en disco.
 *
 * PUNTO CRÍTICO DEL DISEÑO: las cassettes se commitean al repositorio.
 * Sin esta capa, llm-vcr sería un generador industrial de filtraciones.
 * Por eso va activada por defecto — secure by default, no opt-in.
 */
final class Redactor
{
    /** @var list<array{pattern: string, replacement: string}> */
    private array $rules;

    /**
     * @param bool                  $redactPii    redactar datos personales además de credenciales
     * @param array<string, string> $customRules  reglas propias (regex => reemplazo)
     */
    public function __construct(
        bool $redactPii = true,
        array $customRules = [],
    ) {
        // 1. Credenciales: SIEMPRE, no son negociables.
        $this->rules = [
            ['pattern' => '/\b(sk|pk|rk)-[A-Za-z0-9_\-]{16,}\b/', 'replacement' => '<REDACTED:API_KEY>'],
            ['pattern' => '/\bgsk_[A-Za-z0-9]{20,}\b/', 'replacement' => '<REDACTED:GROQ_KEY>'],
            ['pattern' => '/\bBearer\s+[A-Za-z0-9._\-]{20,}/i', 'replacement' => 'Bearer <REDACTED:TOKEN>'],
            ['pattern' => '/\beyJ[A-Za-z0-9._\-]{20,}/', 'replacement' => '<REDACTED:JWT>'],
            ['pattern' => '/\bghp_[A-Za-z0-9]{30,}\b/', 'replacement' => '<REDACTED:GITHUB_TOKEN>'],
            ['pattern' => '/\bAKIA[0-9A-Z]{16}\b/', 'replacement' => '<REDACTED:AWS_KEY>'],
        ];

        // 2. PII: activable, porque a veces el contenido ES el objeto del test.
        if ($redactPii) {
            $this->rules[] = ['pattern' => '/\b[\w.+-]+@[\w-]+\.[\w.]{2,}\b/', 'replacement' => '<REDACTED:EMAIL>'];
            $this->rules[] = ['pattern' => '/\b(?:\+34[\s-]?)?[6-9]\d{2}[\s-]?\d{3}[\s-]?\d{3}\b/', 'replacement' => '<REDACTED:PHONE>'];
            $this->rules[] = ['pattern' => '/\b\d{8}[A-HJ-NP-TV-Z]\b/i', 'replacement' => '<REDACTED:DNI>'];
            $this->rules[] = ['pattern' => '/\b[XYZ]\d{7}[A-HJ-NP-TV-Z]\b/i', 'replacement' => '<REDACTED:NIE>'];
            $this->rules[] = ['pattern' => '/\b(?:\d[ -]?){13,19}\b/', 'replacement' => '<REDACTED:PAN>'];
        }

        $this->rules[] = ['pattern' => '/\bES\d{2}[\s]?(?:\d{4}[\s]?){5}\b/i', 'replacement' => '<REDACTED:IBAN>'];

        foreach ($customRules as $pattern => $replacement) {
            $this->rules[] = ['pattern' => $pattern, 'replacement' => $replacement];
        }
    }

    /**
     * Crea un redactor que solo protege credenciales, dejando pasar el PII.
     *
     * Úsalo cuando el contenido personal es justamente lo que estás testeando
     * y trabajas con datos sintéticos.
     */
    public static function credentialsOnly(): self
    {
        return new self(redactPii: false);
    }

    public function redact(string $value): string
    {
        foreach ($this->rules as $rule) {
            $value = (string) preg_replace($rule['pattern'], $rule['replacement'], $value);
        }

        return $value;
    }

    /**
     * @param list<array{role: string, content: string}> $messages
     *
     * @return list<array{role: string, content: string}>
     */
    public function redactMessages(array $messages): array
    {
        return array_map(
            fn (array $m): array => [
                'role' => $m['role'],
                'content' => $this->redact($m['content']),
            ],
            $messages,
        );
    }
}
