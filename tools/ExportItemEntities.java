import java.nio.file.*;
import com.google.gson.*;
import net.minecraft.SharedConstants;
import net.minecraft.core.component.DataComponents;
import net.minecraft.core.registries.BuiltInRegistries;
import net.minecraft.server.Bootstrap;

/** ItemEntity's tracking profile and size, and the damage types each item's damage_resistant component resists. */
public class ExportItemEntities {
    public static void main(String[] args) throws Exception {
        if (args.length != 1) throw new IllegalArgumentException("Expected output path");
        SharedConstants.tryDetectVersion();
        Bootstrap.bootStrap();
        var root = new JsonObject();
        root.addProperty("version", SharedConstants.getCurrentVersion().id());
        var type = net.minecraft.world.entity.EntityTypes.ITEM;
        var tracking = new JsonObject();
        tracking.addProperty("update_interval", type.updateInterval());
        tracking.addProperty("track_deltas", type.trackDeltas());
        tracking.addProperty("width", type.getWidth());
        tracking.addProperty("height", type.getHeight());
        root.add("item", tracking);
        // Loading the reference world data binds the items' default components.
        try (var data = ReferenceWorldData.load()) {
            var resistant = new JsonObject();
            for (var item : BuiltInRegistries.ITEM) {
                var value = item.components().get(DataComponents.DAMAGE_RESISTANT);
                if (value == null) continue;
                var types = new JsonArray();
                value.types().stream().map(holder -> holder.unwrapKey().orElseThrow().identifier().toString()).sorted().forEach(types::add);
                resistant.add(BuiltInRegistries.ITEM.getKey(item).getPath(), types);
            }
            root.add("damage_resistant", resistant);
        }
        Files.writeString(Path.of(args[0]), new GsonBuilder().setPrettyPrinting().disableHtmlEscaping().create().toJson(root) + "\n");
    }
}
