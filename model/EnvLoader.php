<?php

declare(strict_types=1);

final class EnvLoader
{
    public static function load(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException(sprintf('Le fichier de configuration "%s" est illisible.', $path));
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new RuntimeException(sprintf('Impossible de lire le fichier de configuration "%s".', $path));
        }

        foreach ($lines as $lineNumber => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_starts_with($line, 'export ')) {
                $line = trim(substr($line, 7));
            }

            if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $line, $matches) !== 1) {
                throw new RuntimeException(sprintf('Ligne %d invalide dans le fichier .env.', $lineNumber + 1));
            }

            $name = $matches[1];
            $value = trim($matches[2]);
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'")) {
                $quote = $value[0];
                if ($value[strlen($value) - 1] !== $quote) {
                    throw new RuntimeException(sprintf('Valeur entre guillemets non terminée à la ligne %d du .env.', $lineNumber + 1));
                }

                $value = substr($value, 1, -1);
                if ($quote === '"') {
                    $value = stripcslashes($value);
                }
            } else {
                $value = preg_replace('/\s+#.*$/', '', $value) ?? $value;
            }

            if (array_key_exists($name, $_ENV) || array_key_exists($name, $_SERVER)) {
                continue;
            }

            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }

    public static function get(string $name): ?string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? null;

        return is_string($value) ? $value : null;
    }
}
