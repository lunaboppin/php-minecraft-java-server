# Mob spawn initialization

Issue #99 remains in progress. Spawning an entity is not complete initialization: native zombie families can become babies, gain persistent attribute modifiers, receive equipment, attach to an existing chicken or create a new mount. Their resulting movement, metadata and passenger relationships must survive tracking changes and restart.

The new `World/Spawning/` modules provide the group, fresh-chicken, attribute and difficulty phases for that pipeline. `MobInitialization` consumes the shared world random stream for the native triangular follow-range bonus and handedness. `ZombieAttributes` uses a separate entity random stream for reinforcement chance, knockback resistance, follow range and leader bonuses. It also applies/removes the transient baby speed modifier. Repeated initialization retains or replaces modifiers by their actual identifiers; it does not flatten their effect into base values. Conversion, load and dimension-travel reasons preserve current health when the leader branch changes maximum health.

`ZombieSpawnGroup` preserves supplied group identity and existing baby/pickup state where the native body does. Its synchronous `ZombieSpawnEnvironment` effects apply age before querying, select the first eligible chicken in world-query order, initialize a new chicken before riding it, and add the new entity after attempting attachment. Failed existing-chicken searches do not fall through into the new-chicken branch. `ChickenJockeyInitialization` selects visual and sound variants in native registry order and runs shared Mob initialization for a fresh adult chicken. Biome visual candidates come from the actual loaded vanilla selectors.

`SpawnAttributes` retains bases, modifier identifiers, operations and permanence. Operation buckets use `ModifierMap`, matching the native identifier hash, linear probing, growth/shrink behavior and reverse table iteration. That order matters for exact floating-point accumulation when multiple modifiers affect one attribute. This implements the mutation operations used by initialization; it does not claim complete attribute command, equipment or effect lifecycle behavior.

`RegionalDifficulty` accepts difficulty, world time, actual chunk inhabited time and moon brightness. It rounds at the same float boundaries as Java. `Float32::fromLong` performs direct long-to-float rounding; going through PHP's double first can round large integers twice. These modules do not invent inhabited-time values or replace the outstanding chunk simulation work.

## Official reference evidence

`ExportMobInitialization.java` invokes actual `DifficultyInstance`, `AttributeInstance` and fastutil map methods. For the five zombie families it directly invokes the shared `Mob.finalizeSpawn` phase, actual `setBaby` and actual `Zombie.handleAttributes`. The probe bypasses virtual dispatch only to isolate the shared base phase; it does not replace that phase with a hand-written oracle. Two consecutive rounds test existing modifier retention/replacement and an age change, with exact independent RNG tails.

The pinned fixture contains 983 direct long casts, 1,764 regional difficulty contexts, 320 modifier-map mutations, 1,600 attribute mutation frames and 3,840 zombie attribute-phase frames across 1,920 contexts. These include all five families, both ages, three local difficulty multipliers and four spawn reasons. PHP compares every attribute base, effective value, modifier identifier/operation/amount/permanence, handedness, health and final RNG state. The 24 leader frames exercise the health and modifier branches.

Repeated initial states and unchanged attributes are factored into shared defaults for storage. The verifier reconstructs every complete state. The exporter rejects unexpected default variation or attribute roster changes rather than discarding them.

`ReferenceWorldData.java` uses the actual vanilla `WorldLoader` and data packs, including real registry tags and component initialization. Construction-only registry lookups leave enchantment tag references unresolved and cannot substitute for this context. The isolated level still supplies a controlled plains biome, difficulty, feature flags and minimal debug server; this is phase evidence, not full-world AI or vehicle lifecycle proof.

```sh
php tests/spawn-initialization-unit.php
php tests/spawn-groups-unit.php
JAVA_HOME=/path/to/jdk25 python3 tools/update_reference.py
```

`ExportZombieSpawnGroups.java` observes 12,000 actual shared `Zombie.finalizeSpawn` prefixes on the five concrete entity classes. These cover supplied/default/unrelated groups, conversion, retained baby/pickup state, both difficulties, and empty/single/multiple/occupied/out-of-range chicken layouts. The recorder snapshots state and an independently copied native RNG at the equipment/holiday/attribute boundary. It retains child primitive draws for diagnostics; PHP does not replay those draws to implement the child.

The group test performs actual PHP fresh-chicken initialization in its injected world effects and compares 302,336 fields against Java, including complete child attributes/modifiers and RNG tails. Captures include 1,350 existing-mount and 1,000 new-mount branches. Another 1,072 actual chicken initializations cover all 67 vanilla biomes, all three visual variants, both sound variants and independent RNG tails. Shared defaults factor unchanged child attributes while reconstructing every full state for verification.

The shared Zombie body is called directly to isolate that phase. Full virtual subclass behavior remains separate: the capture retains 15 unrelated-group contract observations, including the husk's actual `ClassCastException`. Those observations are not counted as implemented PHP subclass behavior. Husk post-processing, its additional pickup roll/group wrapping, natural camel-husk/parched groups (#104), and the drowned zombie-nautilus path (#102) still need their corresponding implementation and evidence.

The test world supplies native-query results to the PHP group phase. It verifies decision and effect ordering, not a completed live entity-query, collision, passenger or metadata adapter. Actual constructor state outside the initialization phase, such as UUID and chicken egg-timer randomness, is not established by these checks.

## Work required before completing #99

The live spawn coordinator still needs to integrate these decisions and initialization phases, complete equipment/enchantment and subclass initialization, and apply per-entity attributes/dimensions/metadata. Passenger movement and metadata must be synchronized, and relationships must restore by persistent identity through tracking, dimension changes and restart. Damage, sound, dismount/death and real-client behavior need official full-world comparisons. The unseeded egg comparison must retain legitimate additional entities and explain stochastic differences without masking an unimplemented branch. Neither #99 nor its parent parity issues can close on these phase checks alone.
