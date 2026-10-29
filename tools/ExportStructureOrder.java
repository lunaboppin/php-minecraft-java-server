import java.nio.file.*;
import com.google.gson.*;
import net.minecraft.core.registries.Registries;

/**
 * The structure and structure set registries in iteration order. ChunkGenerator.createStructures
 * tries structure sets in this order, and applyBiomeDecoration places each step's structures in
 * this order, seeding each one with its index in the step.
 */
public class ExportStructureOrder {
    public static void main(String[] args) throws Exception {
        if (args.length != 1) throw new IllegalArgumentException("Expected output path");
        net.minecraft.SharedConstants.tryDetectVersion();
        net.minecraft.server.Bootstrap.bootStrap();
        try (var data = ReferenceWorldData.load()) {
            var access = data.access();
            var root = new JsonObject();
            root.addProperty("version", net.minecraft.SharedConstants.getCurrentVersion().id());
            var structures = access.lookupOrThrow(Registries.STRUCTURE);
            var names = new JsonArray();
            structures.listElements().forEach(holder -> {
                var entry = new JsonArray();
                entry.add(holder.key().identifier().getPath());
                entry.add(holder.value().step().getSerializedName());
                names.add(entry);
            });
            root.add("structures", names);
            var sets = new JsonArray();
            access.lookupOrThrow(Registries.STRUCTURE_SET).listElements().forEach(holder -> sets.add(holder.key().identifier().getPath()));
            root.add("structure_sets", sets);
            Files.writeString(Path.of(args[0]), new GsonBuilder().setPrettyPrinting().create().toJson(root) + "\n");
        }
    }
}
