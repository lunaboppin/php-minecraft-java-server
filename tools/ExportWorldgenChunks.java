import java.lang.reflect.Method;
import java.nio.ByteBuffer;
import java.nio.file.*;
import java.util.*;
import com.google.gson.*;
import net.minecraft.core.Holder;
import net.minecraft.core.QuartPos;
import net.minecraft.core.registries.Registries;
import net.minecraft.world.level.ChunkPos;
import net.minecraft.world.level.LevelHeightAccessor;
import net.minecraft.world.level.biome.Biome;
import net.minecraft.world.level.biome.BiomeManager;
import net.minecraft.world.level.block.state.BlockState;
import net.minecraft.world.level.chunk.ChunkAccess;
import net.minecraft.world.level.chunk.PalettedContainerFactory;
import net.minecraft.world.level.chunk.ProtoChunk;
import net.minecraft.world.level.chunk.UpgradeData;
import net.minecraft.world.level.levelgen.*;
import net.minecraft.world.level.levelgen.blending.Blender;
import net.minecraft.world.level.levelgen.densityfunction.DensityVolume;
import net.minecraft.world.level.levelgen.material.rule.MaterialRule;
import net.minecraft.world.level.levelgen.presets.WorldPresets;

/**
 * Official Overworld chunks after each terrain stage: biomes, noise fill, material (surface)
 * rules and carvers, generated offline by the pinned server's own NoiseBasedChunkGenerator.
 * Structures are not placed, so chunks near structures with terrain adaptation differ from a
 * real world. Usage: output seed cx:cz [cx:cz ...]
 */
public class ExportWorldgenChunks {
    public static void main(String[] args) throws Exception {
        if (args.length < 3) throw new IllegalArgumentException("Expected output, seed and chunk positions");
        long seed = Long.parseLong(args[1]);
        net.minecraft.SharedConstants.tryDetectVersion();
        net.minecraft.server.Bootstrap.bootStrap();
        try (var data = ReferenceWorldData.load()) {
            var access = data.access();
            var stem = access.lookupOrThrow(Registries.WORLD_PRESET).getOrThrow(WorldPresets.NORMAL).value().overworld().orElseThrow();
            var generator = (NoiseBasedChunkGenerator) stem.generator();
            NoiseGeneratorSettings settings = generator.generatorSettings().value();
            RandomState randomState = RandomState.create(access.lookupOrThrow(Registries.NOISE), seed, settings);
            LevelHeightAccessor height = LevelHeightAccessor.create(settings.noiseSettings().minY(), settings.noiseSettings().height());
            PalettedContainerFactory containers = PalettedContainerFactory.create(access);
            Map<Long, ProtoChunk> biomeChunks = new HashMap<>();
            java.util.function.BiFunction<Integer, Integer, ProtoChunk> withBiomes = (x, z) -> biomeChunks.computeIfAbsent(ChunkPos.pack(x, z), key -> {
                var chunk = new ProtoChunk(new ChunkPos(x, z), UpgradeData.EMPTY, height, containers, null);
                generator.createBiomes(randomState, Blender.empty(), null, chunk).join();
                chunk.setPersistedStatus(net.minecraft.world.level.chunk.status.ChunkStatus.BIOMES);
                return chunk;
            });
            BiomeManager biomes = new BiomeManager(
                (qx, qy, qz) -> withBiomes.apply(QuartPos.toSection(qx), QuartPos.toSection(qz)).getNoiseBiome(qx, qy, qz),
                BiomeManager.obfuscateSeed(seed)
            );
            Method doFill = NoiseBasedChunkGenerator.class.getDeclaredMethod("doFill", NoiseChunk.class, ChunkAccess.class);
            Method buildSurface = NoiseBasedChunkGenerator.class.getDeclaredMethod("buildSurface", ChunkAccess.class, NoiseChunk.class, RandomState.class, BiomeManager.class, Set.class, MaterialRule.class);
            Method generateCarvers = NoiseBasedChunkGenerator.class.getDeclaredMethod("generateCarvers", ChunkAccess.class, Blender.class, NoiseChunk.class, RandomState.class, BiomeManager.class, net.minecraft.server.level.WorldGenRegion.class, MaterialRule.class);
            var picker = NoiseBasedChunkGenerator.class.getDeclaredField("globalFluidPicker");
            for (var m : List.of(doFill, buildSurface, generateCarvers)) m.setAccessible(true);
            picker.setAccessible(true);
            var fluidPicker = (Aquifer.FluidPicker) ((java.util.function.Supplier<?>) picker.get(generator)).get();
            MaterialRule rule = settings.materialRule().value();

            var root = new JsonObject();
            root.addProperty("version", net.minecraft.SharedConstants.getCurrentVersion().id());
            root.addProperty("seed", Long.toString(seed));
            var chunks = new JsonArray();
            for (int i = 2; i < args.length; ++i) {
                String[] parts = args[i].split(":");
                int cx = Integer.parseInt(parts[0]), cz = Integer.parseInt(parts[1]);
                Set<Holder<Biome>> possible = new HashSet<>();
                for (int dz = -1; dz <= 1; ++dz) for (int dx = -1; dx <= 1; ++dx) withBiomes.apply(cx + dx, cz + dz).collectBiomesInPalette(possible);
                ProtoChunk chunk = new ProtoChunk(new ChunkPos(cx, cz), UpgradeData.EMPTY, height, containers, null);
                generator.createBiomes(randomState, Blender.empty(), null, chunk).join();
                chunk.setPersistedStatus(net.minecraft.world.level.chunk.status.ChunkStatus.BIOMES);
                var row = new JsonObject();
                row.addProperty("x", cx);
                row.addProperty("z", cz);
                row.add("biomes", biomes(chunk, height));
                if (Boolean.getBoolean("climate")) {
                    var climateVolume = new DensityVolume(4, height.getHeight() / 4, 4, cx * 16, height.getMinY(), cz * 16, 4, 4, 4);
                    var samplers = randomState.samplersWithContext(net.minecraft.world.level.levelgen.densityfunction.SamplerContext.builder().enableCaches().build());
                    var router = settings.noiseRouter();
                    var climate = new JsonObject();
                    var functions = new net.minecraft.world.level.levelgen.densityfunction.DensityFunction[]{router.temperature(), router.vegetation(), router.continents(), router.erosion(), router.depth(), router.ridges()};
                    String[] names = {"temperature", "vegetation", "continents", "erosion", "depth", "ridges"};
                    for (int f = 0; f < functions.length; ++f) {
                        var bits = ByteBuffer.allocate(climateVolume.size() * 4);
                        try (var buffer = samplers.get(functions[f]).sampleVolume(climateVolume)) {
                            for (int j = 0; j < climateVolume.size(); ++j) bits.putFloat(buffer.get(j));
                        }
                        climate.addProperty(names[f], Base64.getEncoder().encodeToString(bits.array()));
                    }
                    row.add("climate", climate);
                }
                var pos = chunk.getPos();
                var volume = new DensityVolume(16, height.getHeight(), 16, pos.getMinBlockX(), height.getMinY(), pos.getMinBlockZ());
                try (NoiseChunk noise = new NoiseChunk(randomState, null, settings, fluidPicker, Blender.empty(), volume)) {
                    if (Boolean.getBoolean("density")) {
                        var bits = ByteBuffer.allocate(volume.size() * 4);
                        var finalDensity = noise.cachingSamplers().get(settings.noiseRouter().finalDensity());
                        try (var buffer = finalDensity.sampleVolume(volume)) {
                            for (int j = 0; j < volume.size(); ++j) bits.putFloat(buffer.get(j));
                        }
                        row.addProperty("density", Base64.getEncoder().encodeToString(bits.array()));
                    }
                    doFill.invoke(generator, noise, chunk);
                    row.add("noise", blocks(chunk, height));
                    buildSurface.invoke(generator, chunk, noise, randomState, biomes, possible, rule);
                    row.add("surface", blocks(chunk, height));
                    generateCarvers.invoke(generator, chunk, Blender.empty(), noise, randomState, biomes, null, rule);
                    row.add("carvers", blocks(chunk, height));
                }
                chunks.add(row);
            }
            root.add("chunks", chunks);
            Files.writeString(Path.of(args[0]), new Gson().toJson(root) + "\n");
        }
    }

    /** Quart biomes in x, z, y order from the chunk's minimum quart. */
    private static JsonObject biomes(ChunkAccess chunk, LevelHeightAccessor height) {
        var palette = new ArrayList<String>();
        var index = new HashMap<String, Integer>();
        var values = ByteBuffer.allocate(16 * (height.getHeight() / 4) * 2);
        var pos = chunk.getPos();
        for (int qy = 0; qy < height.getHeight() / 4; ++qy) for (int qz = 0; qz < 4; ++qz) for (int qx = 0; qx < 4; ++qx) {
            String name = chunk.getNoiseBiome(QuartPos.fromBlock(pos.getMinBlockX()) + qx, QuartPos.fromBlock(height.getMinY()) + qy, QuartPos.fromBlock(pos.getMinBlockZ()) + qz).unwrapKey().orElseThrow().identifier().toString();
            values.putShort((short) (int) index.computeIfAbsent(name, key -> { palette.add(key); return palette.size() - 1; }));
        }
        return encode(palette, values);
    }

    /** Block states, y then z then x, as palette indices. */
    private static JsonObject blocks(ChunkAccess chunk, LevelHeightAccessor height) {
        var palette = new ArrayList<String>();
        var index = new HashMap<BlockState, Integer>();
        var values = ByteBuffer.allocate(height.getHeight() * 256 * 2);
        var pos = new net.minecraft.core.BlockPos.MutableBlockPos();
        var base = chunk.getPos();
        for (int y = height.getMinY(); y < height.getMaxY() + 1; ++y) for (int z = 0; z < 16; ++z) for (int x = 0; x < 16; ++x) {
            BlockState state = chunk.getBlockState(pos.set(base.getMinBlockX() + x, y, base.getMinBlockZ() + z));
            values.putShort((short) (int) index.computeIfAbsent(state, key -> { palette.add(key.toString().replace("Block{", "").replace("}", "")); return palette.size() - 1; }));
        }
        return encode(palette, values);
    }

    private static JsonObject encode(List<String> palette, ByteBuffer values) {
        var out = new JsonObject();
        var names = new JsonArray();
        palette.forEach(names::add);
        out.add("palette", names);
        out.addProperty("data", Base64.getEncoder().encodeToString(values.array()));
        return out;
    }
}
