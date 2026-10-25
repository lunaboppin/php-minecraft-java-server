# Mob and spawn-egg coverage

Target: official Java 26.3. The pinned JAR supplies all 88 egg mappings, type IDs, dimensions, default attribute suppliers, feature flags and peaceful-mode eligibility. Default attribute suppliers are a baseline; randomized and species-specific `finalizeSpawn` behavior is incomplete.

`tests/spawn-eggs.py` exercises all egg/type mappings, multiplayer creation and restart. `tests/parity/spawn-eggs.json` checks representative initial positions and inventory against Java. `tests/mob-movement.py` checks shared local collision, water containment and flight. A successful creation check does not establish complete mob behavior.

| Egg | Entity type | Native creation/restart | Java initial type/position | Full species behavior |
| --- | --- | --- | --- | --- |
| `chicken_spawn_egg` | `chicken` | Tested | Unverified | Incomplete |
| `cow_spawn_egg` | `cow` | Tested | Tested | Incomplete |
| `pig_spawn_egg` | `pig` | Tested | Unverified | Incomplete |
| `sheep_spawn_egg` | `sheep` | Tested | Unverified | Incomplete |
| `camel_spawn_egg` | `camel` | Tested | Unverified | Incomplete |
| `donkey_spawn_egg` | `donkey` | Tested | Unverified | Incomplete |
| `horse_spawn_egg` | `horse` | Tested | Unverified | Incomplete |
| `mule_spawn_egg` | `mule` | Tested | Unverified | Incomplete |
| `cat_spawn_egg` | `cat` | Tested | Unverified | Incomplete |
| `parrot_spawn_egg` | `parrot` | Tested | Unverified | Incomplete |
| `wolf_spawn_egg` | `wolf` | Tested | Unverified | Incomplete |
| `armadillo_spawn_egg` | `armadillo` | Tested | Unverified | Incomplete |
| `bat_spawn_egg` | `bat` | Tested | Tested | Incomplete |
| `bee_spawn_egg` | `bee` | Tested | Unverified | Incomplete |
| `fox_spawn_egg` | `fox` | Tested | Unverified | Incomplete |
| `goat_spawn_egg` | `goat` | Tested | Unverified | Incomplete |
| `llama_spawn_egg` | `llama` | Tested | Unverified | Incomplete |
| `ocelot_spawn_egg` | `ocelot` | Tested | Unverified | Incomplete |
| `panda_spawn_egg` | `panda` | Tested | Unverified | Incomplete |
| `polar_bear_spawn_egg` | `polar_bear` | Tested | Unverified | Incomplete |
| `rabbit_spawn_egg` | `rabbit` | Tested | Unverified | Incomplete |
| `axolotl_spawn_egg` | `axolotl` | Tested | Unverified | Incomplete |
| `cod_spawn_egg` | `cod` | Tested | Tested | Incomplete |
| `dolphin_spawn_egg` | `dolphin` | Tested | Unverified | Incomplete |
| `frog_spawn_egg` | `frog` | Tested | Unverified | Incomplete |
| `glow_squid_spawn_egg` | `glow_squid` | Tested | Unverified | Incomplete |
| `nautilus_spawn_egg` | `nautilus` | Tested | Unverified | Incomplete |
| `pufferfish_spawn_egg` | `pufferfish` | Tested | Unverified | Incomplete |
| `salmon_spawn_egg` | `salmon` | Tested | Unverified | Incomplete |
| `squid_spawn_egg` | `squid` | Tested | Unverified | Incomplete |
| `tadpole_spawn_egg` | `tadpole` | Tested | Unverified | Incomplete |
| `tropical_fish_spawn_egg` | `tropical_fish` | Tested | Unverified | Incomplete |
| `turtle_spawn_egg` | `turtle` | Tested | Unverified | Incomplete |
| `allay_spawn_egg` | `allay` | Tested | Unverified | Incomplete |
| `mooshroom_spawn_egg` | `mooshroom` | Tested | Unverified | Incomplete |
| `sniffer_spawn_egg` | `sniffer` | Tested | Unverified | Incomplete |
| `sulfur_cube_spawn_egg` | `sulfur_cube` | Tested | Unverified | Incomplete |
| `copper_golem_spawn_egg` | `copper_golem` | Tested | Unverified | Incomplete |
| `iron_golem_spawn_egg` | `iron_golem` | Tested | Tested | Incomplete |
| `snow_golem_spawn_egg` | `snow_golem` | Tested | Unverified | Incomplete |
| `trader_llama_spawn_egg` | `trader_llama` | Tested | Unverified | Incomplete |
| `villager_spawn_egg` | `villager` | Tested | Unverified | Incomplete |
| `wandering_trader_spawn_egg` | `wandering_trader` | Tested | Unverified | Incomplete |
| `bogged_spawn_egg` | `bogged` | Tested | Unverified | Incomplete |
| `camel_husk_spawn_egg` | `camel_husk` | Tested | Unverified | Incomplete |
| `drowned_spawn_egg` | `drowned` | Tested | Unverified | Incomplete |
| `husk_spawn_egg` | `husk` | Tested | Unverified | Incomplete |
| `parched_spawn_egg` | `parched` | Tested | Unverified | Incomplete |
| `skeleton_spawn_egg` | `skeleton` | Tested | Unverified | Incomplete |
| `skeleton_horse_spawn_egg` | `skeleton_horse` | Tested | Unverified | Incomplete |
| `stray_spawn_egg` | `stray` | Tested | Unverified | Incomplete |
| `wither_spawn_egg` | `wither` | Tested | Unverified | Incomplete |
| `wither_skeleton_spawn_egg` | `wither_skeleton` | Tested | Unverified | Incomplete |
| `zombie_spawn_egg` | `zombie` | Tested | Tested | Incomplete |
| `zombie_horse_spawn_egg` | `zombie_horse` | Tested | Unverified | Incomplete |
| `zombie_nautilus_spawn_egg` | `zombie_nautilus` | Tested | Unverified | Incomplete |
| `zombie_villager_spawn_egg` | `zombie_villager` | Tested | Unverified | Incomplete |
| `cave_spider_spawn_egg` | `cave_spider` | Tested | Unverified | Incomplete |
| `spider_spawn_egg` | `spider` | Tested | Unverified | Incomplete |
| `breeze_spawn_egg` | `breeze` | Tested | Unverified | Incomplete |
| `creaking_spawn_egg` | `creaking` | Tested | Unverified | Incomplete |
| `creeper_spawn_egg` | `creeper` | Tested | Unverified | Incomplete |
| `elder_guardian_spawn_egg` | `elder_guardian` | Tested | Unverified | Incomplete |
| `guardian_spawn_egg` | `guardian` | Tested | Unverified | Incomplete |
| `phantom_spawn_egg` | `phantom` | Tested | Unverified | Incomplete |
| `silverfish_spawn_egg` | `silverfish` | Tested | Unverified | Incomplete |
| `slime_spawn_egg` | `slime` | Tested | Unverified | Incomplete |
| `warden_spawn_egg` | `warden` | Tested | Unverified | Incomplete |
| `witch_spawn_egg` | `witch` | Tested | Unverified | Incomplete |
| `evoker_spawn_egg` | `evoker` | Tested | Unverified | Incomplete |
| `pillager_spawn_egg` | `pillager` | Tested | Unverified | Incomplete |
| `ravager_spawn_egg` | `ravager` | Tested | Unverified | Incomplete |
| `vindicator_spawn_egg` | `vindicator` | Tested | Unverified | Incomplete |
| `vex_spawn_egg` | `vex` | Tested | Unverified | Incomplete |
| `blaze_spawn_egg` | `blaze` | Tested | Unverified | Incomplete |
| `ghast_spawn_egg` | `ghast` | Tested | Unverified | Incomplete |
| `happy_ghast_spawn_egg` | `happy_ghast` | Tested | Unverified | Incomplete |
| `hoglin_spawn_egg` | `hoglin` | Tested | Unverified | Incomplete |
| `magma_cube_spawn_egg` | `magma_cube` | Tested | Unverified | Incomplete |
| `piglin_spawn_egg` | `piglin` | Tested | Unverified | Incomplete |
| `piglin_brute_spawn_egg` | `piglin_brute` | Tested | Unverified | Incomplete |
| `strider_spawn_egg` | `strider` | Tested | Unverified | Incomplete |
| `zoglin_spawn_egg` | `zoglin` | Tested | Unverified | Incomplete |
| `zombified_piglin_spawn_egg` | `zombified_piglin` | Tested | Unverified | Incomplete |
| `ender_dragon_spawn_egg` | `ender_dragon` | Tested | Unverified | Incomplete |
| `enderman_spawn_egg` | `enderman` | Tested | Unverified | Incomplete |
| `endermite_spawn_egg` | `endermite` | Tested | Unverified | Incomplete |
| `shulker_spawn_egg` | `shulker` | Tested | Unverified | Incomplete |

## Remaining requirements

Species goal stacks/navigation (#35), passive interactions and breeding/taming (#37), hostile spawning/combat (#38), aquatic/flying/neutral/tameable behavior (#44), villagers/raids (#50), Wither (#48), Dragon (#52), loot (#34), components (#9), collision context and state updates (#15), and complete spawn eggs (#75) remain open.

Egg-on-mob offspring, spawner editing, custom entity components, full randomized spawn equipment/variants and special size initialization are not implemented. Ground/air/water steering is shared scaffolding rather than a port of each vanilla goal stack. New types do not yet have complete loot tables, attacks, environmental damage, reproduction or progression. The current mob capacity is 256 per dimension.

Collision and outline shapes are exported using the empty block getter/context. Context-sensitive collision (such as entity-specific powder-snow/scaffolding behavior), moving block entities, world-border/entity collisions and full navigation remain incomplete. Existing edited worlds are preserved; the bounded block-edit read cache updates with every native world write.
