# Loot

Broken blocks and killed mobs drop what the official 26.3 loot tables give. `tools/update_reference.py` copies every `data/minecraft/loot_table/blocks/` and `entities/` table, plus the named predicates they reference (such as `minecraft:tool/can_silk_touch`), into `reference/26.3/loot.json`. `World\Loot` then evaluates a table the way `LootTable.getRandomItems` does.

## What the evaluator covers

- **Pools:** conditions, rolls, and weighted entry choice. Alternatives take their first expanding child. Groups and sequences are supported, as are nested `loot_table` entries (such as a sheep's wool colour) and item `tag` entries.
- **Predicates:**
  - `survives_explosion`, `all_of`, `any_of`, `inverted` and `random_chance`
  - `random_chance_with_enchanted_bonus` (looting) and `table_bonus` (fortune)
  - `match_block` (block state, including ranges)
  - `match_tool` (items, item tags, enchantment levels and tags)
  - `location_check` (the other half of a tall plant)
  - `killed_by_player`
  - `entity_properties`: entity type or tag, flags, equipment, data components, type-specific data and the vehicle
  - `damage_source_properties`: source and direct entities, damage type tags
- **Modifiers:**
  - `set_count`, `apply_bonus` (`ore_drops`, `uniform_bonus_count`, `binomial_with_bonus_count`) and `limit_count`
  - `enchanted_count_increase` (looting)
  - `furnace_smelt`, through the official smelting recipes
- **Number providers:** constant, uniform and binomial.

Item, block, entity type and enchantment tags come from the pinned `update_tags` payload (`Version::registryTags`). Damage type tags come from `damage-types.json`.

## Where it is used

- **Survival block breaking:** the drops follow the held tool. A block that requires a tool drops nothing without the correct one. Ores grant `DropExperienceBlock` experience unless mined with silk touch.
- **`/setblock` and `/fill` `destroy`:** these drop the table without a tool.
- **Mob deaths:** these drop the entity table. The player killer is the attacker, and their main hand is used for looting and fire aspect. As in `LivingEntity.shouldDropLoot`, babies drop nothing. The `block_drops` and `mob_drops` game rules turn drops off.

## Not modelled yet (#34)

- Explosions, so `explosion_decay` and `survives_explosion` always keep items.
- Block entity components (`copy_components`), `copy_state`, potions and dynamic contents.
- Mob fire and sheep colours, which this server does not track, so mobs are never burning and sheep are white.
- Vanilla's per-world random sequences, so random drops match vanilla's distribution, not its exact sequence.

`tests/loot-unit.php` checks representative block and entity tables with a fixed random source.
