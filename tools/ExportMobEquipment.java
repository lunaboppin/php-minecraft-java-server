import java.lang.reflect.*;
import java.nio.file.*;
import java.util.*;
import com.google.gson.*;
import com.mojang.datafixers.util.Pair;
import io.netty.buffer.Unpooled;
import net.minecraft.SharedConstants;
import net.minecraft.core.RegistryAccess;
import net.minecraft.core.registries.BuiltInRegistries;
import net.minecraft.network.RegistryFriendlyByteBuf;
import net.minecraft.network.protocol.game.ClientboundSetEquipmentPacket;
import net.minecraft.resources.Identifier;
import net.minecraft.server.Bootstrap;
import net.minecraft.util.RandomSource;
import net.minecraft.world.*;
import net.minecraft.world.entity.*;
import net.minecraft.world.item.*;
import net.minecraft.world.level.Level;
import net.minecraft.world.level.levelgen.LegacyRandomSource;

/** Actual virtual equipment methods, seeded streams and scripted reachable boundary draws. */
public class ExportMobEquipment {
    static final JsonArray STACKS = new JsonArray();
    static final JsonArray SNAPSHOTS = new JsonArray();
    static final Map<String, Integer> STACK_IDS = new LinkedHashMap<>();
    static final Map<String, Integer> SNAPSHOT_IDS = new LinkedHashMap<>();

    static class ScriptedRandom extends LegacyRandomSource {
        final JsonArray script;
        int position;

        ScriptedRandom(JsonArray script) { super(0); this.script = script; }

        JsonObject step(String method) {
            if (position >= script.size()) throw new IllegalStateException("Unexpected random call: " + method);
            var row = script.get(position++).getAsJsonObject();
            if (!row.get("method").getAsString().equals(method)) throw new IllegalStateException("Random call order differs");
            return row;
        }

        @Override public int next(int bits) { throw new IllegalStateException("Unexpected primitive random call"); }
        @Override public float nextFloat() { return Float.intBitsToFloat(Integer.parseUnsignedInt(step("float").get("bits").getAsString(), 16)); }
        @Override public int nextInt(int bound) {
            var row = step("int");
            if (row.get("bound").getAsInt() != bound) throw new IllegalStateException("Random bound differs");
            int value = row.get("value").getAsInt();
            if (value < 0 || value >= bound) throw new IllegalStateException("Scripted result is outside native bound");
            return value;
        }
    }

    static JsonObject floatStep(float value) {
        if (!(value >= 0 && value < 1) || value * 16777216.0 != Math.floor(value * 16777216.0)) {
            throw new IllegalArgumentException("Expected a reachable nextFloat grid value");
        }
        var row = new JsonObject();
        row.addProperty("method", "float");
        row.addProperty("bits", ExportMobInitialization.floatBits(value));
        return row;
    }

    static JsonObject intStep(int bound, int value) {
        var row = new JsonObject();
        row.addProperty("method", "int");
        row.addProperty("bound", bound);
        row.addProperty("value", value);
        return row;
    }

    static float below(float threshold) {
        return (float) (Math.ceil((double) threshold * 16777216.0) - 1) / 16777216.0F;
    }

    static float atOrAbove(float threshold) {
        return (float) Math.ceil((double) threshold * 16777216.0) / 16777216.0F;
    }

    static JsonArray armorScript(int tier, int mask, int stopAfter, float armorRoll) {
        var script = new JsonArray();
        script.add(floatStep(armorRoll));
        script.add(intStep(3, tier));
        for (int index = 0; index < 3; index++) script.add(floatStep((mask & (1 << index)) != 0 ? 0 : .5F));
        for (int slot = 1; slot < 4; slot++) {
            script.add(floatStep(slot == stopAfter ? 0 : .5F));
            if (slot == stopAfter) break;
        }
        return script;
    }

    static Method equipmentMethod(Class<?> clazz) throws Exception {
        for (var owner = clazz; owner != null; owner = owner.getSuperclass()) {
            try {
                var method = owner.getDeclaredMethod("populateDefaultEquipmentSlots", RandomSource.class, DifficultyInstance.class);
                method.setAccessible(true);
                return method;
            } catch (NoSuchMethodException ignored) {}
        }
        throw new NoSuchMethodException(clazz.getName());
    }

    static int stackId(ItemStack stack, RegistryAccess access) {
        var buffer = new RegistryFriendlyByteBuf(Unpooled.buffer(), access);
        ItemStack.OPTIONAL_STREAM_CODEC.encode(buffer, stack);
        var bytes = new byte[buffer.readableBytes()];
        buffer.readBytes(bytes);
        buffer.release();
        String hex = HexFormat.of().formatHex(bytes);
        if (!STACK_IDS.containsKey(hex)) {
            var row = new JsonObject();
            row.addProperty("hex", hex);
            if (stack.isEmpty()) row.add("state", JsonNull.INSTANCE);
            else {
                var state = new JsonObject();
                state.addProperty("item", BuiltInRegistries.ITEM.getId(stack.getItem()));
                state.addProperty("count", stack.getCount());
                if (stack.getDamageValue() != 0) state.addProperty("damage", stack.getDamageValue());
                row.add("state", state);
            }
            STACK_IDS.put(hex, STACKS.size());
            STACKS.add(row);
        }
        return STACK_IDS.get(hex);
    }

    static int snapshot(Mob actor, RegistryAccess access) {
        var slots = new JsonArray();
        var updates = new ArrayList<Pair<EquipmentSlot, ItemStack>>();
        for (var slot : EquipmentSlot.values()) {
            var stack = actor.getItemBySlot(slot);
            slots.add(stackId(stack, access));
            updates.add(Pair.of(slot, stack));
        }
        String key = slots.toString();
        if (!SNAPSHOT_IDS.containsKey(key)) {
            var row = new JsonObject();
            row.add("slots", slots);
            var buffer = new RegistryFriendlyByteBuf(Unpooled.buffer(), access);
            ClientboundSetEquipmentPacket.STREAM_CODEC.encode(buffer, new ClientboundSetEquipmentPacket(17, updates));
            var bytes = new byte[buffer.readableBytes()];
            buffer.readBytes(bytes);
            buffer.release();
            row.addProperty("packet_hex", HexFormat.of().formatHex(bytes));
            SNAPSHOT_IDS.put(key, SNAPSHOTS.size());
            SNAPSHOTS.add(row);
        }
        return SNAPSHOT_IDS.get(key);
    }

    static void layout(Mob actor, int layout) {
        for (var slot : EquipmentSlot.values()) actor.setItemSlot(slot, ItemStack.EMPTY);
        if (layout == 0) return;
        var names = layout == 1
            ? new String[]{"wooden_sword", "nautilus_shell", "leather_boots", null, "diamond_chestplate", "carved_pumpkin", "gray_carpet", "saddle"}
            : new String[]{"diamond_sword", "shield", "netherite_boots", "netherite_leggings", "netherite_chestplate", "netherite_helmet", "leather_horse_armor", "saddle"};
        for (var slot : EquipmentSlot.values()) {
            String name = names[slot.ordinal()];
            if (name == null) continue;
            var stack = new ItemStack(BuiltInRegistries.ITEM.getValue(Identifier.withDefaultNamespace(name)), slot == EquipmentSlot.OFFHAND ? 2 : 1);
            if (stack.isDamageableItem()) stack.setDamageValue(3 + slot.ordinal());
            actor.setItemSlot(slot, stack);
        }
    }

    @SuppressWarnings("unchecked")
    public static void main(String[] args) throws Exception {
        if (args.length != 1) throw new IllegalArgumentException("Expected output fixture path");
        SharedConstants.tryDetectVersion();
        Bootstrap.bootStrap();
        var result = new JsonObject();
        result.addProperty("version", SharedConstants.getCurrentVersion().id());
        result.addProperty("protocol", SharedConstants.getCurrentVersion().protocolVersion());
        result.addProperty("creation_request", "spawn_item_use with ignoreChecks=true to isolate equipment, including peaceful contexts");
        try (var data = ReferenceWorldData.load()) {
            var access = data.access();
            var level = ExportMobInitialization.createLevel(access);
            var constants = new JsonObject();
            var slotIds = new JsonObject();
            for (var slot : EquipmentSlot.values()) slotIds.addProperty(slot.getName(), slot.ordinal());
            constants.add("slots", slotIds);
            var orderField = Mob.class.getDeclaredField("EQUIPMENT_POPULATION_ORDER");
            orderField.setAccessible(true);
            var order = new JsonArray();
            for (var slot : (List<EquipmentSlot>) orderField.get(null)) order.add(slot.ordinal());
            constants.add("population_order", order);
            var armor = new JsonArray();
            for (int tier = 0; tier <= 5; tier++) {
                var row = new JsonArray();
                for (var slot : EquipmentSlot.values()) {
                    var item = Mob.getEquipmentForSlot(slot, tier);
                    row.add(item == null ? JsonNull.INSTANCE : new JsonPrimitive(BuiltInRegistries.ITEM.getId(item)));
                }
                armor.add(row);
            }
            constants.add("armor", armor);
            var items = new JsonObject();
            for (String name : List.of("air", "iron_sword", "iron_spear", "iron_shovel", "golden_spear", "golden_sword", "trident", "fishing_rod")) {
                items.addProperty(name, BuiltInRegistries.ITEM.getId(BuiltInRegistries.ITEM.getValue(Identifier.withDefaultNamespace(name))));
            }
            constants.add("items", items);
            result.add("constants", constants);

            var contexts = new JsonArray();
            var localContexts = List.of(
                new DifficultyInstance(Difficulty.NORMAL, 18000, 0, 1),
                new DifficultyInstance(Difficulty.NORMAL, 1512000, 2400000, 0),
                new DifficultyInstance(Difficulty.HARD, 1512000, 3600000, 1)
            );
            for (var difficulty : Difficulty.values()) {
                for (var local : localContexts) {
                    var row = new JsonObject();
                    row.addProperty("world_difficulty", difficulty.getId());
                    row.addProperty("special_bits", ExportMobInitialization.floatBits(local.getSpecialMultiplier()));
                    contexts.add(row);
                }
            }
            result.add("contexts", contexts);
            var seeds = new LinkedHashSet<Long>(List.of(0L, 1L, 42L, -1L, Long.MIN_VALUE, Long.MAX_VALUE));
            var seedRandom = RandomSource.create(42);
            while (seeds.size() < 48) seeds.add(seedRandom.nextLong());
            var owners = new JsonObject();
            var cases = new JsonArray();
            var scripted = new JsonArray();
            for (String kind : List.of("giant", "zombie", "husk", "drowned", "zombified_piglin", "zombie_villager")) {
                var type = BuiltInRegistries.ENTITY_TYPE.getValue(Identifier.withDefaultNamespace(kind));
                for (long seed : seeds) {
                    for (int context = 0; context < contexts.size(); context++) {
                        int worldDifficulty = context / localContexts.size();
                        level.difficulty = new DifficultyInstance(Difficulty.byId(worldDifficulty), 18000, 0, 1);
                        var local = localContexts.get(context % localContexts.size());
                        for (int initialLayout = 0; initialLayout < 3; initialLayout++) {
                            ExportMobPhysics.field(level, Level.class, "random", RandomSource.create(seed));
                            boolean spawnAllowed = type.canSpawn(level);
                            var actor = (Mob) type.create(level, new EntitySpawnRequest(EntitySpawnReason.SPAWN_ITEM_USE, true));
                            actor.setId(17);
                            layout(actor, initialLayout);
                            var method = equipmentMethod(actor.getClass());
                            owners.addProperty(kind, method.getDeclaringClass().getName());
                            var argumentRandom = RandomSource.create(seed);
                            long entitySeed = seed + 10000;
                            long worldSeed = seed ^ 0x123456789abcdefL;
                            var entityRandom = RandomSource.create(entitySeed);
                            var worldRandom = RandomSource.create(worldSeed);
                            ExportMobPhysics.field(actor, Entity.class, "random", entityRandom);
                            ExportMobPhysics.field(level, Level.class, "random", worldRandom);
                            var row = new JsonObject();
                            row.addProperty("kind", kind);
                            row.addProperty("seed", Long.toString(seed));
                            row.addProperty("context", context);
                            row.addProperty("layout", initialLayout);
                            row.addProperty("ordinary_spawn_allowed", spawnAllowed);
                            row.addProperty("initial", snapshot(actor, access));
                            var frames = new JsonArray();
                            for (int repeat = 0; repeat < 2; repeat++) {
                                method.invoke(actor, argumentRandom, local);
                                var frame = new JsonObject();
                                frame.addProperty("snapshot", snapshot(actor, access));
                                frame.addProperty("random_next", Long.toString(ReferenceWorldData.peekNextLong(argumentRandom)));
                                frames.add(frame);
                            }
                            row.add("frames", frames);
                            if (ReferenceWorldData.peekNextLong(entityRandom) != RandomSource.create(entitySeed).nextLong()
                                || ReferenceWorldData.peekNextLong(worldRandom) != RandomSource.create(worldSeed).nextLong()) {
                                throw new IllegalStateException("Equipment unexpectedly consumed another random source");
                            }
                            cases.add(row);
                        }
                    }
                }

                var scripts = new LinkedHashMap<String, JsonArray>();
                var scriptContexts = new HashMap<String, Integer>();
                if (!kind.equals("drowned") && !kind.equals("zombified_piglin")) {
                    for (int tier = 0; tier < 3; tier++) for (int mask = 0; mask < 8; mask++) {
                        scripts.put("armor-tier-" + tier + "-growth-" + mask, armorScript(tier, mask, 4, 0));
                    }
                    for (int stop = 1; stop <= 3; stop++) scripts.put("stop-after-" + stop, armorScript(0, 0, stop, 0));
                    scripts.put("armor-threshold-below", armorScript(0, 0, 4, below(.15F)));
                    var none = new JsonArray(); none.add(floatStep(atOrAbove(.15F))); scripts.put("armor-threshold-above", none);
                    for (boolean below : new boolean[]{true, false}) {
                        var growth = armorScript(0, 0, 4, 0);
                        growth.set(2, floatStep(below ? below(.1087F) : atOrAbove(.1087F)));
                        scripts.put("growth-threshold-" + below, growth);
                    }
                    for (boolean hard : new boolean[]{false, true}) for (boolean below : new boolean[]{true, false}) {
                        var stop = armorScript(0, 0, below ? 1 : 4, 0);
                        float chance = hard ? .1F : .25F;
                        stop.set(5, floatStep(below ? below(chance) : atOrAbove(chance)));
                        String name = "stop-threshold-" + hard + "-" + below;
                        scripts.put(name, stop);
                        scriptContexts.put(name, hard ? 11 : 8);
                    }
                    if (!kind.equals("giant")) {
                        scripts.values().forEach(script -> script.add(floatStep(.5F)));
                        for (int weapon = 0; weapon < 6; weapon++) {
                            var script = new JsonArray(); script.add(floatStep(.5F)); script.add(floatStep(0)); script.add(intStep(6, weapon));
                            scripts.put("weapon-" + weapon, script);
                        }
                        for (boolean below : new boolean[]{true, false}) {
                            var script = new JsonArray(); script.add(floatStep(.5F)); script.add(floatStep(below ? below(.05F) : atOrAbove(.05F)));
                            if (below) script.add(intStep(6, 1));
                            scripts.put("weapon-threshold-" + below, script);
                        }
                        for (boolean below : new boolean[]{true, false}) {
                            var script = new JsonArray(); script.add(floatStep(.5F)); script.add(floatStep(below ? below(.01F) : atOrAbove(.01F)));
                            if (below) script.add(intStep(6, 1));
                            String name = "weapon-normal-threshold-" + below;
                            scripts.put(name, script);
                            scriptContexts.put(name, 8);
                        }
                    }
                } else if (kind.equals("drowned")) {
                    for (int weapon = 0; weapon < 16; weapon++) {
                        var script = new JsonArray(); script.add(floatStep(.99999994F)); script.add(intStep(16, weapon));
                        scripts.put("weapon-" + weapon, script);
                    }
                    // Drowned compares its float draw with a DOUBLE 0.9. The
                    // nearest .9F value is just below that native threshold.
                    var noWeapon = new JsonArray(); noWeapon.add(floatStep(.9F)); scripts.put("weapon-threshold-below", noWeapon);
                    var weapon = new JsonArray(); weapon.add(floatStep(Math.nextUp(.9F))); weapon.add(intStep(16, 10)); scripts.put("weapon-threshold-above", weapon);
                } else {
                    for (int weapon = 0; weapon < 20; weapon++) {
                        var script = new JsonArray(); script.add(intStep(20, weapon)); scripts.put("weapon-" + weapon, script);
                    }
                }
                for (var entry : scripts.entrySet()) for (int initialLayout = 0; initialLayout < 3; initialLayout++) {
                    int context = scriptContexts.getOrDefault(entry.getKey(), 11);
                    level.difficulty = new DifficultyInstance(Difficulty.byId(context / localContexts.size()), 18000, 0, 1);
                    var actor = (Mob) type.create(level, new EntitySpawnRequest(EntitySpawnReason.SPAWN_ITEM_USE, true));
                    actor.setId(17);
                    layout(actor, initialLayout);
                    var random = new ScriptedRandom(entry.getValue());
                    var row = new JsonObject();
                    row.addProperty("kind", kind);
                    row.addProperty("name", entry.getKey());
                    row.addProperty("context", context);
                    row.addProperty("layout", initialLayout);
                    row.addProperty("initial", snapshot(actor, access));
                    row.add("script", entry.getValue());
                    equipmentMethod(actor.getClass()).invoke(actor, random, localContexts.get(2));
                    if (random.position != random.script.size()) throw new IllegalStateException("Unused scripted inputs for " + kind + "/" + entry.getKey());
                    row.addProperty("snapshot", snapshot(actor, access));
                    scripted.add(row);
                }
            }
            result.add("owners", owners);
            result.add("cases", cases);
            result.add("scripted_cases", scripted);
            result.add("stacks", STACKS);
            result.add("snapshots", SNAPSHOTS);
        }
        Files.writeString(Path.of(args[0]), new GsonBuilder().serializeNulls().setPrettyPrinting().create().toJson(result) + "\n");
        System.out.println("Exported actual equipment initialization and codec fixtures");
    }
}
