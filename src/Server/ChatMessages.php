<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\Protocol\Binary;
use PhpMinecraftJavaServer\Protocol\Nbt;

/**
 * Command chat as vanilla sends it to unsigned clients: ClientboundDisguisedChatPacket with the
 * message, its bound chat type, the sender's display name and an optional target name.
 */
trait ChatMessages
{
    /** The chat_type registry's network ID. */
    private function chatTypeId(string $type): int
    {
        static $types = null;
        $types ??= array_flip(json_decode((string) file_get_contents($this->version->directory . '/dynamic-registries.json'), true, 512, JSON_THROW_ON_ERROR)['minecraft:chat_type']);
        return $types['minecraft:' . $type];
    }

    private function sendDisguisedChat(Client $to, array $content, string $type, array $name, ?array $target = null): void
    {
        // ServerPlayer.acceptsChatMessages: only players showing all chat receive it.
        if (($to->information['chatVisibility'] ?? 0) !== 0) {
            return;
        }
        $to->send($this->version, 'disguised_chat', Nbt::component($content) . Binary::writeVarInt($this->chatTypeId($type) + 1) . Nbt::component($name)
            . ($target === null ? "\0" : "\1" . Nbt::component($target)));
    }

    /**
     * PlayerList.broadcastChatMessage for an unsigned chat message: ClientboundPlayerChatPacket
     * with no signature, the server's timestamp and the chat type bound to the sender's name, to
     * each player who shows chat.
     */
    private function broadcastPlayerChat(Client $sender, string $message): void
    {
        $this->log('[Not Secure] <' . $sender->name . '> ' . $message);
        $body = Binary::writeString($message) . pack('J', (int) floor(microtime(true) * 1000)) . pack('J', 0) . Binary::writeVarInt(0);
        $type = Binary::writeVarInt($this->chatTypeId('chat') + 1) . Nbt::component($this->displayName($sender)) . "\0";
        foreach ($this->players() as $player) {
            if (($player->information['chatVisibility'] ?? 0) !== 0) {
                continue;
            }
            $player->send($this->version, 'player_chat', Binary::writeVarInt($player->chatIndex++) . $sender->uuid . Binary::writeVarInt(0) . "\0" . $body . "\0" . Binary::writeVarInt(0) . $type);
        }
    }

    /**
     * MessageArgument: the message text, with each entity selector replaced by the names it
     * selects when the sender may use selectors.
     */
    private function messageComponent(Client $sender, string $text): array
    {
        $parts = [];
        if ($this->operator($sender)) {
            preg_match_all('/@[parsen](?:\[(?:[^\]"\']|"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\')*\])?/', $text, $matches, PREG_OFFSET_CAPTURE);
            $parts = $matches[0];
        }
        if ($parts === []) {
            return ['text' => $text];
        }
        $extra = [];
        $position = 0;
        foreach ($parts as [$selector, $start]) {
            if ($position < $start) {
                $extra[] = ['text' => substr($text, $position, $start - $position)];
            }
            try {
                $entities = $this->selectEntities($selector, $sender);
            } catch (Commands\CommandFailure) {
                // A selector matching nothing contributes nothing.
                $entities = [];
            }
            $extra[] = $this->formatList(array_map(fn (array $entity): array => $this->entityName($entity), $entities));
            $position = $start + strlen($selector);
        }
        if ($position < strlen($text)) {
            $extra[] = ['text' => substr($text, $position)];
        }
        return ['text' => '', 'extra' => $extra];
    }
}
