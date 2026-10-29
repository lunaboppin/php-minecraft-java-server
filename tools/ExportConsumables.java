import java.lang.reflect.Field;
import java.nio.file.*;
import com.google.gson.*;
import com.mojang.serialization.JsonOps;
import net.minecraft.SharedConstants;
import net.minecraft.core.component.DataComponents;
import net.minecraft.core.registries.BuiltInRegistries;
import net.minecraft.network.syncher.EntityDataAccessor;
import net.minecraft.resources.RegistryOps;
import net.minecraft.server.Bootstrap;
import net.minecraft.world.entity.player.Player;
import net.minecraft.world.item.component.Consumable;

/** Every item's consumable component (its consume effects) and the player's absorption entity data index. */
public class ExportConsumables {
    public static void main(String[] args) throws Exception {
        if (args.length != 1) throw new IllegalArgumentException("Expected output path");
        SharedConstants.tryDetectVersion(); Bootstrap.bootStrap();
        var root = new JsonObject();
        root.addProperty("version", SharedConstants.getCurrentVersion().id());
        var items = new JsonObject();
        // Loading the reference world data binds the items' default components.
        try (var data = ReferenceWorldData.load()) {
            var ops = RegistryOps.create(JsonOps.INSTANCE, data.access());
            for (var item : BuiltInRegistries.ITEM) {
                Consumable consumable = item.components().get(DataComponents.CONSUMABLE);
                if (consumable != null) {
                    items.add(BuiltInRegistries.ITEM.getKey(item).toString(), Consumable.CODEC.encodeStart(ops, consumable).getOrThrow());
                }
            }
            var alwaysEdible = new JsonArray();
            for (var item : BuiltInRegistries.ITEM) {
                var food = item.components().get(DataComponents.FOOD);
                if (food != null && food.canAlwaysEat()) alwaysEdible.add(BuiltInRegistries.ITEM.getKey(item).toString());
            }
            root.add("always_edible", alwaysEdible);
        }
        root.add("items", items);
        Field absorption = Player.class.getDeclaredField("DATA_PLAYER_ABSORPTION_ID");
        absorption.setAccessible(true);
        root.addProperty("absorption_index", ((EntityDataAccessor<?>) absorption.get(null)).id());
        Files.writeString(Path.of(args[0]), new GsonBuilder().setPrettyPrinting().create().toJson(root) + "\n");
    }
}
