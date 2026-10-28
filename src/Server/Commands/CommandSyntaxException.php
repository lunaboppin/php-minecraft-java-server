<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

/**
 * A CommandSyntaxException with its input and cursor. Raised while parsing, vanilla follows the
 * message with the input around the cursor (Commands.finishParsing).
 */
final class CommandSyntaxException extends CommandFailure
{
    public function __construct(array $component, public readonly string $input, public readonly int $cursor)
    {
        parent::__construct($component);
    }

    /** The grey context line: up to ten characters before the cursor, the rest in red, then "<--[HERE]". */
    public function context(): array
    {
        $cursor = min(strlen($this->input), $this->cursor);
        // Java counts UTF-16 units; the command text is counted in characters here.
        $before = mb_strlen(substr($this->input, 0, $cursor), 'UTF-8');
        $extra = [];
        if ($before > 10) {
            $extra[] = ['text' => '...'];
        }
        $shown = mb_substr(substr($this->input, 0, $cursor), max(0, $before - 10), null, 'UTF-8');
        if ($shown !== '') {
            $extra[] = ['text' => $shown];
        }
        if ($cursor < strlen($this->input)) {
            $extra[] = ['text' => substr($this->input, $cursor), 'color' => 'red', 'underlined' => true];
        }
        $extra[] = ['translate' => 'command.context.here', 'color' => 'red', 'italic' => true];
        return ['text' => '', 'color' => 'gray', 'click_event' => ['action' => 'suggest_command', 'command' => '/' . $this->input], 'extra' => $extra];
    }
}
