import java.nio.file.*;
import java.util.List;
import java.util.function.Supplier;
import com.google.gson.*;
import net.minecraft.core.registries.Registries;
import net.minecraft.world.level.biome.FeatureSorter;
import net.minecraft.world.level.chunk.ChunkGenerator;
import net.minecraft.world.level.levelgen.presets.WorldPresets;

/**
 * The Overworld generator's global placed-feature order for each decoration step
 * (FeatureSorter). A feature's index in its step seeds its random source.
 */
public class ExportFeatureOrder {
    public static void main(String[] args) throws Exception {
        if (args.length != 1) throw new IllegalArgumentException("Expected output path");
        net.minecraft.SharedConstants.tryDetectVersion();
        net.minecraft.server.Bootstrap.bootStrap();
        try (var data = ReferenceWorldData.load()) {
            var access = data.access();
            var generator = access.lookupOrThrow(Registries.WORLD_PRESET).getOrThrow(WorldPresets.NORMAL).value().overworld().orElseThrow().generator();
            var field = ChunkGenerator.class.getDeclaredField("featuresPerStep");
            field.setAccessible(true);
            var steps = (List<FeatureSorter.StepFeatureData>) ((Supplier<?>) field.get(generator)).get();
            var placed = access.lookupOrThrow(Registries.PLACED_FEATURE);
            var root = new JsonObject();
            root.addProperty("version", net.minecraft.SharedConstants.getCurrentVersion().id());
            var out = new JsonArray();
            for (var step : steps) {
                var names = new JsonArray();
                for (var feature : step.features()) names.add(placed.getKey(feature).getPath());
                out.add(names);
            }
            root.add("steps", out);
            Files.writeString(Path.of(args[0]), new GsonBuilder().setPrettyPrinting().create().toJson(root) + "\n");
        }
    }
}
