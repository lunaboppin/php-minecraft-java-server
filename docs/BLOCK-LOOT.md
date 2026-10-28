# Block loot

Survival block breaking drops what the official 26.3 loot table gives for the held tool. `tools/update_reference.py` copies every `data/minecraft/loot_table/blocks/*.json` table, plus the named predicates they reference (such as `minecraft:tool/can_silk_touch`), into `reference/26.3/block-loot.json`. `World\BlockLoot` then evaluates a table the way `LootTable.getRandomItems` does.

The evaluator covers:

- **Pools:** conditions, rolls, and weighted entry choice. Alternatives take their first expanding child.
- **Predicates:**
  - `survives_explosion`, `all_of`, `any_of`, `inverted` and `random_chance`
  - `table_bonus` (fortune chances)
  - `match_block` (block state, including ranges)
  - `match_tool` (items, item tags, enchantment levels)
  - `location_check` (the other half of a tall plant)
  - `entity_properties` with an empty predicate
- **Modifiers:** `set_count`, `apply_bonus` (`ore_drops`, `uniform_bonus_count`, `binomial_with_bonus_count`) and `limit_count`.
- **Number providers:** constant, uniform and binomial.

Item and block tags come from the pinned `update_tags` payload (`Version::registryTags`).

Ores grant `DropExperienceBlock` experience unless mined with silk touch. A block that requires a tool still drops nothing without the correct one.

Not modelled yet (#34):

- Explosions, so `explosion_decay` and `survives_explosion` always keep items.
- Block entity components (`copy_components`), `copy_state` and dynamic contents.
- Vanilla's per-world random sequences, so random drops match vanilla's distribution, not its exact sequence.

`tests/block-loot-unit.php` checks representative tables with a fixed random source.
