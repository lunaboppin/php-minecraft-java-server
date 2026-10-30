# World generation

Vanilla terrain (`vanillaTerrain: true`) ports the official 26.3 generator for the Overworld, the Nether and the End. Generation workers (`bin/vanilla-worker.php`) carve, decorate and record chunks in `ChunkStore`. A chunk is final, and sent to players, once it and its eight neighbours are decorated and its generation spawns are stored. Issues #25, #41, #46, #47 and #49 track the remaining work.

## What is ported

- **Terrain:** the density functions and noise router, surface rules, aquifers, and carvers (caves, canyons, Nether caves).
- **Biomes:** climate sampling, the multi-noise biome source and `BiomeManager` zoom.
- **Features:** every configured feature type except `bonus_chest`, `end_podium` and `void_start_platform`, placed in the official per-step order.
- **Structures:** every structure set, with jigsaw pools and templates, terrain adaptation (the beardifier) and placement-time shape updates.
- **`/locate`:** structure and biome searches, with the same results as the official server for every target tried.
- **Entities:** template and structure-placed entities, and `NaturalSpawner.spawnMobsForChunkGeneration`. They are stored with each chunk and spawned when it is first sent; mobs are saved with their chunk while no player has it loaded.
- **Block entities:** loot tables and seeds are recorded per container. Rolling the loot when a container opens is #34.

## How it is compared

`tools/parity/worldgen_regions.py` runs the pinned official server on an area and decodes its saved chunks:
- blocks, biomes and structure starts;
- entities too, with `--entities`, which freezes ticking first so mobs stay where generation put them.

It needs an existing EULA acknowledgement (`--eula-file`).

`tools/parity/vanilla_decoration.php REGIONS.json SEED CX CZ` generates the 7×7 chunks around a centre in PHP, decorates the 5×5 inside them, and compares the 3×3 in the middle. Environment variables change the comparison:

| Variable | Effect |
| --- | --- |
| `FULL=1` | compares full block states |
| `TOP=n` | lists the n most common kinds of difference |
| `DIFFKIND=official<-php` | prints the positions of one kind of difference |
| `BOX=x0,y0,z0,x1,y1,z1` | limits the comparison to an area |
| `ENTITIES=1` | compares entities by type, position and exact yaw instead of blocks (shulkers by position only, because their yaw comes from an unseeded random) |

Example, for the End around the origin:

```bash
python3 tools/parity/worldgen_regions.py --seed 42 --structures --dimension the_end --from=-4:-4 --to=4:4 --eula-file EULA --output end.json
php tools/parity/vanilla_decoration.php end.json 42 0 0
```

## Seed 42 results

| Area | Result |
| --- | --- |
| End, chunks -4..4 by -4..4 (central island, obsidian pillars) | 81 of 81 chunks identical |
| End, void transition 60..64 by -2..2, and distant -70..-66 by 30..34 (outer islands, chorus) | 50 of 50 chunks identical |
| End city, chunks 64..68 by -40..-36 | blocks identical; 88 of 88 shulkers |
| Nether, -16..-10 by -3..3 (42 chunks) | 10 blocks differ, all where ore blobs overlap |
| Ocean monument | blocks match; 3 of 3 elder guardians |
| Woodland mansion (598 pieces) | blocks match except 2 edge cases; every illager and allay |
| Plains village | every villager, cat, golem and pen animal |
| Generation-time creatures | match by type, position and exact yaw in every area away from world spawn |

Two kinds of difference are vanilla's own, so PHP cannot match them exactly:
- **Order-dependent features:** where features overlap chunk borders, the result depends on which neighbouring chunk the official server decorates first, and that order depends on thread scheduling. Ore blobs, lush caves, dense dark oak and cherry forests, and dripstone are affected. Two official runs of the same seed differ from each other by about as much there.
- **World spawn area:** chunks near world spawn tick during the official server's startup, before the export can freeze them, so their mobs have already moved.

## Known gaps

- **Mob details:** spawn-time setup that `Mob.finalizeSpawn` decides, such as cat and sheep variants and villager professions, is not applied yet.
- **Entity types:** chest minecarts, item frames and cushions stay recorded until the server can spawn them.
- **Fluids:** flow after generation (#20) and loot rolls (#34) are separate work.
- **Light:** generation spawns compute their own light. Light occlusion by partial block shapes (slabs, stairs) is not modelled.
