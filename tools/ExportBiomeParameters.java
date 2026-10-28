import java.nio.file.*;
import com.google.gson.*;
import net.minecraft.SharedConstants;
import net.minecraft.server.Bootstrap;
import net.minecraft.world.level.biome.Climate;
import net.minecraft.world.level.biome.MultiNoiseBiomeSourceParameterList;

/**
 * The quantized multi-noise biome parameters of the Overworld and Nether presets, in the
 * order the official parameter lists (and so their search trees) are built from.
 */
public class ExportBiomeParameters {
    public static void main(String[] args) throws Exception {
        if (args.length != 1) throw new IllegalArgumentException("Expected output path");
        SharedConstants.tryDetectVersion(); Bootstrap.bootStrap();
        var root = new JsonObject();
        root.addProperty("version", SharedConstants.getCurrentVersion().id());
        root.addProperty("order", "biome, then min and max of temperature, humidity, continentalness, erosion, depth and weirdness, then offset");
        for (var entry : MultiNoiseBiomeSourceParameterList.knownPresets().entrySet()) {
            var rows = new JsonArray();
            for (var pair : entry.getValue().values()) {
                Climate.ParameterPoint point = pair.getFirst();
                var row = new JsonArray();
                row.add(pair.getSecond().identifier().getPath());
                for (Climate.Parameter parameter : new Climate.Parameter[]{point.temperature(), point.humidity(), point.continentalness(), point.erosion(), point.depth(), point.weirdness()}) {
                    row.add(parameter.min());
                    row.add(parameter.max());
                }
                row.add(point.offset());
                rows.add(row);
            }
            root.add(entry.getKey().id().getPath(), rows);
        }
        Files.writeString(Path.of(args[0]), new Gson().toJson(root) + "\n");
    }
}
