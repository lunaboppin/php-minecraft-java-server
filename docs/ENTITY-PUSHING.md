# Native entity pushing

Issue [#105](https://github.com/lunaboppin/php-minecraft-java-server/issues/105) remains open. Its discovery capture retains the original Java/PHP divergence when a late player spawned beside a Giant. The modules below establish shared arithmetic, lookup and impulse iteration; they are not yet connected to live server entities.

`World/EntityPushing.php` ports the shared `LivingEntity.push(Entity)` and `Entity.push(Entity)` path, the reversed call in `LivingEntity.doPush`, the server-side team predicate and strict AABB intersection. Horizontal impulses use the square root of the maximum absolute horizontal separation, followed by the native inverse clamp and float-derived constants. They do not use ordinary Euclidean normalization. Non-finite impulses leave motion and synchronization flags unchanged. Sleeping, no-physics, same-root-vehicle, vehicle and pushability dependencies are explicit inputs.

`World/EntitySectionIndex.php` preserves native section ordering and insertion order within each section. Moving to another section removes and appends membership; updating bounds within the same section preserves order. Lookup uses the native two-block horizontal/four-block lower padding, accessible sections and strict intersections. Packed section keys remain signed 64-bit integers during sorting. Visibility is supplied by the caller; this class does not invent loaded/ticking chunk state.

`World/LivingEntityPushPhase.php` composes ordered lookup, caller exclusion, team filtering and shared impulse iteration. Per-species virtual dispatch, actual eligibility derivation, cramming effects, passenger-root discovery, network delivery and scheduling belong to the surrounding controllers and remain incomplete.

## Actual Java phase evidence

`ExportEntityPushing.java` invokes the actual inherited native methods on Giant probes. The probe overrides dependency accessors so every flag combination can be supplied independently; this tests the shared method bodies, not completed real-world derivation of those flags. The fixture includes:

- 40,960 push contexts: 2,048 flag combinations and 20 direction/IEEE-boundary inputs, with two consecutive calls.
- 2,048 additional contexts invoking the actual protected `LivingEntity.doPush` method twice.
- 152 actual team predicates and 405 actual AABB intersections.
- The actual method owners for all 90 mapped mob classes and the server player.

PHP checks exact position/velocity bits, synchronization flags, retained dependency flags, RNG tails, predicates and intersection results in 1,417,773 assertions. Identical snapshots are interned without dropping their fields. Every original unfactored push context was independently reconstructed and compared before retaining the compact representation. Independent final exports of the fixture and runtime ownership rules are byte-identical.

`ExportEntitySectionQueries.java` uses actual `EntitySectionStorage` and `EntitySection` operations. Its 857 explicit add/move/remove/visibility events produce 1,714 native ordered query results. Cases include negative coordinates, adjacent section boundaries, hidden/tracked/ticking visibility, removal/re-addition and positions near both horizontal world limits. These are storage-phase API comparisons; they do not claim that the full live entity-manager callback or chunk lifecycle has been implemented.

## Controlled world evidence

`tools/parity/entity_pushing.py` starts a checksum-pinned official 26.3 server using an existing accepted EULA file. It freezes the actual world, establishes exact player and Giant positions, then records 24 ticks for each of four cases: an outside player, an overlapping creative player, an overlapping spectator and two overlapping Giants. All initial states, complete player/mob snapshots and bidirectional packet traces are retained. Killed actors are fully removed before the next case.

`tests/entity-pushing-world-unit.php` composes the PHP modules with actual `World` storage and the verified `AirTravel` integrator. Its 840 comparisons check every mob position/velocity bit and ground flag across 96 native world ticks. The comparison includes each mob's post-travel contact pass and the later server-player contact pass. Player-owned motion/reset behavior is outside this PHP mob-state comparison; the complete native player observations remain in the fixture.

```sh
php tests/entity-pushing-unit.php
php tests/entity-section-unit.php
php tests/entity-pushing-world-unit.php
JAVA_HOME=/path/to/jdk25 python3 tools/update_reference.py
python3 tools/parity/entity_pushing.py --java-home /path/to/jdk25 \
  --eula-file /path/to/accepted/eula.txt --output tools/.cache/pushing-java \
  --compare reference/26.3/entity-pushing-trajectories.json
python3 tools/parity/verify.py --java-home /path/to/jdk25 tools/.cache/pushing-java/packets.json
```

An independent second server run reproduced the complete retained observations exactly. The actual Java codecs decoded all 3,156 packets from the first capture, including 62 complete chunks and 32 loaded registries.

The output directory must be empty. Logs remain readable and existing captures are not overwritten. CI retains the controlled world evidence separately from ordinary travel and the original multiplayer knockback test.

## Remaining implementation

Connect these phases to authoritative server entities at their native tick positions, maintaining membership at actual creation/move/remove and dimension events. Derive eligibility from real alive/spectator/climbing/ticking state, respect teams and passenger roots, and dispatch species-specific methods from the native ownership inventory. Cube mobs, shulkers, horses, bats, parrots, wardens, creakings and golems cannot all be treated as generic LivingEntity behavior. Complete native cramming rolls/damage and player-owned impulse delivery/reset behavior. Then verify established/late observers, separation, section/chunk/dimension changes, save/restore and the original collision reproduction. None of #105/#35/#86/#87 closes on the phase or four world cases alone.
