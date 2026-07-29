<?php

declare(strict_types=1);

namespace MikiBuilder\LlmVcr\Cassette;

use MikiBuilder\LlmVcr\Exception\LlmVcrException;

/**
 * Cassette: fichero JSON versionable en git con las interacciones grabadas.
 *
 * Formato pensado para revisión en pull request: legible, diffable y sin
 * secretos (pasa siempre por el Redactor antes de persistir).
 */
final class Cassette
{
    public const FORMAT_VERSION = 1;

    /** @var list<Interaction> */
    private array $interactions = [];

    private bool $dirty = false;

    public function __construct(
        private readonly string $name,
        private readonly string $path,
    ) {
    }

    public static function load(string $name, string $path): self
    {
        $cassette = new self($name, $path);

        if (!is_file($path)) {
            return $cassette;
        }

        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return $cassette;
        }

        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new LlmVcrException(
                sprintf('Corrupted cassette at "%s": %s', $path, $e->getMessage()),
                previous: $e,
            );
        }

        /** @var list<array<string, mixed>> $items */
        $items = is_array($data['interactions'] ?? null) ? $data['interactions'] : [];

        foreach ($items as $item) {
            $cassette->interactions[] = Interaction::fromArray($item);
        }

        return $cassette;
    }

    public function add(Interaction $interaction): void
    {
        $this->interactions[] = $interaction;
        $this->dirty = true;
    }

    /** @return list<Interaction> */
    public function interactions(): array
    {
        return $this->interactions;
    }

    public function count(): int
    {
        return count($this->interactions);
    }

    public function isEmpty(): bool
    {
        return $this->interactions === [];
    }

    /**
     * Persiste la cassette de forma atómica.
     *
     * Escritura en fichero temporal + rename: si dos tests en paralelo graban
     * a la vez, nunca queda un JSON a medias en disco.
     */
    public function save(): void
    {
        if (!$this->dirty) {
            return;
        }

        $dir = dirname($this->path);
        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new LlmVcrException(sprintf('Could not create the cassette directory: %s', $dir));
        }

        $payload = [
            'cassette' => $this->name,
            'version' => self::FORMAT_VERSION,
            'interactions' => array_map(
                static fn (Interaction $i): array => $i->toArray(),
                $this->interactions,
            ),
        ];

        $json = json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        $tmp = $this->path . '.' . bin2hex(random_bytes(4)) . '.tmp';

        if (file_put_contents($tmp, $json . "\n") === false) {
            throw new LlmVcrException(sprintf('Could not write the cassette: %s', $tmp));
        }

        if (!rename($tmp, $this->path)) {
            @unlink($tmp);
            throw new LlmVcrException(sprintf('Could not move the cassette to: %s', $this->path));
        }

        $this->dirty = false;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function name(): string
    {
        return $this->name;
    }
}
