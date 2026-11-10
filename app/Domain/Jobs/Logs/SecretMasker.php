<?php

declare(strict_types=1);

namespace App\Domain\Jobs\Logs;

/** Replaces known secret values with `***`, the way GitHub does in its own logs. */
final class SecretMasker
{
    public const MASK = '***';

    /** Shorter values would mask ordinary words and digits. */
    private const MIN_LENGTH = 4;

    /**
     * @param  list<string>  $secrets
     */
    public function mask(string $text, array $secrets): string
    {
        $values = [];
        foreach ($secrets as $secret) {
            // A multi-line secret is printed line by line, so each line is a secret of its own.
            foreach (preg_split('/\R/', $secret) ?: [] as $line) {
                $line = trim($line);
                if (strlen($line) >= self::MIN_LENGTH) {
                    $values[$line] = true;
                    $values[base64_encode($line)] = true;
                }
            }
        }
        if ($values === [] || $text === '') {
            return $text;
        }

        // Longest first, so a secret containing another is masked whole.
        $values = array_map('strval', array_keys($values));
        usort($values, fn (string $a, string $b) => strlen($b) <=> strlen($a));

        return str_replace($values, self::MASK, $text);
    }
}
