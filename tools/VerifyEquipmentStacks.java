import java.nio.file.*;
import com.google.gson.*;
import io.netty.buffer.Unpooled;
import net.minecraft.SharedConstants;
import net.minecraft.core.RegistryAccess;
import net.minecraft.network.RegistryFriendlyByteBuf;
import net.minecraft.network.protocol.game.ClientboundSetEquipmentPacket;
import net.minecraft.server.Bootstrap;

/** Decode PHP output with the actual vanilla stack and equipment codecs. */
public class VerifyEquipmentStacks {
    static JsonObject equipment(RegistryAccess access, JsonObject request) {
        var row = new JsonObject();
        row.addProperty("name", request.get("name").getAsString());
        var bytes = java.util.HexFormat.of().parseHex(request.get("hex").getAsString());
        var input = new RegistryFriendlyByteBuf(Unpooled.wrappedBuffer(bytes), access);
        try {
            var packet = ClientboundSetEquipmentPacket.STREAM_CODEC.decode(input);
            row.addProperty("accepted", true);
            row.addProperty("entity", packet.getEntity());
            var slots = new JsonObject();
            for (var slot : packet.getSlots()) {
                slots.add(Integer.toString(slot.getFirst().ordinal()), ExportEquipmentStacks.state(slot.getSecond()));
            }
            row.add("slots", slots);
            row.addProperty("remaining", input.readableBytes());
        } catch (RuntimeException failure) {
            row.addProperty("accepted", false);
            row.addProperty("exception", failure.getClass().getName());
        } finally { input.release(); }
        return row;
    }

    public static void main(String[] args) throws Exception {
        if (args.length != 2) throw new IllegalArgumentException("Expected request and output paths");
        SharedConstants.tryDetectVersion(); Bootstrap.bootStrap();
        ExportEquipmentStacks.initializeComponents();
        var requests = JsonParser.parseString(Files.readString(Path.of(args[0]))).getAsJsonArray();
        var results = new JsonArray();
        try (var data = ReferenceWorldData.load()) {
            for (var element : requests) {
                var request = element.getAsJsonObject();
                var result = switch (request.get("kind").getAsString()) {
                    case "stack" -> ExportEquipmentStacks.decode(data.access(), request.get("name").getAsString(), java.util.HexFormat.of().parseHex(request.get("hex").getAsString()));
                    case "equipment" -> equipment(data.access(), request);
                    default -> throw new IllegalArgumentException("Unknown verification request");
                };
                result.add("kind", request.get("kind"));
                results.add(result);
            }
        }
        Files.writeString(Path.of(args[1]), new GsonBuilder().serializeNulls().setPrettyPrinting().create().toJson(results) + "\n");
        System.out.println("Decoded " + results.size() + " PHP payloads with actual vanilla codecs");
    }
}
