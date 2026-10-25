<?php
declare(strict_types=1);
namespace PhpMinecraftJavaServer\Protocol;

/** Network byte order primitives. Minecraft VarInts are signed, not ZigZag. */
final class Binary
{
    private int $offset = 0;
    public function __construct(private readonly string $data = '') {}
    public function remaining(): int { return strlen($this->data) - $this->offset; }
    public function end(): void {
        if ($this->remaining() !== 0) { throw new ProtocolException('Trailing packet data'); }
    }
    public function bytes(int $length): string {
        if ($length < 0 || $length > $this->remaining()) { throw new ProtocolException('Truncated field'); }
        $value = substr($this->data, $this->offset, $length);
        $this->offset += $length;
        return $value;
    }
    public function byte(): int { return ord($this->bytes(1)); }
    public function bool(): bool {
        $value = $this->byte();
        if ($value > 1) { throw new ProtocolException('Invalid boolean'); }
        return $value === 1;
    }
    public function ushort(): int { return unpack('n', $this->bytes(2))[1]; }
    public function short(): int { $v = $this->ushort(); return $v >= 0x8000 ? $v - 0x10000 : $v; }
    public function int(): int { $v = unpack('N', $this->bytes(4))[1]; return $v >= 0x80000000 ? $v - 0x100000000 : $v; }
    public function long(): int { return unpack('J', $this->bytes(8))[1]; }
    public function float(): float { return unpack('G', $this->bytes(4))[1]; }
    public function double(): float { return unpack('E', $this->bytes(8))[1]; }
    public function varInt(): int {
        $value = 0;
        for ($i = 0; $i < 5; ++$i) {
            $byte = $this->byte();
            if ($i === 4 && ($byte & 0xf0) !== 0) { throw new ProtocolException('VarInt overflow'); }
            $value |= ($byte & 0x7f) << ($i * 7);
            if (($byte & 0x80) === 0) { return $value >= 0x80000000 ? $value - 0x100000000 : $value; }
        }
        throw new ProtocolException('VarInt exceeds five bytes');
    }
    public function varLong(): int {
        $value = 0;
        for ($i = 0; $i < 10; ++$i) {
            $byte = $this->byte();
            if ($i === 9 && ($byte & 0xfe) !== 0) { throw new ProtocolException('VarLong overflow'); }
            $value |= ($byte & 0x7f) << ($i * 7);
            if (($byte & 0x80) === 0) { return $value; }
        }
        throw new ProtocolException('VarLong exceeds ten bytes');
    }
    public function string(int $maxCharacters = 32767): string {
        $length = $this->varInt();
        if ($length < 0 || $length > $maxCharacters * 3) { throw new ProtocolException('String length exceeds limit'); }
        $text = $this->bytes($length);
        if (preg_match('//u', $text) !== 1 || self::utf16Length($text) > $maxCharacters) { throw new ProtocolException('Invalid UTF-8 or oversized string'); }
        return $text;
    }
    public function position(): array {
        $v = $this->long();
        $x = $v >> 38;
        $y = $v & 0xfff;
        $z = ($v >> 12) & 0x3ffffff;
        return [$x, $y >= 0x800 ? $y - 0x1000 : $y, $z >= 0x2000000 ? $z - 0x4000000 : $z];
    }
    public static function writeVarInt(int $value): string {
        if ($value < -2147483648 || $value > 2147483647) { throw new \InvalidArgumentException('VarInt outside int32'); }
        $value &= 0xffffffff;
        $out = '';
        do {
            $byte = $value & 0x7f;
            $value >>= 7;
            $out .= chr($byte | ($value !== 0 ? 0x80 : 0));
        } while ($value !== 0);
        return $out;
    }
    public static function writeVarLong(int $value): string {
        $out = '';
        do {
            $byte = $value & 0x7f;
            $value = ($value >> 7) & 0x01ffffffffffffff;
            $out .= chr($byte | ($value !== 0 ? 0x80 : 0));
        } while ($value !== 0);
        return $out;
    }
    public static function writeString(string $text, int $maxCharacters = 32767): string {
        if (preg_match('//u', $text) !== 1 || strlen($text) > $maxCharacters * 3 || self::utf16Length($text) > $maxCharacters) { throw new \InvalidArgumentException('Invalid string'); }
        return self::writeVarInt(strlen($text)) . $text;
    }
    private static function utf16Length(string $text): int {
        preg_match_all('/[\x{10000}-\x{10ffff}]/u', $text, $astral);
        preg_match_all('/./us', $text, $all);
        return count($all[0]) + count($astral[0]);
    }
    public static function writeLong(int $v): string { return pack('J', $v); }
    public static function writePosition(int $x, int $y, int $z): string {
        if ($x < -33554432 || $x > 33554431 || $z < -33554432 || $z > 33554431 || $y < -2048 || $y > 2047) { throw new \InvalidArgumentException('Position out of range'); }
        return pack('J', (($x & 0x3ffffff) << 38) | (($z & 0x3ffffff) << 12) | ($y & 0xfff));
    }
    public static function offlineUuid(string $name): string {
        $uuid = md5('OfflinePlayer:' . $name, true);
        $uuid[6] = chr((ord($uuid[6]) & 0x0f) | 0x30);
        $uuid[8] = chr((ord($uuid[8]) & 0x3f) | 0x80);
        return $uuid;
    }
    public static function formatUuid(string $bytes): string {
        if (strlen($bytes) !== 16) { throw new \InvalidArgumentException('UUID must contain 16 bytes'); }
        $hex = bin2hex($bytes);
        return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
    }
}
