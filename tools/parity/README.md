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

`report.json` contains both checkpoint sequences and the first differing JSON path, reference value and PHP value, or an execution error. `*-packets.json` retains uncompressed packet bodies in both directions, protocol state, scenario step and dimension. `scenario.json`, process logs and test world files remain beside the report. Do not publish a test directory containing private player data. These scenarios use a synthetic offline account.

The Java verifier decodes every retained packet with official codecs and rejects trailing bytes. It also checks complete chunk sections, biome resolution, heightmaps, registry loading and light masks. Serverbound decoding currently uses a creative/operator context, matching the bundled scenarios. It is not a general survival packet oracle.

## Coverage and limits

`smoke.json` checks login, registry/chunk delivery, creative mining, block edits, inventory, 2×2 crafting, a summoned entity and save/restart. `lifecycle.json` checks actual item placement, death/respawn, Nether portal travel and dimension persistence after restart. `placement.json` exercises obsidian placement with float rounding outside [0,1], the official block-centre bounds, rejected out-of-range/non-finite hits, and continued connection usability. The scenario files enumerate normalizations. Absent damage and explicit zero damage are equivalent. Generated entity IDs are omitted from entity-type observations. Game-clock barriers allow vanilla's tick-end block broadcasts to arrive before comparison.

These are semantic checkpoint comparisons plus independent packet validation. They do **not** assert identical packet ordering, compare every packet field, prove exact terrain generation, test full item components, or cover multiplayer and every entity lifecycle. Issue #2 remains open until those broader comparisons are delivered. A passing scenario establishes only its named observations.

CI always runs harness unit checks and PHP replay. Official comparisons additionally require the repository Actions variable `MINECRAFT_EULA_ACCEPTED=true`, set only after the repository owner accepts the EULA for these tests. The workflow retains diagnostic artifacts for seven days, including failures.
