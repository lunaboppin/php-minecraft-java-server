import java.nio.file.*;
import com.google.gson.*;
import net.minecraft.SharedConstants;
import net.minecraft.core.registries.BuiltInRegistries;
import net.minecraft.resources.Identifier;
import net.minecraft.server.Bootstrap;
import net.minecraft.world.entity.Mob;

/**
 * Mob.checkDespawn's inputs for each mob type: its category's instant and no-despawn distances,
 * whether it stays in peaceful, and which class's removeWhenFarAway decides for it.
 */
public class ExportMobDespawn {
    public static void main(String[] args) throws Exception {
        if (args.length != 2) throw new IllegalArgumentException("Expected output path and mob class mapping");
        SharedConstants.tryDetectVersion(); Bootstrap.bootStrap();
        var mapping = JsonParser.parseString(Files.readString(Path.of(args[1]))).getAsJsonObject();
        var root = new JsonObject();
        root.addProperty("version", SharedConstants.getCurrentVersion().id());
        var mobs = new JsonObject();
        for (var entry : mapping.entrySet()) {
            var type = BuiltInRegistries.ENTITY_TYPE.getValue(Identifier.withDefaultNamespace(entry.getKey()));
            var mobClass = Class.forName(entry.getValue().getAsString());
            if (!Mob.class.isAssignableFrom(mobClass)) continue;
            var mob = new JsonObject();
            var category = type.getCategory();
            mob.addProperty("category", category.getName());
            mob.addProperty("despawn_distance", category.getDespawnDistance());
            mob.addProperty("no_despawn_distance", category.getNoDespawnDistance());
            mob.addProperty("allowed_in_peaceful", type.isAllowedInPeaceful());
            mob.addProperty("remove_when_far_away", mobClass.getMethod("removeWhenFarAway", double.class).getDeclaringClass().getSimpleName());
            mobs.add(entry.getKey(), mob);
        }
        root.add("mobs", mobs);
        Files.writeString(Path.of(args[0]), new GsonBuilder().setPrettyPrinting().create().toJson(root) + "\n");
    }
}
