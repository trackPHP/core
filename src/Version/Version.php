<?php
namespace TrackPHP\Version;

final class Version
{
    private static ?string $cached = null;

    public static function current(): string
    {
        if (self::versionIsCached()) return self::cachedVersion();
        else return self::versionFromFile();
    }

    private static function versionIsCached() {
        if (self::$cached !== null) return true;
        return false;
    }

    private static function cachedVersion() {
        return self::$cached;
    }

    private static function versionFromFile() {
        $file = dirname(__DIR__, 2) . '/VERSION';

        if (!is_file($file)) {
            throw new \RuntimeException('VERSION file is missing');
        }

        return self::$cached = trim((string) file_get_contents($file));
    }
}
