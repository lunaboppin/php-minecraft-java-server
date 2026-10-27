# Native entity pushing

Issue [#105](https://github.com/lunaboppin/php-minecraft-java-server/issues/105) remains open. Its discovery capture retains the original Java/PHP divergence when a late player spawned beside a Giant. The modules below establish shared arithmetic, lookup, eligibility and impulse iteration. They are now connected to live server mobs and players for the shared `LivingEntity` contact path; the species controllers, cramming and player-owned impulse delivery listed under [remaining implementation](#remaining-implementation) are not.

`World/EntityPushing.php` ports the shared `LivingEntity.push(Entity)` and `Entity.push(Entity)` path, the reversed call in `LivingEntity.doPush`, the server-side team predicate and strict AABB intersection. Horizontal impulses use the square root of the maximum absolute horizontal separation, followed by the native inverse clamp and float-derived constants. They do not use ordinary Euclidean normalization. Non-finite impulses leave motion and synchronization flags unchanged. Sleeping, no-physics, same-root-vehicle, vehicle and pushability dependencies are explicit inputs.

`World/EntitySectionIndex.php` preserves native section ordering and insertion order within each section. Moving to another section removes and appends membership; updating bounds within the same section preserves order. Lookup uses the native two-block horizontal/four-block lower padding, accessible sections and strict intersections. Packed section keys remain signed 64-bit integers during sorting. Visibility is supplied by the caller; this class does not invent loaded/ticking chunk state.

`World/LivingEntityPushPhase.php` composes ordered lookup, caller exclusion, team filtering and shared impulse iteration. An optional dispatch closure lets the live adapter substitute species contact behavior, and the phase returns the IDs touched by eligible contacts. Eligibility derivation and live membership are described below; cramming effects, passenger-root discovery, player impulse delivery and native scheduling remain incomplete.

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

## Native eligibility

`tools/ExportPushEligibility.java` uses the real registries, tags and controlled `ServerLevel` probes. It records 87,574 shared contexts, covering every one of the 35,723 block states, climbable and gliding behavior, trapdoor/ladder facing and side effects. It also records 960 virtual actor contexts and 96 actual virtual push contexts for species that override `isPushable`/`push`. `World/EntityPushEligibility.php` and one rule class per native owner in `World/Pushing/Eligibility/` reproduce them, selected through `World/Pushing/EligibilityRegistry.php`. Verified differences are kept: Parrot is pushable but does not push players, Bat is never pushable, a Horse is not pushable while it is a vehicle, and Shulker's `push(Entity)` is a no-op for both bodies. `tests/push-eligibility-unit.php` makes 355,288 exact checks. An independent second export was byte-identical to the committed fixture and runtime rules, and both are covered by `reference/26.3/checksums.json`.

## Live integration

`Server/MobPushing.php` and `Server/Pushing/WorldPushContext.php` maintain one section index per dimension. Membership is updated on spawn, restore, movement, teleport, removal, dimension change and disconnect. Following the native phase order, each ticking mob pushes neighbors immediately after its own travel, and each player's contacts run after the world tick. Ordinary mob tracking changes are sent before world entity physics. `World/Pushing/SharedPushDispatch.php` dispatches only the shared `LivingEntity` and Parrot source paths. Entities whose velocity setter ignores impulses still set the synchronization flag.

Integration exposed a persistence bug: periodic autosave ran after world ticks but before player contacts, saving half of a completed tick's impulse (0.05 instead of 0.1). Autosave now runs at the end of `Server::tick()`, after the client loop, matching native `MinecraftServer` order.

`tests/mob-pushing-backend.php` drives the actual `Server` spawn and tick methods with persistent worlds through all four controlled world cases and compares 875 exact native fields, including state persisted after a completed tick. `tests/entity-pushing-network.py` runs both PHP and the official 26.3 server with Actor, Observer and Late TCP clients. For `outside`, `player-wall`, `spectator-wall` and `two-giants` it compares stable authoritative mob position, velocity and ground state, plus tracking seen by established and late clients, using controlled wall fixtures and exact late-player placement. All four cases pass. The official codecs decoded every packet from all eight resulting captures (6,497 packets, 1,018 complete chunks). This does not prove equality of every timing-dependent packet stream or of player movement semantics.

```sh
php tests/push-eligibility-unit.php
php tests/mob-pushing-backend.php
python3 tests/entity-pushing-network.py --java-jar tools/.cache/26.3/server.jar \
  --java-home /path/to/jdk25 --eula-file /path/to/accepted/eula.txt --output tools/.cache/collision-network
python3 tools/parity/verify.py --java-home /path/to/jdk25 \
  tools/.cache/collision-network/*-php/packets.json tools/.cache/collision-network/*-java/packets.json
```

## Remaining implementation

- Iron Golem, Warden and Sulfur Cube source contact controllers are not enabled; they currently do not push neighbors. Cube-mob damage on golem contact belongs to those controllers.
- Native cramming rolls and damage are incomplete.
- Player-owned impulses are computed on the server but not yet delivered to the client or reset with native player lifecycle.
- Passengers and baby/adult ages are not coordinated with push roots and dimensions.
- Older swimming and flying locomotion controllers can lose horizontal impulses.
- Mob ticking uses the existing 80-block player-distance approximation, not native chunk tickets and simulation distance (#8).

None of #105/#35/#86/#87 closes on the current scope.
