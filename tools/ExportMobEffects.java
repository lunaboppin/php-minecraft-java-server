import java.lang.reflect.*;
import java.nio.file.*;
import java.util.Map;
import com.google.gson.*;
import net.minecraft.SharedConstants;
import net.minecraft.core.Holder;
import net.minecraft.core.registries.BuiltInRegistries;
import net.minecraft.server.Bootstrap;
import net.minecraft.world.effect.MobEffect;

/** Every vanilla status effect: protocol id, category, colour, instant flag and attribute modifiers per level. */
public class ExportMobEffects {
    public static void main(String[] args) throws Exception {
        if (args.length != 1) throw new IllegalArgumentException("Expected output path");
        SharedConstants.tryDetectVersion(); Bootstrap.bootStrap();
        var root = new JsonObject();
        root.addProperty("version", SharedConstants.getCurrentVersion().id());
        var effects = new JsonObject();
        Field modifiers = MobEffect.class.getDeclaredField("attributeModifiers");
        modifiers.setAccessible(true);
        for (MobEffect effect : BuiltInRegistries.MOB_EFFECT) {
            var row = new JsonObject();
            row.addProperty("id", BuiltInRegistries.MOB_EFFECT.getId(effect));
            row.addProperty("category", effect.getCategory().name().toLowerCase());
            row.addProperty("color", effect.getColor());
            row.addProperty("instant", effect.isInstantaneous());
            row.addProperty("description", effect.getDescriptionId());
            var attributes = new JsonObject();
            for (var entry : ((Map<Holder<?>, Object>) modifiers.get(effect)).entrySet()) {
                Object template = entry.getValue();
                Method create = template.getClass().getDeclaredMethod("create", int.class);
                create.setAccessible(true);
                var modifier = new JsonObject();
                Object level0 = create.invoke(template, 0), level1 = create.invoke(template, 1);
                modifier.addProperty("id", level0.getClass().getMethod("id").invoke(level0).toString());
                modifier.addProperty("operation", level0.getClass().getMethod("operation").invoke(level0).toString().toLowerCase());
                var amounts = new JsonArray();
                for (int amplifier = 0; amplifier < 4; ++amplifier) {
                    Object created = create.invoke(template, amplifier);
                    amounts.add((double) created.getClass().getMethod("amount").invoke(created));
                }
                modifier.add("amounts", amounts);
                attributes.add(entry.getKey().unwrapKey().orElseThrow().identifier().toString(), modifier);
            }
            row.add("attributes", attributes);
            effects.add(BuiltInRegistries.MOB_EFFECT.getKey(effect).toString(), row);
        }
        root.add("effects", effects);
        Files.writeString(Path.of(args[0]), new GsonBuilder().setPrettyPrinting().create().toJson(root) + "\n");
    }
}
