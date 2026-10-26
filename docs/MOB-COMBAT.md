# Mob melee coverage

Ordinary main-hand melee attacks against mobs now use the default item attribute components exported from the pinned Java 26.3 server. This covers 44 item-specific damage/speed/minimum-charge/durability configurations plus the default empty-hand values. Charge and armor calculations round at Java float boundaries.

The target's damage cooldown is shared between attackers: equal/weaker hits during the first ten ticks are ignored, stronger hits apply only the difference, and those stronger hits do not restart the window. Applied damage uses the target's exported base armor/toughness. Health and the current `damage_event` packet reach tracking observers; death is emitted once, and survival/adventure weapon wear comes from the item's weapon component. Switching item types resets charge. Hurt cooldown timestamps are discarded when restoring saved mobs so the old server tick clock cannot make them invulnerable after restart.

`tools/ExportMobCombat.java` invokes actual classes in the checksum-pinned JAR. Its isolated player arithmetic oracle initializes only the fields read by the actual charge methods; it does not represent a player session or test the full attack routine. The fixture contains 914 exact comparisons of charge/minimum-charge results, unenchanted armor results and damage/hurt-animation codec bytes. The game runtime remains PHP.

`tests/mob-combat.py` uses actual TCP clients and fresh isolated saves. It compares empty-hand, sword, axe, trident and mace base hits, survival wear, two observers' health/damage events, a shared target hit by two players, base zombie armor, and saved health after restart. The armor case uses creative attackers to keep that incoming-damage check independent of hostile AI. Both servers run the same commands and client actions. Timing is fenced on the simulation clock, and captures are retained for independent decoding with the official packet codecs.

Run the native scenario with `python3 tests/mob-combat.py`. For comparison against Java, first prepare the pinned reference and supply an existing accepted EULA file:

```sh
JAVA_HOME=/path/to/jdk python3 tests/mob-combat.py \
  --java-jar tools/.cache/26.3/server.jar \
  --eula-file /path/to/accepted/eula.txt \
  --output tools/.cache/parity-mob-combat
JAVA_HOME=/path/to/jdk python3 tools/parity/verify.py \
  tools/.cache/parity-mob-combat/php-packets.json \
  tools/.cache/parity-mob-combat/java-packets.json
```

The output directory must be empty. The tool verifies the JAR checksum and never accepts the EULA on the operator's behalf.

This does not complete #87 or full combat parity. Knockback/physics, critical and sweeping/special attacks, enchantments/effects, shields/absorption, equipment-derived armor, species immunities and reactions, environmental damage, complete loot/XP, hostile attacks and all species/boss states still require implementation and their own comparisons. Ordinary target damage is a shared foundation for the full mob roster, not proof that every mob works.
