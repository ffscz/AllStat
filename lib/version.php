<?php
/**
 * Verze AllStatu, která se ukazuje v bočním panelu. Při vydání nové verze ji zvedni spolu s
 * allstat-balicek/overlay/VERSION (build.php ověří, že se shodují).
 */
const ALLSTAT_VERSION = '1.2.4';

/** Verze běžící instalace: z kořenového souboru VERSION (veřejný balíček), jinak z konstanty výše. */
function allstat_app_version(): string
{
    $file = trim((string) @file_get_contents(dirname(__DIR__) . '/VERSION'));

    return preg_match('/^\d+\.\d+\.\d+$/', $file) === 1 ? $file : ALLSTAT_VERSION;
}
