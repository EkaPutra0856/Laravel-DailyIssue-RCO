<?php

namespace App\Handover;

/**
 * Padanan kecil perilaku string Python yang dipakai aturan rekap, supaya hasil PHP identik dengan versi Python:
 * str.strip() / str.split() memakai whitespace Unicode, len() menghitung karakter, str(float) -> "2.0".
 */
final class Py
{
    // Whitespace menurut str.isspace() Python.
    public const WS = '[\s\x{1C}-\x{1F}\x{85}\x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]';

    public static function strip(string $s): string
    {
        return preg_replace('#^'.self::WS.'+|'.self::WS.'+$#u', '', $s) ?? $s;
    }

    /** @return string[] */
    public static function split(string $s): array
    {
        return preg_split('#'.self::WS.'+#u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    public static function len(string $s): int
    {
        return mb_strlen($s, 'UTF-8');
    }

    public static function lower(string $s): string
    {
        return mb_strtolower($s, 'UTF-8');
    }

    /** str(v) ala Python untuk nilai sel. */
    public static function str(mixed $v): string
    {
        return match (true) {
            $v === null => 'None',
            is_bool($v) => $v ? 'True' : 'False',
            is_float($v) => self::floatStr($v),
            default => (string) $v,
        };
    }

    /** isinstance(v, (int, float)) and not isinstance(v, bool) */
    public static function isNumber(mixed $v): bool
    {
        return is_int($v) || is_float($v);
    }

    /** html.escape() Python (quote=True). */
    public static function escape(string $s): string
    {
        return str_replace(['&', '<', '>', '"', "'"], ['&amp;', '&lt;', '&gt;', '&quot;', '&#x27;'], $s);
    }

    private static function floatStr(float $v): string
    {
        $s = var_export($v, true);  // 2.0 -> '2.0', 12.5 -> '12.5'

        return str_contains($s, '.') || str_contains($s, 'E') || str_contains($s, 'N') || str_contains($s, 'I')
            ? $s : $s.'.0';
    }
}
