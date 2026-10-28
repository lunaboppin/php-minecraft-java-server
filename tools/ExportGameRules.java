import java.nio.file.*;
import com.google.gson.*;
import com.mojang.brigadier.arguments.IntegerArgumentType;
import net.minecraft.SharedConstants;
import net.minecraft.core.registries.BuiltInRegistries;
import net.minecraft.server.Bootstrap;
import net.minecraft.world.flag.FeatureFlags;
import net.minecraft.world.level.gamerules.GameRule;

/** Every vanilla game rule with its value type, default, integer bounds and required features. */
public class ExportGameRules {
    static <T> String defaultText(GameRule<T> rule) {
        return rule.serialize(rule.defaultValue());
    }

    public static void main(String[] args) throws Exception {
        if (args.length != 1) throw new IllegalArgumentException("Expected output path");
        SharedConstants.tryDetectVersion(); Bootstrap.bootStrap();
        var root = new JsonObject();
        root.addProperty("version", SharedConstants.getCurrentVersion().id());
        var rules = new JsonObject();
        for (var rule : BuiltInRegistries.GAME_RULE) {
            var row = new JsonObject();
            boolean bool = rule.valueClass() == Boolean.class;
            row.addProperty("type", bool ? "bool" : "int");
            row.addProperty("default", defaultText(rule));
            if (rule.argument() instanceof IntegerArgumentType integer) {
                row.addProperty("min", integer.getMinimum());
                row.addProperty("max", integer.getMaximum());
            }
            row.addProperty("vanilla", rule.requiredFeatures().isSubsetOf(FeatureFlags.VANILLA_SET));
            rules.add(rule.id(), row);
        }
        root.add("rules", rules);
        Files.writeString(Path.of(args[0]), new GsonBuilder().setPrettyPrinting().create().toJson(root) + "\n");
    }
}
