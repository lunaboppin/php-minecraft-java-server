<?php
declare(strict_types=1);
namespace PhpMinecraftJavaServer\Server;

/** Read a one-shot deployment disconnect message next to the primary world database. */
final class RestartNotice
{
    public const FALLBACK='Server restarting. Please reconnect shortly.';
    public static function consume(string $worldPath):string {
        return OperatorMessage::consume(dirname($worldPath).'/restart-reason.txt')??self::FALLBACK;
    }
}
