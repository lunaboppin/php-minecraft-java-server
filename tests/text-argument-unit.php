<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use PhpMinecraftJavaServer\Server\Commands\CommandSyntaxException;
use PhpMinecraftJavaServer\Server\Commands\StringReader;
use PhpMinecraftJavaServer\Server\Commands\TextComponentArgument;

$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    ++$checks;
};
$parse = static function (string $text): array|string {
    try {
        return TextComponentArgument::read(new StringReader($text));
    } catch (CommandSyntaxException $error) {
        return $error->component['translate'] . ' ' . json_encode($error->component['with'] ?? []) . ' @' . $error->cursor;
    }
};
// Outputs recorded from the official server's /tellraw.
$check($parse('"hello"') === ['text' => 'hello'], 'a string is a text component');
$check($parse('{text:"hi",color:"red"}') === ['text' => 'hi', 'color' => 'red'], 'unquoted keys');
$check($parse('{"text":"json style","bold":true}') === ['text' => 'json style', 'bold' => true], 'JSON-style input and booleans');
$check($parse('["a",{text:"b",italic:1b}]') === ['text' => 'a', 'extra' => [['text' => 'b', 'italic' => true]]], 'a list carries the rest as siblings');
$check($parse('{translate:"chat.type.text",with:["x",3]}') === ['translate' => 'chat.type.text', 'with' => [['text' => 'x'], 3]], 'translation arguments keep numbers');
$check($parse('123') === 'argument.component.invalid [{"text":"Failed to parse either. First: Failed to parse either. First: Not a string; Second: Not a list: 123; Second: Not a map: 123"}] @0', 'a number is not a component');
$check($parse('{foo:"bar"}') === 'argument.component.invalid [{"text":"Failed to parse either. First: Failed to parse either. First: Not a string; Second: Not a list: {foo:\"bar\"}; Second: No matching codec found"}] @0', 'unknown content');
$check($parse('{text:"unterminated"') === 'argument.literal.incorrect [{"text":","}] @20', 'an unterminated compound');
echo "PASS: {$checks} text component argument checks\n";
