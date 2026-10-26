# Ordinary mob movement tracking

The ordinary unmounted mob path uses the pinned Java 26.3 tracker's packet-selection rules. `Server/Tracking/EntityMovementTracker.php` owns the entity-local update counter, movement/rotation caches, velocity flags and forced synchronization. `SteppedPositionTracker.php` collects intermediate forced steps. `Protocol/PositionPath.php` encodes absolute paths, and `RelativePositionCodec.php` quantizes separately rounded coordinates into relative components.

Normal position updates use `move_entity_pos` or `move_entity_pos_rot`. Ground changes, precision requirements, oversized deltas and periodic synchronization use `entity_position_sync`. A protocol-777 relative movement header combines grounded state and step count; stepped offsets include their tick spans. Rotation-only packets put grounded state before angle bytes. These layouts are generated and checked against the actual official codecs.

New viewers receive the tracker's cached position base, last sent movement and encoded rotations. Using a mob's newer, unsent position as the pairing base would make the next relative delta move that viewer to the wrong position. Removing a mob releases its tracker, and switching to the existing special-travel fallback clears the ordinary tracker before it can be reused with a different base.

## Evidence

`tools/ExportMobTracking.java` calls the actual `ServerEntity.sendChanges` and actual `ClientboundAddEntityPacket(entity, tracker)` constructor on initialized, isolated Giant state. It does not reimplement the tracker to generate expected output. The server-level shell is sufficient for these movement calls; this probe does not run a full world or prove AI, effect or lifecycle behavior.

The fixture includes 14 scenarios and 4,078 frames: long stationary runs through periodic full synchronization, walking/rotation, quantization and large-delta boundaries, angle boundaries, intervals 1/2/3/10/60, delta tracking enabled/disabled, force-step flags, hurt-velocity flags and predicted external movement. All 3,417 ordered packets, delivery classifications and post-update flags match the PHP tracker exactly. Pairing packets also match at every frame.

A separate Python decoder is checked against Java's actual `VecDelta.decode` and absolute position paths. Its 8,156 checks compare reconstructed existing-viewer positions and late-pairing bases bit for bit. Thus the independent TCP observer can recognize the new packet layouts without assuming the PHP encoder is correct.

```sh
php tests/mob-tracking-unit.php
python3 tests/parity/entity-motion.py
```

`tests/mob-knockback.py` exercises the integration with two established TCP clients and a third joining after the hit. It compares exact settled server state, both established clients' reconstructed position and compressed velocity sequences against an actual official server. Within each run it correlates matching position updates for the established and late viewer and allows only the protocol's 1/4096-block quantization difference. The raw vectors, packets and number of common updates checked remain in the evidence. Unseeded wall-clock timing is not treated as identical tick alignment between the two processes.

The first three-client comparison passed against the pinned official server, with six unique common late-viewer updates checked in each process. Full packet captures are independently decoded by the official codecs. The ordinary movement foundation and its actual-world trajectory tests are documented in [MOB-MOVEMENT.md](MOB-MOVEMENT.md).

## Remaining acceptance scope

This work covers the ordinary unmounted movement path integrated for the existing 63 mapped ground/air profiles outside fluids. It does not complete #13/#86: species navigation and look controllers, passengers/vehicles, item-specific precision, flying/swimming/fluid paths, metadata/equipment lifecycle ordering, entity pushing, all visibility transitions and full native simulation/chunk tracking still need implementation or broader verification. Existing special-travel behavior remains a separate fallback. These issues must stay open until their complete acceptance criteria are met.
