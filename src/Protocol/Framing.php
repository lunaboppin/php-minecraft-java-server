<?php
declare(strict_types=1);
namespace Jva\Protocol;

final class Framing
{
    public const MAX_PACKET = 2097151;
    /** Consumes exactly one complete frame; a partial TCP frame remains untouched. */
    public static function extract(string &$buffer, int $threshold = -1): ?string {
        $length = 0; $prefix = 0;
        for ($i = 0; $i < 3; ++$i) {
            if (!isset($buffer[$i])) { return null; }
            $byte = ord($buffer[$i]);
            $length |= ($byte & 0x7f) << ($i * 7);
            if (($byte & 0x80) === 0) { $prefix = $i + 1; break; }
        }
        if ($prefix === 0 || $length === 0) { throw new ProtocolException('Invalid frame length'); }
        if (strlen($buffer) < $prefix + $length) { return null; }
        $payload = substr($buffer, $prefix, $length);
        $buffer = substr($buffer, $prefix + $length);
        if ($threshold < 0) { return $payload; }
        $reader = new Binary($payload);
        $size = $reader->varInt();
        $body = $reader->bytes($reader->remaining());
        if ($size === 0) {
            if (strlen($body) >= $threshold) { throw new ProtocolException('Uncompressed packet above threshold'); }
            return $body;
        }
        if ($size < $threshold || $size > self::MAX_PACKET) { throw new ProtocolException('Invalid decompressed length'); }
        $inflater = inflate_init(ZLIB_ENCODING_DEFLATE);
        if ($inflater === false) { throw new ProtocolException('Cannot initialize decompression'); }
        // Feed bounded slices; never let a tiny compressed frame expand without a limit.
        $decoded = '';
        for ($i = 0, $len = strlen($body); $i < $len; $i += 256) {
            $part = @inflate_add($inflater, substr($body, $i, 256), ZLIB_SYNC_FLUSH);
            if ($part === false) { throw new ProtocolException('Invalid zlib stream'); }
            $decoded .= $part;
            if (strlen($decoded) > $size) { throw new ProtocolException('Decompressed packet exceeds declared size'); }
        }
        if (inflate_get_status($inflater) !== ZLIB_STREAM_END || inflate_get_read_len($inflater) !== strlen($body) || strlen($decoded) !== $size) { throw new ProtocolException('Invalid compressed packet'); }
        return $decoded;
    }
    public static function encode(int $id, string $payload = '', int $threshold = -1): string {
        if ($id < 0) { throw new \InvalidArgumentException('Negative packet id'); }
        $body = Binary::writeVarInt($id) . $payload;
        if (strlen($body) > self::MAX_PACKET) { throw new ProtocolException('Packet too large'); }
        if ($threshold >= 0) {
            $body = strlen($body) >= $threshold ? Binary::writeVarInt(strlen($body)) . gzcompress($body) : "\0" . $body;
        }
        if (strlen($body) > self::MAX_PACKET) { throw new ProtocolException('Frame too large'); }
        return Binary::writeVarInt(strlen($body)) . $body;
    }
}
