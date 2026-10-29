# Ordinary mob movement tracking

The ordinary unmounted mob path uses the pinned Java 26.3 tracker's packet-selection rules. `Server/Tracking/EntityMovementTracker.php` owns the entity-local update counter, movement/rotation caches, velocity flags and forced synchronization. `SteppedPositionTracker.php` collects intermediate forced steps. `Protocol/PositionPath.php` encodes absolute paths, and `RelativePositionCodec.php` quantizes separately rounded coordinates into relative components.

Normal position updates use `move_entity_pos` or `move_entity_pos_rot`. Ground changes, precision requirements, oversized deltas and periodic synchronization use `entity_position_sync`. A protocol-777 relative movement header combines grounded state and step count; stepped offsets include their tick spans. Rotation-only packets put grounded state before angle bytes. These layouts are generated and checked against the actual official codecs.

New viewers receive the tracker's cached position base, last sent movement and encoded rotations. Using a mob's newer, unsent position as the pairing base would make the next relative delta move that viewer to the wrong position. Removing a mob releases its tracker, and switching to the existing special-travel fallback clears the ordinary tracker before it can be reused with a different base.

The reference profiles also identify actual class overrides. Shulkers use no-op interpolation and always report zero velocity, so ordinary travel and impulses cannot move them. Their teleport/attachment and other AI behavior still need separate work. Emerging wardens receive the native spawn-data flag; that flag does not implement their broader emergence/AI lifecycle.

## Evidence

`tools/ExportMobTracking.java` calls the actual `ServerEntity.sendChanges` and each entity’s actual `getAddEntityPacket(tracker)` method on initialized, isolated Giant, Shulker and Warden state. It does not reimplement the tracker to generate expected output. The server-level shell is sufficient for these movement calls; this probe does not run a full world or prove AI, effect or lifecycle behavior.

The fixture includes 20 scenarios and 5,856 frames: long stationary runs through periodic full synchronization, walking/rotation, quantization and large-delta boundaries, angle boundaries, intervals 1/2/3/10/60, delta tracking enabled/disabled, force-step flags, hurt-velocity flags, predicted external movement, signed tick-counter overflow, shulker no-op interpolation and warden standing/emerging spawn data. All 4,017 ordered packets, delivery classifications and post-update flags match the PHP tracker exactly. Pairing packets also match at every frame.

A separate Python decoder is checked against Java's actual `VecDelta.decode` and absolute position paths. Its 11,712 checks compare reconstructed existing-viewer positions and late-pairing bases bit for bit. Thus the independent TCP observer can recognize the new packet layouts without assuming the PHP encoder is correct.

```sh
php tests/mob-tracking-unit.php
python3 tests/parity/entity-motion.py
```

`tests/mob-knockback.py` exercises the integration with two established TCP clients and a third joining after the hit. It compares exact settled server state, both established clients' reconstructed position and compressed velocity sequences against an actual official server. Within each run it correlates matching position updates for the established and late viewer and allows only the protocol's 1/4096-block quantization difference. The raw vectors, packets and number of common updates checked remain in the evidence. Unseeded wall-clock timing is not treated as identical tick alignment between the two processes.

The three-client comparison passed against the pinned official server, with six unique common late-viewer updates checked in each process. The extended comparison also verifies that a supported idle shulker retains its position and zero velocity. Full packet captures are independently decoded by the official codecs. The ordinary movement foundation and its actual-world trajectory tests are documented in [MOB-MOVEMENT.md](MOB-MOVEMENT.md).

## Players

Players are tracked by the same `EntityMovementTracker`, with the player type's profile from `movement.json` (`player_tracking`: every second tick, no velocity). Pairing follows `ServerEntity.sendPairingData`. It sends `add_entity` at the tracker's last synced position, then only non-default entity data, the synchronized attributes, and equipment for occupied slots.

The ground flag in movement packets follows vanilla's server-side state. A confirmed teleport takes the player off the ground. Each tick, vanilla's own travel step moves the player by their server-side vertical movement before snapping them back to the client's position, which puts them on the ground again over a floor. A jump starts that movement at 0.42.

`tests/player-motion.py` makes an observer watch a player walk, turn, move nine blocks at once, teleport, leave range and come back, and compares the observer's packets with the official server byte for byte. Two things in vanilla depend on timing, so the test controls for them:
- The 60-tick zero-delta heartbeat is timed from when the tracker last ran, and vanilla skips players whose chunk is not yet entity-ticking. The test leaves zero-delta heartbeats out.
- Vanilla handles packets at the start of a tick, while PHP handles them as they arrive. The moving client therefore confirms a teleport and reports its position in one write, as a real client does in one tick.

## Remaining acceptance scope

This work covers the ordinary unmounted movement path integrated for the existing 63 mapped ground/air profiles outside fluids. It does not complete #13/#86: species navigation and look controllers, passengers/vehicles, item-specific precision, flying/swimming/fluid paths, metadata/equipment lifecycle ordering, entity pushing, all visibility transitions and full native simulation/chunk tracking still need implementation or broader verification. Existing special-travel behavior remains a separate fallback. These issues must stay open until their complete acceptance criteria are met.
