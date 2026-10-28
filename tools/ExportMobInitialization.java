import java.lang.invoke.MethodHandles;
import java.lang.reflect.Proxy;
import java.nio.file.Files;
import java.nio.file.Path;
import java.util.*;
import com.google.gson.*;
import it.unimi.dsi.fastutil.objects.Object2ObjectOpenHashMap;
import net.minecraft.SharedConstants;
import net.minecraft.core.*;
import net.minecraft.core.registries.*;
import net.minecraft.resources.*;
import net.minecraft.server.Bootstrap;
import net.minecraft.server.level.ServerLevel;
import net.minecraft.util.RandomSource;
import net.minecraft.world.*;
import net.minecraft.world.entity.*;
import net.minecraft.world.entity.ai.attributes.*;
import net.minecraft.world.entity.monster.zombie.Zombie;
import net.minecraft.world.flag.*;
import net.minecraft.world.level.*;
import net.minecraft.world.level.biome.*;
import net.minecraft.world.level.gameevent.GameEvent;
import net.minecraft.world.level.gamerules.GameRules;
import net.minecraft.world.phys.Vec3;

/** Actual native math, shared Mob initialization, age changes and Zombie attribute phases. */
public class ExportMobInitialization {
    static class ProbeLevel extends ServerLevel {
        GameRules rules;
        Holder<Biome> biome;
        DifficultyInstance difficulty;
        int nextId;

        private ProbeLevel() {
            super(null, null, null, null, Level.OVERWORLD, null, false, 0, List.of(), true);
        }

        public int getNextEntityId() { return ++nextId; }
        public int getSeaLevel() { return 63; }
        public void gameEvent(Holder<GameEvent> event, Vec3 position, GameEvent.Context context) {}
        public GameRules getGameRules() { return rules; }
        public Holder<Biome> getBiome(BlockPos position) { return biome; }
        public Difficulty getDifficulty() { return difficulty.getDifficulty(); }
        public DifficultyInstance getCurrentDifficultyAt(BlockPos position) { return difficulty; }
        public FeatureFlagSet enabledFeatures() { return FeatureFlags.DEFAULT_FLAGS; }
    }

    static JsonArray bits(double... values) {
        return ExportMobPhysics.bits(values);
    }

    static String floatBits(float value) {
        return String.format(Locale.ROOT, "%08x", Float.floatToRawIntBits(value));
    }

    static JsonObject modifier(AttributeModifier modifier, boolean permanent) {
        var result = new JsonObject();
        result.addProperty("id", modifier.id().toString());
        result.addProperty("operation", modifier.operation().ordinal());
        result.add("amount_bits", bits(modifier.amount()));
        result.addProperty("permanent", permanent);
        return result;
    }

    static JsonObject attributes(LivingEntity actor) {
        var result = new JsonObject();
        for (var holder : BuiltInRegistries.ATTRIBUTE.listElements().toList()) {
            if (!actor.getAttributes().hasAttribute(holder)) continue;
            var instance = actor.getAttribute(holder);
            var row = new JsonObject();
            row.add("base_value_bits", bits(instance.getBaseValue(), instance.getValue()));
            var modifiers = new JsonArray();
            for (var value : instance.getModifiers()) {
                modifiers.add(modifier(value, instance.getPermanentModifiers().contains(value)));
            }
            row.add("modifiers", modifiers);
            result.add(holder.key().identifier().getPath(), row);
        }
        return result;
    }

    static JsonObject snapshot(Zombie actor) {
        var result = new JsonObject();
        result.add("attributes", attributes(actor));
        result.addProperty("health_bits", floatBits(actor.getHealth()));
        result.addProperty("baby", actor.isBaby());
        result.addProperty("left_handed", actor.isLeftHanded());
        result.addProperty("break_doors", actor.canBreakDoors());
        var dimensions = actor.getDimensions(Pose.STANDING);
        result.add("dimensions_bits", bits(dimensions.width(), dimensions.height(), dimensions.eyeHeight()));
        return result;
    }

    static void exportMath(JsonObject result) {
        var definitions = new JsonObject();
        for (var attribute : BuiltInRegistries.ATTRIBUTE) {
            if (!(attribute instanceof RangedAttribute ranged)) throw new IllegalStateException("Unknown native attribute implementation");
            var row = new JsonObject();
            row.addProperty("id", BuiltInRegistries.ATTRIBUTE.getId(attribute));
            row.addProperty("default", ranged.getDefaultValue());
            row.addProperty("min", ranged.getMinValue());
            row.addProperty("max", ranged.getMaxValue());
            row.addProperty("sync", ranged.isClientSyncable());
            definitions.add(BuiltInRegistries.ATTRIBUTE.getKey(attribute).getPath(), row);
        }
        result.add("definitions", definitions);

        var longs = new LinkedHashSet<Long>(List.of(Long.MIN_VALUE, Long.MAX_VALUE, -1L, 0L, 1L));
        for (int shift = 1; shift <= 39; shift++) {
            for (long significand : new long[]{1L << 23, (1L << 23) + 1}) {
                long half = significand * (1L << shift) + (1L << (shift - 1));
                for (long offset : new long[]{-1, 0, 1}) {
                    longs.add(half + offset);
                    longs.add(-half + offset);
                }
            }
        }
        var random = RandomSource.create(42);
        for (int index = 0; index < 512; index++) longs.add(random.nextLong());
        var casts = new JsonArray();
        for (long value : longs) {
            var row = new JsonObject();
            row.addProperty("input", Long.toString(value));
            row.addProperty("bits", floatBits((float) value));
            casts.add(row);
        }
        result.add("long_floats", casts);

        var difficulties = new JsonArray();
        for (var difficulty : Difficulty.values()) {
            for (long day : new long[]{Long.MIN_VALUE, -1, 0, 71999, 72000, 72001, 1440000, 1512000, Long.MAX_VALUE}) {
                for (long inhabited : new long[]{Long.MIN_VALUE, -1, 0, 1, 1800000, 3600000, Long.MAX_VALUE}) {
                    for (float moon : new float[]{-1, -0.0F, 0, .25F, 1, 2, Float.NaN}) {
                        var value = new DifficultyInstance(difficulty, day, inhabited, moon);
                        var row = new JsonObject();
                        row.addProperty("difficulty", difficulty.getId());
                        row.addProperty("day", Long.toString(day));
                        row.addProperty("inhabited", Long.toString(inhabited));
                        row.addProperty("moon_bits", floatBits(moon));
                        row.addProperty("effective_bits", floatBits(value.getEffectiveDifficulty()));
                        row.addProperty("special_bits", floatBits(value.getSpecialMultiplier()));
                        difficulties.add(row);
                    }
                }
            }
        }
        result.add("regional_difficulty", difficulties);

        var map = new Object2ObjectOpenHashMap<Identifier, JsonObject>();
        var mapFrames = new JsonArray();
        for (int index = 0; index < 320; index++) {
            int key = index < 96 ? index : index < 192 ? index - 96 : (index * 17) % 96;
            var id = Identifier.withDefaultNamespace("spawn_" + key);
            var row = new JsonObject();
            row.addProperty("id", id.toString());
            row.addProperty("remove", index >= 96 && index < 192);
            if (row.get("remove").getAsBoolean()) map.remove(id);
            else map.put(id, row);
            var order = new JsonArray();
            for (var value : map.values()) order.add(value.get("id").getAsString());
            row.add("order", order);
            mapFrames.add(row.deepCopy());
        }
        result.add("modifier_map", mapFrames);

        var attributeCases = new JsonArray();
        for (String name : List.of("max_health", "movement_speed", "knockback_resistance", "follow_range")) {
            var holder = BuiltInRegistries.ATTRIBUTE.getOrThrow(ResourceKey.create(Registries.ATTRIBUTE, Identifier.withDefaultNamespace(name)));
            var instance = new AttributeInstance(holder, ignored -> {});
            var row = new JsonObject();
            row.addProperty("name", name);
            row.add("initial_base_bits", bits(instance.getBaseValue()));
            var frames = new JsonArray();
            for (int index = 0; index < 400; index++) {
                var frame = new JsonObject();
                int key = index < 96 ? index : index < 192 ? index - 96 : random.nextInt(96);
                var id = Identifier.withDefaultNamespace("spawn_" + key);
                frame.addProperty("id", id.toString());
                if (index >= 96 && index < 192) {
                    instance.removeModifier(id);
                    frame.addProperty("action", "remove");
                } else if (index >= 192 && index % 9 == 0) {
                    double value = switch (index % 45) {
                        case 0 -> -0.0;
                        case 9 -> Double.NaN;
                        case 18 -> Double.POSITIVE_INFINITY;
                        case 27 -> Double.NEGATIVE_INFINITY;
                        default -> random.nextDouble() * 100 - 50;
                    };
                    instance.setBaseValue(value);
                    frame.addProperty("action", "base");
                    frame.add("base_bits", bits(value));
                } else {
                    var operation = AttributeModifier.Operation.values()[index % 3];
                    double amount = Math.scalb(random.nextDouble() * 2 - 1, random.nextInt(35) - 20);
                    var value = new AttributeModifier(id, amount, operation);
                    instance.addOrReplacePermanentModifier(value);
                    frame.addProperty("action", "replace");
                    frame.add("modifier", modifier(value, true));
                }
                frame.add("value_bits", bits(instance.getValue()));
                frames.add(frame);
            }
            row.add("frames", frames);
            attributeCases.add(row);
        }
        result.add("attribute_cases", attributeCases);
    }

    static ProbeLevel createLevel(RegistryAccess.Frozen access) throws Exception {
        var unsafeField = sun.misc.Unsafe.class.getDeclaredField("theUnsafe");
        unsafeField.setAccessible(true);
        var unsafe = (sun.misc.Unsafe) unsafeField.get(null);
        var server = (net.minecraft.server.dedicated.DedicatedServer) unsafe.allocateInstance(net.minecraft.server.dedicated.DedicatedServer.class);
        ExportMobPhysics.field(server, net.minecraft.server.MinecraftServer.class, "debugSubscribers", new net.minecraft.util.debug.ServerDebugSubscribers(server));
        var level = (ProbeLevel) unsafe.allocateInstance(ProbeLevel.class);
        level.rules = new GameRules(FeatureFlags.DEFAULT_FLAGS);
        level.biome = access.lookupOrThrow(Registries.BIOME).getOrThrow(Biomes.PLAINS);
        level.difficulty = new DifficultyInstance(Difficulty.NORMAL, 18000, 0, 1);
        ExportMobPhysics.field(level, ServerLevel.class, "server", server);
        ExportMobPhysics.field(level, Level.class, "registryAccess", access);
        ExportMobPhysics.field(level, Level.class, "dimension", Level.OVERWORLD);
        ExportMobPhysics.field(level, Level.class, "dimensionTypeRegistration", access.lookupOrThrow(Registries.DIMENSION_TYPE).getOrThrow(ResourceKey.create(Registries.DIMENSION_TYPE, Identifier.withDefaultNamespace("overworld"))));
        return level;
    }

    public static void main(String[] args) throws Throwable {
        if (args.length != 1) throw new IllegalArgumentException("Expected output fixture path");
        SharedConstants.tryDetectVersion();
        Bootstrap.bootStrap();
        var result = new JsonObject();
        result.addProperty("version", SharedConstants.getCurrentVersion().id());
        result.addProperty("protocol", SharedConstants.getCurrentVersion().protocolVersion());
        exportMath(result);
        try (var data = ReferenceWorldData.load()) {
            var access = data.access();
            var level = createLevel(access);
            var commonMethod = Mob.class.getDeclaredMethod("finalizeSpawn", ServerLevelAccessor.class, DifficultyInstance.class, EntitySpawnReason.class, SpawnGroupData.class);
            var common = MethodHandles.privateLookupIn(Mob.class, MethodHandles.lookup()).unreflectSpecial(commonMethod, Mob.class);
            var handleAttributes = Zombie.class.getDeclaredMethod("handleAttributes", float.class, EntitySpawnReason.class);
            handleAttributes.setAccessible(true);
            var phases = new JsonArray();
            for (String kind : List.of("zombie", "husk", "drowned", "zombified_piglin", "zombie_villager")) {
                for (long seed : new long[]{0, 1, 2, 11, 16, 34, 42, 76, 85, 108, 117, 771, 813, 868, 1150, 1960}) {
                    for (boolean baby : new boolean[]{false, true}) {
                        for (float special : new float[]{0, .5F, 1}) {
                            for (var reason : new EntitySpawnReason[]{EntitySpawnReason.SPAWN_ITEM_USE, EntitySpawnReason.CONVERSION, EntitySpawnReason.LOAD, EntitySpawnReason.DIMENSION_TRAVEL}) {
                                var type = BuiltInRegistries.ENTITY_TYPE.getValue(Identifier.withDefaultNamespace(kind));
                                ExportMobPhysics.field(level, Level.class, "random", RandomSource.create(seed));
                                var actor = (Zombie) type.create(level, reason);
                                actor.snapTo(.5, 64, .5, 0, 0);
                                actor.setHealth(7);
                                var worldRandom = RandomSource.create(seed);
                                var entityRandom = RandomSource.create(seed + 10000);
                                ExportMobPhysics.field(level, Level.class, "random", worldRandom);
                                ExportMobPhysics.field(actor, Entity.class, "random", entityRandom);
                                var world = (ServerLevelAccessor) Proxy.newProxyInstance(ServerLevelAccessor.class.getClassLoader(), new Class[]{ServerLevelAccessor.class}, (proxy, method, values) -> {
                                    if (method.getName().equals("getRandom")) return worldRandom;
                                    throw new UnsupportedOperationException("Unexpected shared initialization world call: " + method.getName());
                                });
                                var row = new JsonObject();
                                row.addProperty("kind", kind);
                                row.addProperty("seed", Long.toString(seed));
                                row.addProperty("entity_seed", Long.toString(seed + 10000));
                                row.addProperty("baby", baby);
                                row.addProperty("special_bits", floatBits(special));
                                row.addProperty("reason", reason.name().toLowerCase(Locale.ROOT));
                                row.add("initial", snapshot(actor));
                                var frames = new JsonArray();
                                for (int repeat = 0; repeat < 2; repeat++) {
                                    common.bindTo(actor).invoke(world, level.difficulty, reason, null);
                                    actor.setBaby(repeat == 0 ? baby : !baby);
                                    handleAttributes.invoke(actor, special, reason);
                                    frames.add(snapshot(actor));
                                }
                                row.add("frames", frames);
                                row.addProperty("world_random_next", Long.toString(worldRandom.nextLong()));
                                row.addProperty("entity_random_next", Long.toString(entityRandom.nextLong()));
                                phases.add(row);
                            }
                        }
                    }
                }
            }
            // Factor identical initial states and unchanged attributes without
            // dropping observations. The verifier reconstructs each full state.
            var defaults = new JsonObject();
            for (var value : phases) {
                var row = value.getAsJsonObject();
                String kind = row.get("kind").getAsString();
                var initial = row.remove("initial").getAsJsonObject();
                if (!defaults.has(kind)) defaults.add(kind, initial.deepCopy());
                if (!defaults.get(kind).equals(initial)) throw new IllegalStateException("Initial state unexpectedly varies for " + kind);
                var initialAttributes = initial.getAsJsonObject("attributes");
                for (var frameValue : row.getAsJsonArray("frames")) {
                    var frame = frameValue.getAsJsonObject();
                    var actual = frame.getAsJsonObject("attributes");
                    if (!actual.keySet().equals(initialAttributes.keySet())) throw new IllegalStateException("Attribute roster changed during initialization");
                    var changes = new JsonObject();
                    for (var entry : actual.entrySet()) {
                        if (!entry.getValue().equals(initialAttributes.get(entry.getKey()))) changes.add(entry.getKey(), entry.getValue());
                    }
                    frame.add("attributes", changes);
                }
            }
            result.add("defaults", defaults);
            result.add("attribute_phases", phases);
        }
        Files.writeString(Path.of(args[0]), new GsonBuilder().setPrettyPrinting().create().toJson(result) + "\n");
        System.out.println("Exported native difficulty, long casts, modifier maps and zombie initialization attribute phases");
    }
}
