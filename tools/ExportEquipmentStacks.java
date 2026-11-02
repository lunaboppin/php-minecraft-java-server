import java.lang.reflect.Constructor;
import java.nio.file.*;
import java.util.*;
import com.google.gson.*;
import com.mojang.datafixers.util.Pair;
import io.netty.buffer.Unpooled;
import it.unimi.dsi.fastutil.objects.Object2IntOpenHashMap;
import net.minecraft.SharedConstants;
import net.minecraft.core.*;
import net.minecraft.core.component.*;
import net.minecraft.core.registries.*;
import net.minecraft.network.RegistryFriendlyByteBuf;
import net.minecraft.network.protocol.game.ClientboundSetEquipmentPacket;
import net.minecraft.resources.Identifier;
import net.minecraft.server.Bootstrap;
import net.minecraft.world.effect.*;
import net.minecraft.world.entity.EquipmentSlot;
import net.minecraft.world.item.*;
import net.minecraft.world.item.alchemy.*;
import net.minecraft.world.item.enchantment.*;

/** Actual stack patches and equipment packets for the spawn equipment component bridge. */
public class ExportEquipmentStacks {
    static final Map<String, DataComponentType<?>> COMPONENTS = new LinkedHashMap<>();
    static void initializeComponents() {
        COMPONENTS.put("damage", DataComponents.DAMAGE);
        COMPONENTS.put("enchantments", DataComponents.ENCHANTMENTS);
        COMPONENTS.put("storedEnchantments", DataComponents.STORED_ENCHANTMENTS);
        COMPONENTS.put("enchantability", DataComponents.ENCHANTABLE);
        COMPONENTS.put("potion", DataComponents.POTION_CONTENTS);
    }

    static JsonElement value(Object value) {
        if (value instanceof ItemEnchantments enchantments) return ExportEnchantmentComponents.semantic(enchantments);
        if (value instanceof Enchantable enchantable) return new JsonPrimitive(enchantable.value());
        if (value instanceof PotionContents contents) return potion(contents);
        if (value instanceof Integer number) return new JsonPrimitive(number);
        throw new IllegalArgumentException("Unexpected supported component value " + value);
    }

    static JsonElement key(Holder<?> holder) {
        return new JsonPrimitive(holder.unwrapKey().orElseThrow().identifier().toString());
    }

    /** PotionContents as its four optional parts, with each custom effect's details. */
    static JsonObject potion(PotionContents contents) {
        var result = new JsonObject();
        result.add("potion", contents.potion().map(ExportEquipmentStacks::key).orElse(JsonNull.INSTANCE));
        result.add("custom_color", contents.customColor().map(color -> (JsonElement) new JsonPrimitive(color)).orElse(JsonNull.INSTANCE));
        var effects = new JsonArray();
        for (var effect : contents.customEffects()) {
            var row = new JsonObject();
            row.add("id", key(effect.getEffect()));
            details(row, effect);
            effects.add(row);
        }
        result.add("custom_effects", effects);
        result.add("custom_name", contents.customName().map(name -> (JsonElement) new JsonPrimitive(name)).orElse(JsonNull.INSTANCE));
        return result;
    }

    /** MobEffectInstance.Details, read through the instance since the record is private. */
    static void details(JsonObject row, MobEffectInstance effect) {
        row.addProperty("amplifier", effect.getAmplifier());
        row.addProperty("duration", effect.getDuration());
        row.addProperty("ambient", effect.isAmbient());
        row.addProperty("show_particles", effect.isVisible());
        row.addProperty("show_icon", effect.showIcon());
        try {
            var field = MobEffectInstance.class.getDeclaredField("hiddenEffect");
            field.setAccessible(true);
            var hidden = (MobEffectInstance) field.get(effect);
            if (hidden == null) {
                row.add("hidden_effect", JsonNull.INSTANCE);
            } else {
                var inner = new JsonObject();
                details(inner, hidden);
                row.add("hidden_effect", inner);
            }
        } catch (ReflectiveOperationException failure) {
            throw new IllegalStateException(failure);
        }
    }

    static String field(DataComponentType<?> type) {
        return COMPONENTS.entrySet().stream().filter(entry -> entry.getValue() == type).findFirst().orElseThrow().getKey();
    }

    static JsonElement state(ItemStack stack) {
        if (stack.isEmpty()) return JsonNull.INSTANCE;
        var result = new JsonObject();
        result.addProperty("item", BuiltInRegistries.ITEM.getId(stack.getItem()));
        result.addProperty("count", stack.getCount());
        var removed = new ArrayList<String>();
        var split = stack.getComponentsPatch().split();
        for (var type : split.added().keySet()) {
            result.add(field(type), value(split.added().get(type)));
        }
        for (var type : split.removed()) {
            removed.add(BuiltInRegistries.DATA_COMPONENT_TYPE.getKey(type).toString());
        }
        if (!removed.isEmpty()) {
            Collections.sort(removed);
            var names = new JsonArray(); removed.forEach(names::add);
            result.add("removedComponents", names);
        }
        return result;
    }

    static byte[] encode(RegistryAccess access, ItemStack stack) {
        var buffer = new RegistryFriendlyByteBuf(Unpooled.buffer(), access);
        try {
            ItemStack.OPTIONAL_STREAM_CODEC.encode(buffer, stack);
            var bytes = new byte[buffer.readableBytes()]; buffer.readBytes(bytes); return bytes;
        } finally { buffer.release(); }
    }

    static JsonObject decode(RegistryAccess access, String name, byte[] bytes) {
        var row = new JsonObject(); row.addProperty("name", name);
        row.addProperty("hex", HexFormat.of().formatHex(bytes));
        var buffer = new RegistryFriendlyByteBuf(Unpooled.wrappedBuffer(bytes), access);
        try {
            var stack = ItemStack.OPTIONAL_STREAM_CODEC.decode(buffer);
            row.addProperty("accepted", true);
            row.add("stack", state(stack));
            var metadata = ExportEnchanting.metadata(stack, access.lookupOrThrow(Registries.ENCHANTMENT).listElements().toList());
            metadata.addProperty("name", BuiltInRegistries.ITEM.getKey(stack.getItem()).toString());
            row.add("metadata", metadata);
            row.addProperty("remaining", buffer.readableBytes());
            row.addProperty("reencoded_hex", HexFormat.of().formatHex(encode(access, stack)));
        } catch (RuntimeException failure) {
            row.addProperty("accepted", false);
            row.addProperty("exception", failure.getClass().getName());
        } finally { buffer.release(); }
        return row;
    }

    static ItemEnchantments enchantments(Registry<Enchantment> registry, int mode) throws Exception {
        var map = new Object2IntOpenHashMap<Holder<Enchantment>>();
        var holders = registry.listElements().toList();
        if (mode != 0) {
            for (int index = 0; index < (mode == 1 ? 1 : holders.size()); index++) {
                map.put(holders.get(index), mode == 1 ? 0 : ((index * 17) & 255));
            }
        }
        Constructor<ItemEnchantments> constructor = ItemEnchantments.class.getDeclaredConstructor(Object2IntOpenHashMap.class);
        constructor.setAccessible(true);
        return constructor.newInstance(map);
    }

    public static void main(String[] args) throws Exception {
        if (args.length != 1) throw new IllegalArgumentException("Expected output fixture path");
        SharedConstants.tryDetectVersion(); Bootstrap.bootStrap();
        initializeComponents();
        var root = new JsonObject();
        root.addProperty("version", SharedConstants.getCurrentVersion().id());
        root.addProperty("protocol", SharedConstants.getCurrentVersion().protocolVersion());
        var rules = root.deepCopy();
        var cases = new JsonArray();
        var packets = new JsonArray();
        var comparisonStacks = new ArrayList<ItemStack>();
        try (var data = ReferenceWorldData.load()) {
            var access = data.access();
            var registry = access.lookupOrThrow(Registries.ENCHANTMENT);
            var componentIds = new JsonObject();
            var fields = new JsonObject();
            for (var component : BuiltInRegistries.DATA_COMPONENT_TYPE) {
                componentIds.addProperty(BuiltInRegistries.DATA_COMPONENT_TYPE.getKey(component).toString(), BuiltInRegistries.DATA_COMPONENT_TYPE.getId(component));
            }
            COMPONENTS.forEach((name, type) -> fields.addProperty(name, BuiltInRegistries.DATA_COMPONENT_TYPE.getKey(type).toString()));
            rules.add("component_ids", componentIds); rules.add("fields", fields);
            var potions = new JsonArray();
            var potionNames = new JsonArray();
            access.lookupOrThrow(Registries.POTION).listElements().forEach(holder -> { potions.add(key(holder)); potionNames.add(holder.value().name()); });
            var effects = new JsonArray();
            access.lookupOrThrow(Registries.MOB_EFFECT).listElements().forEach(holder -> effects.add(key(holder)));
            rules.add("potions", potions); rules.add("potion_names", potionNames); rules.add("mob_effects", effects);
            var defaults = new JsonObject();
            for (var item : BuiltInRegistries.ITEM) {
                var row = new JsonObject(); var present = new JsonArray(); var values = new JsonObject();
                var presentNames = item.components().keySet().stream().map(type -> BuiltInRegistries.DATA_COMPONENT_TYPE.getKey(type).toString()).sorted().toList();
                presentNames.forEach(present::add);
                for (var entry : COMPONENTS.entrySet()) {
                    Object value = item.components().get(entry.getValue());
                    if (value != null) values.add(entry.getKey(), value(value));
                }
                row.add("present", present); row.add("values", values);
                defaults.add(Integer.toString(BuiltInRegistries.ITEM.getId(item)), row);
            }
            rules.add("defaults", defaults);
            rules.addProperty("air_item", BuiltInRegistries.ITEM.getId(Items.AIR));
            for (String name : List.of("air", "stone", "book", "enchanted_book", "iron_sword", "iron_spear", "golden_sword", "golden_spear", "iron_shovel", "trident", "fishing_rod", "leather_boots", "copper_helmet", "diamond_chestplate", "netherite_leggings", "carved_pumpkin", "shield", "saddle")) {
                var item = BuiltInRegistries.ITEM.getValue(Identifier.withDefaultNamespace(name));
                for (int mode = 0; mode < 12; mode++) {
                    var stack = new ItemStack(item, mode % 3 + 1);
                    switch (mode) {
                        case 0 -> {}
                        case 1 -> stack.set(DataComponents.DAMAGE, 7);
                        case 2 -> stack.set(DataComponents.ENCHANTMENTS, enchantments(registry, 1));
                        case 3 -> stack.set(DataComponents.STORED_ENCHANTMENTS, enchantments(registry, 2));
                        case 4 -> stack.remove(DataComponents.ENCHANTMENTS);
                        case 5 -> stack.remove(DataComponents.STORED_ENCHANTMENTS);
                        case 6 -> stack.remove(DataComponents.ENCHANTABLE);
                        case 7 -> stack.set(DataComponents.ENCHANTABLE, new Enchantable(Integer.MAX_VALUE));
                        case 8 -> {
                            stack.set(DataComponents.DAMAGE, 11);
                            stack.set(DataComponents.ENCHANTMENTS, enchantments(registry, 2));
                            stack.set(DataComponents.STORED_ENCHANTMENTS, enchantments(registry, 1));
                            stack.set(DataComponents.ENCHANTABLE, new Enchantable(1));
                        }
                        case 9 -> {
                            stack.remove(DataComponents.DAMAGE); stack.remove(DataComponents.ENCHANTABLE);
                            stack.remove(DataComponents.ENCHANTMENTS); stack.remove(DataComponents.STORED_ENCHANTMENTS);
                        }
                        case 10 -> stack.set(DataComponents.ENCHANTMENTS, ItemEnchantments.EMPTY);
                        case 11 -> stack.remove(DataComponents.MAX_STACK_SIZE);
                    }
                    byte[] bytes = encode(access, stack);
                    var row = decode(access, name + "/" + mode, bytes);
                    row.add("initial", state(stack));
                    row.addProperty("comparison_index", comparisonStacks.size());
                    comparisonStacks.add(stack.copy());
                    cases.add(row);
                    if (mode == 8 && !stack.isEmpty()) {
                        var updates = new ArrayList<Pair<EquipmentSlot, ItemStack>>();
                        for (var slot : EquipmentSlot.values()) updates.add(Pair.of(slot, slot.ordinal() % 2 == 0 ? stack : ItemStack.EMPTY));
                        var buffer = new RegistryFriendlyByteBuf(Unpooled.buffer(), access);
                        try {
                            ClientboundSetEquipmentPacket.STREAM_CODEC.encode(buffer, new ClientboundSetEquipmentPacket(17, updates));
                            var payload = new byte[buffer.readableBytes()]; buffer.readBytes(payload);
                            var packet = new JsonObject(); packet.add("stack", state(stack));
                            packet.addProperty("hex", HexFormat.of().formatHex(payload)); packets.add(packet);
                        } finally { buffer.release(); }
                    }
                    if (name.equals("iron_sword") && mode == 8) {
                        for (int length = 0; length < bytes.length; length++) {
                            cases.add(decode(access, "truncated/" + length, Arrays.copyOf(bytes, length)));
                        }
                    }
                }
            }
            // PotionContents: a potion alone, every optional part, removed or empty.
            for (String name : List.of("stone", "potion", "splash_potion", "lingering_potion", "tipped_arrow")) {
                var item = BuiltInRegistries.ITEM.getValue(Identifier.withDefaultNamespace(name));
                for (int mode = 0; mode < 6; mode++) {
                    var stack = new ItemStack(item, 1);
                    switch (mode) {
                        case 0 -> {}
                        case 1 -> stack.set(DataComponents.POTION_CONTENTS, new PotionContents(Potions.WATER));
                        case 2 -> stack.set(DataComponents.POTION_CONTENTS, new PotionContents(Optional.of(Potions.SWIFTNESS), Optional.of(0x123456),
                            List.of(new MobEffectInstance(MobEffects.SPEED, 200, 1, true, false, true), new MobEffectInstance(MobEffects.POISON, 30)),
                            Optional.of("custom")));
                        case 3 -> stack.remove(DataComponents.POTION_CONTENTS);
                        case 4 -> stack.set(DataComponents.POTION_CONTENTS, PotionContents.EMPTY);
                        case 5 -> stack.set(DataComponents.POTION_CONTENTS, new PotionContents(Optional.empty(), Optional.of(-1), List.of(), Optional.empty()));
                    }
                    byte[] bytes = encode(access, stack);
                    var row = decode(access, name + "/potion/" + mode, bytes);
                    row.add("initial", state(stack));
                    row.addProperty("comparison_index", comparisonStacks.size());
                    comparisonStacks.add(stack.copy());
                    cases.add(row);
                    if (name.equals("potion") && mode == 2) {
                        for (int length = 3; length < bytes.length; length++) {
                            cases.add(decode(access, "truncated-potion/" + length, Arrays.copyOf(bytes, length)));
                        }
                    }
                }
            }
            int sword = BuiltInRegistries.ITEM.getId(Items.IRON_SWORD);
            int damage = BuiltInRegistries.DATA_COMPONENT_TYPE.getId(DataComponents.DAMAGE);
            int enchantable = BuiltInRegistries.DATA_COMPONENT_TYPE.getId(DataComponents.ENCHANTABLE);
            int enchantments = BuiltInRegistries.DATA_COMPONENT_TYPE.getId(DataComponents.ENCHANTMENTS);
            var crafted = new TreeMap<String, byte[]>();
            crafted.put("negative-empty", ExportEnchantmentComponents.entries(-1));
            crafted.put("air-count", ExportEnchantmentComponents.entries(1, 0, 0, 0));
            crafted.put("negative-damage", ExportEnchantmentComponents.entries(1, sword, 1, 0, damage, -1));
            crafted.put("zero-enchantability", ExportEnchantmentComponents.entries(1, sword, 1, 0, enchantable, 0));
            crafted.put("duplicate-add", ExportEnchantmentComponents.entries(1, sword, 2, 0, damage, 3, damage, 7));
            crafted.put("add-then-remove", ExportEnchantmentComponents.entries(1, sword, 1, 1, damage, 7, damage));
            crafted.put("negative-added-positive-removed", ExportEnchantmentComponents.entries(1, sword, -1, 1, damage));
            crafted.put("negative-added", ExportEnchantmentComponents.entries(1, sword, -1, 0));
            crafted.put("bad-enchantment-level", ExportEnchantmentComponents.entries(1, sword, 1, 0, enchantments, 1, 0, 256));
            crafted.put("explicit-default-damage", ExportEnchantmentComponents.entries(1, sword, 1, 0, damage, 0));
            crafted.put("explicit-empty-enchantments", ExportEnchantmentComponents.entries(1, sword, 1, 0, enchantments, 0));
            crafted.put("remove-absent-component", ExportEnchantmentComponents.entries(1, sword, 0, 1, BuiltInRegistries.DATA_COMPONENT_TYPE.getId(DataComponents.STORED_ENCHANTMENTS)));
            crafted.put("max-count", ExportEnchantmentComponents.entries(Integer.MAX_VALUE, sword, 0, 0));
            // A custom effect with another hidden beneath it, which in turn hides a third.
            int potionItem = BuiltInRegistries.ITEM.getId(Items.POTION);
            int potionContents = BuiltInRegistries.DATA_COMPONENT_TYPE.getId(DataComponents.POTION_CONTENTS);
            int speed = BuiltInRegistries.MOB_EFFECT.getId(MobEffects.SPEED.value());
            crafted.put("potion-hidden-effects", ExportEnchantmentComponents.entries(1, potionItem, 1, 0, potionContents, 0, 0, 1, speed, 1, 200, 1, 0, 1, 1, 0, 400, 0, 1, 1, 1, 0, 9000, 0, 0, 0, 0, 0));
            crafted.put("potion-unknown-effect", ExportEnchantmentComponents.entries(1, potionItem, 1, 0, potionContents, 0, 0, 1, 9999, 0, 1, 0, 1, 1, 0, 0));
            crafted.put("potion-unknown-potion", ExportEnchantmentComponents.entries(1, potionItem, 1, 0, potionContents, 1, 9999, 0, 0, 0));
            for (var entry : crafted.entrySet()) {
                cases.add(decode(access, entry.getKey(), entry.getValue()));
            }
            var comparisons = new JsonArray();
            for (var first : comparisonStacks) {
                var row = new StringBuilder();
                for (var second : comparisonStacks) {
                    row.append(ItemStack.isSameItemSameComponents(first, second) ? '1' : '0');
                }
                comparisons.add(row.toString());
            }
            root.add("comparison_rows", comparisons);
            root.add("cases", cases); root.add("equipment_packets", packets);
        }
        var gson = new GsonBuilder().serializeNulls().setPrettyPrinting().create();
        Files.writeString(Path.of(args[0]), gson.toJson(root) + "\n");
        Files.writeString(Path.of(args[0]).resolveSibling("equipment-stack-rules.json"), gson.toJson(rules) + "\n");
        System.out.println("Exported " + cases.size() + " native stack cases and " + packets.size() + " equipment packets");
    }
}
