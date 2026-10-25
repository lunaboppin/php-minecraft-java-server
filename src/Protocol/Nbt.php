<?php
declare(strict_types=1);
namespace PhpMinecraftJavaServer\Protocol;

/** Network NBT has an unnamed root (no two-byte root-name prefix). */
final class Nbt
{
    public static function text(string $text): string {
        if (preg_match('//u', $text) !== 1) { throw new \InvalidArgumentException('Invalid UTF-8'); }
        return "\x08" . self::string($text);
    }
    // NBT uses Java modified UTF-8, including surrogate pairs for supplementary characters.
    private static function string(string $value): string {
        $utf16 = iconv('UTF-8', 'UTF-16BE', $value);
        if ($utf16 === false) { throw new \InvalidArgumentException('Invalid text'); }
        $out = '';
        foreach (unpack('n*', $utf16) as $unit) {
            if ($unit >= 1 && $unit <= 127) { $out .= chr($unit); }
            elseif ($unit <= 2047) { $out .= chr(0xc0 | ($unit >> 6)) . chr(0x80 | ($unit & 63)); }
            else { $out .= chr(0xe0 | ($unit >> 12)) . chr(0x80 | (($unit >> 6) & 63)) . chr(0x80 | ($unit & 63)); }
        }
        if (strlen($out) > 65535) { throw new \InvalidArgumentException('NBT string too long'); }
        return pack('n', strlen($out)) . $out;
    }
}
