# Mob spawn initialization

Issue #99 remains in progress. Spawning an entity is not complete initialization: native zombie families can become babies, gain persistent attribute modifiers, receive equipment, attach to an existing chicken or create a new mount. Their resulting movement, metadata and passenger relationships must survive tracking changes and restart.

The new `World/Spawning/` modules provide the attribute and difficulty phases for that pipeline. `MobInitialization` consumes the shared world random stream for the native triangular follow-range bonus and handedness. `ZombieAttributes` uses a separate entity random stream for reinforcement chance, knockback resistance, follow range and leader bonuses. It also applies/removes the transient baby speed modifier. Repeated initialization retains or replaces modifiers by their actual identifiers; it does not flatten their effect into base values. Conversion, load and dimension-travel reasons preserve current health when the leader branch changes maximum health.

`SpawnAttributes` retains bases, modifier identifiers, operations and permanence. Operation buckets use `ModifierMap`, matching the native identifier hash, linear probing, growth/shrink behavior and reverse table iteration. That order matters for exact floating-point accumulation when multiple modifiers affect one attribute. This implements the mutation operations used by initialization; it does not claim complete attribute command, equipment or effect lifecycle behavior.

`RegionalDifficulty` accepts difficulty, world time, actual chunk inhabited time and moon brightness. It rounds at the same float boundaries as Java. `Float32::fromLong` performs direct long-to-float rounding; going through PHP's double first can round large integers twice. These modules do not invent inhabited-time values or replace the outstanding chunk simulation work.

## Official reference evidence

`ExportMobInitialization.java` invokes actual `DifficultyInstance`, `AttributeInstance` and fastutil map methods. For the five zombie families it directly invokes the shared `Mob.finalizeSpawn` phase, actual `setBaby` and actual `Zombie.handleAttributes`. The probe bypasses virtual dispatch only to isolate the shared base phase; it does not replace that phase with a hand-written oracle. Two consecutive rounds test existing modifier retention/replacement and an age change, with exact independent RNG tails.

The pinned fixture contains 983 direct long casts, 1,764 regional difficulty contexts, 320 modifier-map mutations, 1,600 attribute mutation frames and 3,840 zombie attribute-phase frames across 1,920 contexts. These include all five families, both ages, three local difficulty multipliers and four spawn reasons. PHP compares every attribute base, effective value, modifier identifier/operation/amount/permanence, handedness, health and final RNG state. The 24 leader frames exercise the health and modifier branches.

Repeated initial states and unchanged attributes are factored into shared defaults for storage. The verifier reconstructs every complete state. The exporter rejects unexpected default variation or attribute roster changes rather than discarding them.

`ReferenceWorldData.java` uses the actual vanilla `WorldLoader` and data packs, including real registry tags and component initialization. Construction-only registry lookups leave enchantment tag references unresolved and cannot substitute for this context. The isolated level still supplies a controlled plains biome, difficulty, feature flags and minimal debug server; this is phase evidence, not full-world AI or vehicle lifecycle proof.

```sh
php tests/spawn-initialization-unit.php
JAVA_HOME=/path/to/jdk25 python3 tools/update_reference.py
```

## Work required before completing #99

The live spawn coordinator still needs the group/baby decisions, full equipment/enchantment initialization and correct chicken initialization before applying the attribute phase. Passenger movement and metadata must be synchronized, and relationships must restore by persistent identity through tracking, dimension changes and restart. Damage, sound, dismount/death and real-client behavior need official full-world comparisons. The unseeded egg comparison must retain legitimate additional entities and explain stochastic differences without masking an unimplemented branch. Neither #99 nor its parent parity issues can close on the attribute-phase checks alone.
