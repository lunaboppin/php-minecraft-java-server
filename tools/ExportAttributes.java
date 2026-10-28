import java.nio.file.*;
import com.google.gson.*;
import net.minecraft.SharedConstants;
import net.minecraft.core.registries.BuiltInRegistries;
import net.minecraft.server.Bootstrap;
import net.minecraft.world.entity.ai.attributes.Attribute;
import net.minecraft.world.entity.ai.attributes.RangedAttribute;

/** Every vanilla attribute: protocol id, default, range, whether clients are sent it, and its name key. */
public class ExportAttributes {
    public static void main(String[] args) throws Exception {
        if (args.length != 1) throw new IllegalArgumentException("Expected output path");
        SharedConstants.tryDetectVersion(); Bootstrap.bootStrap();
        var root = new JsonObject();
        root.addProperty("version", SharedConstants.getCurrentVersion().id());
        var attributes = new JsonObject();
        for (Attribute attribute : BuiltInRegistries.ATTRIBUTE) {
            var row = new JsonObject();
            row.addProperty("id", BuiltInRegistries.ATTRIBUTE.getId(attribute));
            row.addProperty("default", attribute.getDefaultValue());
            if (attribute instanceof RangedAttribute ranged) {
                row.addProperty("min", ranged.getMinValue());
                row.addProperty("max", ranged.getMaxValue());
            }
            row.addProperty("synced", attribute.isClientSyncable());
            row.addProperty("description", attribute.getDescriptionId());
            attributes.add(BuiltInRegistries.ATTRIBUTE.getKey(attribute).toString(), row);
        }
        root.add("attributes", attributes);
        Files.writeString(Path.of(args[0]), new GsonBuilder().setPrettyPrinting().create().toJson(root) + "\n");
    }
}
