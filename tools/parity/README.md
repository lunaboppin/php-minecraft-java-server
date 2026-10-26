# Vanilla comparison harness

The runtime remains native PHP. Java is an isolated test oracle, pinned to the server SHA-1 in `reference/26.3/version.json` (Minecraft 26.3, protocol 777).

Requires Python 3, the project's patched PHP CLI, and a Java 25+ JDK for vanilla comparisons. Rebuild reference tools first if the pinned JAR is absent:

```sh
JAVA_HOME=/path/to/jdk25 python3 tools/update_reference.py
python3 tests/parity/harness.py
python3 tools/parity/run.py --self-test --output tools/.cache/parity-self
```

The self-test compares two independent PHP processes and saves. It tests harness mechanics; it provides no evidence of vanilla parity.

For an official comparison, supply your existing acknowledgement of the [Minecraft EULA](https://www.minecraft.net/en-us/eula). The harness copies that file into the isolated reference directory. It does not accept terms on your behalf.

```sh
JAVA_HOME=/path/to/jdk25 python3 tools/parity/run.py \
  --eula-file /path/to/accepted/eula.txt --output tools/.cache/parity-smoke
JAVA_HOME=/path/to/jdk25 python3 tools/parity/run.py \
  --scenario tests/parity/lifecycle.json \
  --eula-file /path/to/accepted/eula.txt --output tools/.cache/parity-lifecycle
JAVA_HOME=/path/to/jdk25 python3 tools/parity/verify.py \
  tools/.cache/parity-smoke/php-packets.json \
  tools/.cache/parity-smoke/reference-packets.json
```

Each output directory must be empty. Both processes bind only to loopback on unused ports. Each owns separate world files, logs and configuration; the hosted server and its saves are never used. Restart steps retain those test saves. Process shutdown is attempted even after startup or scenario failure. The codec verifier extracts the checksum-pinned JAR into scratch space and leaves production reference files unchanged.

PHP and Java reference logs stay readable plain text. The server launcher, reference exporters and codec verifier derive Java's Log4j configuration from the pinned distribution and changes its archive suffix from `.log.gz` to `.log`; restart/daily rotation does not compress them.

`report.json` contains both checkpoint sequences and the first differing JSON path, reference value and PHP value, or an execution error. `*-packets.json` retains uncompressed packet bodies in both directions, protocol state, scenario step and dimension. `scenario.json`, process logs and test world files remain beside the report. Do not publish a test directory containing private player data. These scenarios use a synthetic offline account.

The Java verifier decodes every retained packet with official codecs and rejects trailing bytes. It also checks complete chunk sections, biome resolution, heightmaps, registry loading and light masks. Serverbound decoding currently uses a creative/operator context, matching the bundled scenarios. It is not a general survival packet oracle.

## Coverage and limits

`smoke.json` checks login, registry/chunk delivery, creative mining, block edits, inventory, 2×2 crafting, a summoned entity and save/restart. `lifecycle.json` checks actual item placement, death/respawn, Nether portal travel and dimension persistence after restart. `placement.json` exercises obsidian placement with float rounding outside [0,1], the official block-centre bounds, rejected out-of-range/non-finite hits, and continued connection usability. `music-redstone.json` checks note sound/tuning, music-disc start/stop and a flat dust/lamp circuit. `water.json` checks falling, sideways decay, draining and two-source regeneration on full-block floors. Inventory checkpoints request a no-op full menu resynchronization so omitted prediction-matching cursor updates cannot leave stale observations. The scenario files enumerate normalizations. Absent damage and explicit zero damage are equivalent. Generated entity IDs are omitted from entity-type observations. `settings.json` compares skin-layer and main-hand updates against vanilla. Commands and inventory clicks wait for the simulation clock; mining and placement wait for sequence acknowledgements. Vanilla handles pings on the network thread, so ping replies alone cannot order gameplay actions. Game-clock barriers allow vanilla's tick-end block broadcasts to arrive before comparison.

`equipment.json` connects a second observer and compares all six equipment slots, hotbar selection, main/offhand swaps, removals and save/restart. Missing equipment slots start empty; vanilla's hand-swap event is applied to the observed slot state. Native multiplayer tests additionally exercise dimension isolation/re-entry, late joins and damaged saved tools.

`tracking.json` compares circular horizontal range boundaries, viewer unload/re-entry, standing/crouching metadata, sprint command semantics and spectator visibility with a second client. It uses explicit player teleports to test tracking independently of authoritative collision/movement. Native tests additionally exercise supported mobs/items, repeated dimension travel and reconnect. Full spectator camera, riding, swimming/fall-flight poses and collision-constrained crawling remain outside this scenario. Multiplayer comparisons run in a separate CI job from the six existing single-player scenarios.

`spawn-eggs.json` uses normal difficulty and compares initial entity type/position and inventory for ground, flying, hostile, golem and source-fluid eggs, slab placement, empty-air use and survival consumption. Both servers disable natural spawning with `minecraft:spawn_mobs false` before egg use; unexpected new entities remain in the comparison. Re-tracking the same UUID after it crosses a visibility boundary retains its initial position rather than counting another creation; full pairing packets remain in the capture. Each checkpoint copies its observations so later packets cannot change earlier results. UUIDs, IDs and random yaw are omitted. Native tests exercise all 88 egg mappings, multiplayer/restart, persisted spawning rules, Dragon part-ID reservation and peaceful-mode rejection. These checks do not establish species AI, randomized finalize-spawn state, equipment/enchantments, egg-on-mob offspring, spawner editing or boss-fight parity. This comparison has its own CI job.

`authentication.py` separately compares encrypted rejection of an unjoined session using both servers' real verification path. Captures contain decrypted packet bodies for official-codec validation. Positive verified-account behavior still needs real-account evidence; native fixture-verifier tests are described in [authentication coverage](../../docs/authentication.md).

These are semantic checkpoint comparisons plus independent packet validation. They do **not** assert identical packet ordering, compare every packet field, prove exact terrain generation, test full item components, or cover every multiplayer/entity lifecycle. Issue #2 remains open until those broader comparisons are delivered. A passing scenario establishes only its named observations.

CI always runs harness unit checks and PHP replay. Official comparisons additionally require the repository Actions variable `MINECRAFT_EULA_ACCEPTED=true`, set only after the repository owner accepts the EULA for these tests. The workflow retains diagnostic artifacts for seven days, including failures.
