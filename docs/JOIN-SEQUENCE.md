# Join sequence

`Server::joinWorld` sends the play-state join in the order of the official 26.3 server's `PlayerList.placeNewPlayer` and `sendLevelInfo`:

1. `login`, `change_difficulty`, `player_abilities`, `set_held_slot`
2. the permission-level `entity_event` and `commands`
3. `recipe_book_settings`
4. `player_position`, `server_data`
5. two `player_info_update` packets: the players already online, sent to the joining player, then the joining player, sent to everyone, each with every action (mask `0xff`)
6. `initialize_border`, a full `set_time`, `set_default_spawn_position`
7. weather events, only while it rains
8. `game_event` 13 (chunk loading starts), `ticking_state`, `ticking_step`
9. `set_chunk_cache_center`, `post_effects`
10. `container_set_content`, `set_entity_data`, `set_health` and `set_experience`, then chunks

The world border is vanilla's default. The tick rate is 20, unfrozen, with no pending steps. The recipe book's filters are all off. These payloads, and the player list entries, are byte-identical to the official server's. The join message is broadcast before the joining player is added, so they don't receive it, as in vanilla. There is no extra welcome message.

Not sent yet, because their systems aren't implemented:
- `update_recipes` and `recipe_book_add` (#24)
- `update_advancements` (#42)
- `update_attributes` (#23)

Chunks are sent the way vanilla's `PlayerChunkSender` sends them:
- **First batch:** up to nine chunks, with one unacknowledged batch allowed.
- **After acknowledgements:** the quota grows by the rate the client reports in `chunk_batch_received` (clamped to 0.01–64; NaN means the minimum), up to ten unacknowledged batches.
- **Order:** the nearest pending chunks first.

Vanilla only sends chunks that are already loaded, and generation there runs on worker threads. Here generation and packet building are synchronous, so a batch also stops after 25 ms to keep the tick rate up. Building a chunk packet currently takes about 15 ms (#111).

PHP also sends a second `set_entity_data` for the player's metadata.

`tests/join-sequence.py` pins the order and payloads. `tools/parity/run.py` scenarios and `tools/parity/verify.py` compare against and decode with the official server.
