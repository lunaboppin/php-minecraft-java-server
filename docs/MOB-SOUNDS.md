# Mob sound coverage

Primary hurt/death voices now use data exported from the pinned Java 26.3 sound getters. The reference includes all 90 supported mob species. The ordinary LivingEntity playback path covers 89 of them; the Ender Dragon requires its separate fight/death lifecycle under #52. Pitch uses the actual float32 arithmetic for LivingEntity, Bat, HappyGhast, Parrot and ZombieVillager. Event range, sound source and positional packet bytes are checked against official methods/codecs.

Cow, pig, chicken, cat and wolf sound variants are selected uniformly from their registered sound variants, synchronized through the correct entity metadata and saved with the mob. Existing saves without a voice selection keep the classic default. A voice selection survives restart. This does not reproduce the complete world/entity random stream shared with every other vanilla action; that broader deterministic scheduling/randomness work remains open.

The runtime separates pure voice/range calculations (`World/MobAudio.php`), packet encoding (`Protocol/Packets.php`) and dimension/range-aware playback (`Server/MobSounds.php`). Damage playback respects the shared hurt window and the mob's silent state. Players hear events according to the event's broadcast radius, independently of entity tracking.

`tools/ExportMobSounds.java` invokes the real sound getters on isolated probes. It initializes synchronized defaults using the actual subclass definitions, supplies the server-side flag and variant registries those getters read, and uses empty equipment. These probes are not operational worlds or entity lifecycle simulations. The committed independent TCP recording contains default adult NoAI mobs receiving generic command damage. It confirms 178 primary sound names, volumes and categories across the 89 ordinary species; incidental sounds remain recorded. The dragon's recording demonstrates why a default getter alone does not prove actual boss behavior.

`tests/mob-sounds.py` exercises real PHP and Java TCP connections for cow, pig, chicken, cat and bat voices, two listeners, weaker repeated hits, a distant listener and a saved voice after restart. Each event's exact identifier must match the mob's synchronized chosen variant. Fresh servers may choose different random variants and pitches; observations preserve those choices while comparisons check their validated phase, category, volume, counts, listener delivery and persistence. Raw packets retain all primary, ambient, secondary and player sounds for independent official-codec decoding.

```sh
php tests/mob-sounds-unit.php
JAVA_HOME=/path/to/jdk python3 tests/mob-sounds.py \
  --java-jar tools/.cache/26.3/server.jar \
  --eula-file /path/to/accepted/eula.txt \
  --output tools/.cache/parity-mob-sounds
JAVA_HOME=/path/to/jdk python3 tools/parity/verify.py \
  tools/.cache/parity-mob-sounds/php-packets.json \
  tools/.cache/parity-mob-sounds/java-packets.json
```

Rebuild getter/codec data with `tools/update_reference.py`. Re-record the full default roster with `tools/parity/mob_sounds.py`, an empty output directory, the pinned JAR and an existing accepted EULA file. The recorder freezes night/weather so sun damage cannot contaminate observations, checks the JAR checksum and preserves raw packets. The reference tools keep logs readable and never accept terms on the operator's behalf.

Issue #88 remains open. Ambient, step, swim, flight, attack and special-state sounds; underwater/land transitions; changing slime size, shulker state and species-specific reactions; secondary health/equipment sounds; complete baby/variant handling; and boss sequences still require implementation and dedicated comparisons. Default primary voices are a foundation for that work, not complete mob sound parity.
