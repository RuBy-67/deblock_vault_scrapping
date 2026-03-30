<?php

declare(strict_types=1);

/**
 * Charge la config depuis .env à la racine du projet ou variables d'environnement.
 */
function monitor_load_env(string $rootDir): array
{
    $envPath = $rootDir . DIRECTORY_SEPARATOR . '.env';
    $vars = [];
    if (is_readable($envPath)) {
        foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k);
            $v = trim($v, " \t\"'");
            $vars[$k] = $v;
        }
    }

    $g = static fn (string $k, string $d = '') => getenv($k) !== false ? (string) getenv($k) : ($vars[$k] ?? $d);

    return [
        'db_host' => $g('MYSQL_HOST', '127.0.0.1'),
        'db_port' => (int) $g('MYSQL_PORT', '3306'),
        'db_name' => $g('MYSQL_DATABASE', 'eurcv_monitor'),
        'db_user' => $g('MYSQL_USER', 'root'),
        'db_pass' => $g('MYSQL_PASSWORD', ''),
        'node_address' => strtolower($g('NODE_ADDRESS', '0x4d2fb5f8ec243fde4df1a9678b82238570c7e0e4')),
        'token_contract' => strtolower($g('TOKEN_CONTRACT', '0xbeef007ecfbfdf9b919d0050821a9b6dbd634ff0')),
        /** URL publique du dossier dashboard (https://exemple.com/chemin/dashboard), sans slash final  pour aperçus Discord / Open Graph derrière proxy. */
        'public_base_url' => trim($g('DASHBOARD_PUBLIC_URL', '')),
    ];
}

/**
 * Base URL absolue du dashboard (schéma + hôte + chemin du dossier), pour og:image et og:url.
 */
function monitor_dashboard_public_base(array $cfg): string
{
    $fromEnv = trim((string) ($cfg['public_base_url'] ?? ''));
    if ($fromEnv !== '') {
        return rtrim($fromEnv, '/');
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443')
        || (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    $dir = dirname($script);
    if ($dir === '/' || $dir === '.') {
        $dir = '';
    }

    return rtrim($scheme . '://' . $host . $dir, '/');
}
