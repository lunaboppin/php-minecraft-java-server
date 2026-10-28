import java.nio.file.*;
import com.google.gson.*;
import com.mojang.serialization.JsonOps;
import net.minecraft.SharedConstants;
import net.minecraft.core.registries.BuiltInRegistries;
import net.minecraft.network.chat.ComponentSerialization;
import net.minecraft.network.chat.TextColor;
import net.minecraft.server.Bootstrap;
import net.minecraft.world.item.ItemStack;

/**
 * Default item hover names with their rarity colors, and entity type descriptions, as used by
 * command feedback such as /give and /summon (ItemStack.getDisplayName, Entity.getDisplayName).
 */
public class ExportDisplayNames {
    public static void main(String[] args) throws Exception {
        if (args.length != 1) throw new IllegalArgumentException("Expected output path");
        SharedConstants.tryDetectVersion(); Bootstrap.bootStrap();
        var root = new JsonObject();
        root.addProperty("version", SharedConstants.getCurrentVersion().id());
        var items = new JsonObject();
        // Loading the reference data binds item components, which default stacks require.
        try (var data = ReferenceWorldData.load()) {
        for (var item : BuiltInRegistries.ITEM) {
            var stack = new ItemStack(item);
            if (stack.isEmpty()) continue;
            var row = new JsonObject();
            row.add("name", ComponentSerialization.CODEC.encodeStart(JsonOps.INSTANCE, stack.getHoverName()).getOrThrow());
            row.addProperty("color", TextColor.fromLegacyFormat(stack.getRarity().color()).serialize());
            items.add(BuiltInRegistries.ITEM.getKey(item).toString(), row);
        }
        }
        root.add("items", items);
        var entities = new JsonObject();
        for (var type : BuiltInRegistries.ENTITY_TYPE) {
            entities.add(BuiltInRegistries.ENTITY_TYPE.getKey(type).toString(),
                ComponentSerialization.CODEC.encodeStart(JsonOps.INSTANCE, type.getDescription()).getOrThrow());
        }
        root.add("entities", entities);
        Files.writeString(Path.of(args[0]), new GsonBuilder().setPrettyPrinting().disableHtmlEscaping().create().toJson(root) + "\n");
    }
}
