<?php
declare(strict_types=1);

namespace ZBG;

const PLUGIN = 'zfs-backup-guard';
const VERSION = '2026.10.14';

/** Persistente Konfiguration auf dem Flash. Für Tests per ZBG_CONFIG_DIR überschreibbar. */
function configDir(): string
{
    return getenv('ZBG_CONFIG_DIR') ?: '/boot/config/plugins/' . PLUGIN;
}

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'ZBG\\')) {
        return;
    }
    $file = __DIR__ . '/../lib/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

/** Fehler, deren Meldung direkt in der UI angezeigt werden darf. */
final class UserError extends \RuntimeException
{
}
