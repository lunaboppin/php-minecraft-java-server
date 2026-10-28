<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

/** Brigadier's StringReader, with its built-in exceptions translated as BrigadierExceptions does. */
final class StringReader
{
    public int $cursor = 0;

    public function __construct(public readonly string $string)
    {
    }

    public function canRead(int $length = 1): bool
    {
        return $this->cursor + $length <= strlen($this->string);
    }

    public function peek(int $offset = 0): string
    {
        return $this->string[$this->cursor + $offset];
    }

    public function read(): string
    {
        return $this->string[$this->cursor++];
    }

    public function skip(): void
    {
        ++$this->cursor;
    }

    public function remaining(): string
    {
        return substr($this->string, $this->cursor);
    }

    public function skipWhitespace(): void
    {
        while ($this->canRead() && ctype_space($this->peek())) {
            $this->skip();
        }
    }

    /** A CommandSyntaxException at the current cursor. */
    public function error(string $key, array $arguments = []): CommandSyntaxException
    {
        // String arguments are plain text components.
        $arguments = array_map(static fn (mixed $argument): mixed => is_string($argument) ? ['text' => $argument] : $argument, $arguments);
        return new CommandSyntaxException($arguments === [] ? ['translate' => $key] : ['translate' => $key, 'with' => $arguments], $this->string, $this->cursor);
    }

    public function readInt(): int
    {
        $number = $this->number('int');
        if (preg_match('/\A-?\d+\z/D', $number) !== 1 || (float) $number > 2147483647 || (float) $number < -2147483648) {
            $this->cursor -= strlen($number);
            throw $this->error('parsing.int.invalid', [$number]);
        }
        return (int) $number;
    }

    public function readFloat(): float
    {
        return $this->decimal('float');
    }

    public function readDouble(): float
    {
        return $this->decimal('double');
    }

    public function readBoolean(): bool
    {
        $start = $this->cursor;
        $value = $this->readString();
        if ($value === '') {
            throw $this->error('parsing.bool.expected');
        }
        if ($value === 'true' || $value === 'false') {
            return $value === 'true';
        }
        $this->cursor = $start;
        throw $this->error('parsing.bool.invalid', [$value]);
    }

    public function readUnquotedString(): string
    {
        $start = $this->cursor;
        while ($this->canRead() && preg_match('/[0-9A-Za-z_\-.+]/', $this->peek()) === 1) {
            $this->skip();
        }
        return substr($this->string, $start, $this->cursor - $start);
    }

    public function readString(): string
    {
        if (!$this->canRead()) {
            return '';
        }
        $next = $this->peek();
        if ($next === '"' || $next === "'") {
            $this->skip();
            return $this->readStringUntil($next);
        }
        return $this->readUnquotedString();
    }

    public function readQuotedString(): string
    {
        if (!$this->canRead()) {
            return '';
        }
        $next = $this->peek();
        if ($next !== '"' && $next !== "'") {
            throw $this->error('parsing.quote.expected.start');
        }
        $this->skip();
        return $this->readStringUntil($next);
    }

    /** Identifier.read: resource location characters, then Identifier.parse. */
    public function readIdentifier(): string
    {
        $start = $this->cursor;
        while ($this->canRead() && preg_match('/[0-9a-z_:\/.\-]/', $this->peek()) === 1) {
            $this->skip();
        }
        $text = substr($this->string, $start, $this->cursor - $start);
        [$namespace, $path] = str_contains($text, ':') ? explode(':', $text, 2) : ['minecraft', $text];
        if (preg_match('/\A[a-z0-9_.\-]*\z/D', $namespace) !== 1 || preg_match('/\A[a-z0-9_.\-\/]*\z/D', $path) !== 1) {
            $this->cursor = $start;
            throw $this->error('argument.id.invalid');
        }
        return ($namespace === '' ? 'minecraft' : $namespace) . ':' . $path;
    }

    private function readStringUntil(string $terminator): string
    {
        $result = '';
        $escaped = false;
        while ($this->canRead()) {
            $char = $this->read();
            if ($escaped) {
                if ($char !== $terminator && $char !== '\\') {
                    --$this->cursor;
                    throw $this->error('parsing.quote.escape', [$char]);
                }
                $result .= $char;
                $escaped = false;
            } elseif ($char === '\\') {
                $escaped = true;
            } elseif ($char === $terminator) {
                return $result;
            } else {
                $result .= $char;
            }
        }
        throw $this->error('parsing.quote.expected.end');
    }

    /** The characters Brigadier accepts in a number: digits, '.' and '-'. */
    private function number(string $kind): string
    {
        $start = $this->cursor;
        while ($this->canRead() && preg_match('/[0-9.\-]/', $this->peek()) === 1) {
            $this->skip();
        }
        if ($this->cursor === $start) {
            throw $this->error('parsing.' . $kind . '.expected');
        }
        return substr($this->string, $start, $this->cursor - $start);
    }

    private function decimal(string $kind): float
    {
        $number = $this->number($kind);
        if (preg_match('/\A-?(\d+\.?\d*|\.\d+)\z/D', $number) !== 1) {
            $this->cursor -= strlen($number);
            throw $this->error('parsing.' . $kind . '.invalid', [$number]);
        }
        $value = (float) $number;
        return $kind === 'float' ? unpack('G', pack('G', $value))[1] : $value;
    }
}
