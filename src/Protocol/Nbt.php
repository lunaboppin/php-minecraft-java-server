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
    /**
     * Encode a text component. A component with only `text` stays a bare string tag, as vanilla
     * does; otherwise it is a compound supporting text, translate, color, italic, with and extra.
     */
    public static function component(array $component): string
    {
        if (array_keys($component) === ['text']) {
            return self::text($component['text']);
        }
        return "\x0a" . self::componentBody($component);
    }

    private static function componentBody(array $component): string
    {
        $out = '';
        foreach ($component as $key => $value) {
            $out .= match ($key) {
                'text', 'translate', 'color' => "\x08" . self::string($key) . self::string($value),
                'italic' => "\x01" . self::string($key) . chr((int) $value),
                'with', 'extra' => "\x09" . self::string($key) . "\x0a" . pack('N', count($value))
                    . implode('', array_map(self::componentBody(...), $value)),
                default => throw new \InvalidArgumentException('Unsupported text component field: ' . $key),
            };
        }
        return $out . "\0";
    }

    public static function sign(array $data):string {
        $out="\x0a";
        foreach(['front','back'] as $side){
            $out.="\x0a".self::string($side.'_text')."\x09".self::string('messages')."\x08".pack('N',4);
            foreach($data[$side]??['','','',''] as $line){$out.=self::string($line);}
            $out.="\x08".self::string('color').self::string('black')."\x01".self::string('has_glowing_text')."\0\0";
        }
        return $out."\x01".self::string('is_waxed')."\0\0";
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
