import java.lang.reflect.Constructor;
import java.nio.file.*;
import java.util.*;
import com.google.gson.*;
import io.netty.buffer.Unpooled;
import it.unimi.dsi.fastutil.objects.Object2IntOpenHashMap;
import net.minecraft.SharedConstants;
import net.minecraft.core.*;
import net.minecraft.core.registries.Registries;
import net.minecraft.network.RegistryFriendlyByteBuf;
import net.minecraft.network.VarInt;
import net.minecraft.server.Bootstrap;
import net.minecraft.world.item.enchantment.*;

/** Actual component codec bytes, semantic maps, consumption and rejection cases. */
public class ExportEnchantmentComponents {
    static JsonObject semantic(ItemEnchantments value) {
        var sorted = new TreeMap<String, Integer>();
        for (var holder : value.keySet()) {
            sorted.put(holder.unwrapKey().orElseThrow().identifier().toString(), value.getLevel(holder));
        }
        var result = new JsonObject();
        sorted.forEach(result::addProperty);
        return result;
    }

    static byte[] encode(RegistryAccess access, ItemEnchantments value) {
        var buffer = new RegistryFriendlyByteBuf(Unpooled.buffer(), access);
        try {
            ItemEnchantments.STREAM_CODEC.encode(buffer, value);
            var bytes = new byte[buffer.readableBytes()];
            buffer.readBytes(bytes);
            return bytes;
        } finally {
            buffer.release();
        }
    }

    static JsonObject decode(RegistryAccess access, String label, byte[] bytes) {
        var row = new JsonObject();
        row.addProperty("name", label);
        row.addProperty("hex", HexFormat.of().formatHex(bytes));
        var buffer = new RegistryFriendlyByteBuf(Unpooled.wrappedBuffer(bytes), access);
        try {
            var value = ItemEnchantments.STREAM_CODEC.decode(buffer);
            row.addProperty("accepted", true);
            row.add("enchantments", semantic(value));
            row.addProperty("remaining", buffer.readableBytes());
        } catch (RuntimeException failure) {
            row.addProperty("accepted", false);
            row.addProperty("exception", failure.getClass().getName());
        } finally {
            buffer.release();
        }
        return row;
    }

    static byte[] entries(int count, int... entries) {
        var buffer = Unpooled.buffer();
        try {
            VarInt.write(buffer, count);
            for (int value : entries) VarInt.write(buffer, value);
            var bytes = new byte[buffer.readableBytes()];
            buffer.readBytes(bytes);
            return bytes;
        } finally {
            buffer.release();
        }
    }

    public static void main(String[] args) throws Exception {
        if (args.length != 1) throw new IllegalArgumentException("Expected output fixture path");
        SharedConstants.tryDetectVersion();
        Bootstrap.bootStrap();
        var root = new JsonObject();
        root.addProperty("version", SharedConstants.getCurrentVersion().id());
        root.addProperty("protocol", SharedConstants.getCurrentVersion().protocolVersion());
        var cases = new JsonArray();
        try (var data = ReferenceWorldData.load()) {
            var access = data.access();
            var registry = access.lookupOrThrow(Registries.ENCHANTMENT);
            var holders = registry.listElements().toList();
            var ids = new JsonObject();
            for (var holder : holders) {
                ids.addProperty(holder.key().identifier().toString(), registry.getId(holder.value()));
            }
            root.add("registry", ids);
            Constructor<ItemEnchantments> constructor = ItemEnchantments.class.getDeclaredConstructor(Object2IntOpenHashMap.class);
            constructor.setAccessible(true);
            cases.add(decode(access, "empty", encode(access, ItemEnchantments.EMPTY)));
            for (var holder : holders) {
                for (int level : new int[]{0, 1, holder.value().getMaxLevel(), 127, 128, 255}) {
                    var map = new Object2IntOpenHashMap<Holder<Enchantment>>();
                    map.put(holder, level);
                    cases.add(decode(access, holder.key().identifier() + "/" + level, encode(access, constructor.newInstance(map))));
                }
            }
            for (int rotation = 0; rotation < holders.size(); rotation++) {
                var map = new Object2IntOpenHashMap<Holder<Enchantment>>();
                for (int offset = 0; offset < holders.size(); offset++) {
                    var holder = holders.get((offset + rotation) % holders.size());
                    map.put(holder, (offset * 17 + rotation) & 255);
                }
                byte[] bytes = encode(access, constructor.newInstance(map));
                cases.add(decode(access, "all/" + rotation, bytes));
                if (rotation == 0) {
                    for (int length = 0; length < bytes.length; length++) {
                        cases.add(decode(access, "truncated/" + length, Arrays.copyOf(bytes, length)));
                    }
                    var trailing = Arrays.copyOf(bytes, bytes.length + 2);
                    trailing[bytes.length] = 0x12;
                    trailing[bytes.length + 1] = 0x34;
                    cases.add(decode(access, "trailing-fields", trailing));
                }
            }
            int first = registry.getId(holders.getFirst().value());
            int last = registry.getId(holders.getLast().value());
            cases.add(decode(access, "duplicate-last-wins", entries(3, first, 1, last, 255, first, 2)));
            cases.add(decode(access, "duplicate-invalid-overwritten", entries(2, first, -1, first, 1)));
            cases.add(decode(access, "negative-count", entries(-1)));
            cases.add(decode(access, "negative-level", entries(1, first, -1)));
            cases.add(decode(access, "oversized-level", entries(1, first, 256)));
            cases.add(decode(access, "negative-holder", entries(1, -1, 1)));
            cases.add(decode(access, "unknown-holder", entries(1, holders.size(), 1)));
            cases.add(decode(access, "huge-count-truncated", entries(Integer.MAX_VALUE)));
            root.add("cases", cases);
        }
        Files.writeString(Path.of(args[0]), new GsonBuilder().setPrettyPrinting().create().toJson(root) + "\n");
        System.out.println("Exported " + cases.size() + " actual enchantment component codec cases");
    }
}
