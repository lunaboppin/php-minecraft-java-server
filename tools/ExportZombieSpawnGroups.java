import java.lang.reflect.*;
import java.lang.invoke.MethodHandles;
import java.nio.file.*;
import java.util.*;
import java.util.function.Predicate;
import com.google.gson.*;
import net.minecraft.SharedConstants;
import net.minecraft.core.*;
import net.minecraft.core.registries.*;
import net.minecraft.resources.Identifier;
import net.minecraft.server.Bootstrap;
import net.minecraft.util.RandomSource;
import net.minecraft.world.*;
import net.minecraft.world.attribute.EnvironmentAttributeReader;
import net.minecraft.world.entity.*;
import net.minecraft.world.entity.animal.chicken.Chicken;
import net.minecraft.world.entity.monster.zombie.Zombie;
import net.minecraft.world.entity.variant.PriorityProvider;
import net.minecraft.world.entity.variant.SpawnContext;
import net.minecraft.world.level.*;
import net.minecraft.world.level.levelgen.LegacyRandomSource;
import net.minecraft.world.phys.AABB;

/** Observe the actual finalizeSpawn prefix, before equipment/holiday/attribute phases. */
public class ExportZombieSpawnGroups {
    static final StackWalker WALKER = StackWalker.getInstance();

    static JsonObject chickenState(Chicken chicken, JsonObject initialAttributes) throws ReflectiveOperationException {
        var state = new JsonObject();
        state.addProperty("variant", chicken.getVariant().unwrapKey().orElseThrow().identifier().toString());
        var soundGetter = Chicken.class.getDeclaredMethod("getSoundVariant");
        soundGetter.setAccessible(true);
        var sound = (Holder<?>) soundGetter.invoke(chicken);
        state.addProperty("sound_variant", sound.unwrapKey().orElseThrow().identifier().toString());
        state.addProperty("left_handed", chicken.isLeftHanded());
        state.addProperty("baby", chicken.isBaby());
        state.addProperty("jockey", chicken.isChickenJockey());
        var attributes = ExportMobInitialization.attributes(chicken);
        if (!attributes.keySet().equals(initialAttributes.keySet())) throw new IllegalStateException("Chicken attribute roster changed");
        var changes = new JsonObject();
        for (var entry : attributes.entrySet()) {
            if (!entry.getValue().equals(initialAttributes.get(entry.getKey()))) changes.add(entry.getKey(), entry.getValue());
        }
        state.add("attributes", changes);
        return state;
    }

    static class WorldRandom extends LegacyRandomSource {
        Zombie actor;
        JsonObject prefix;
        final JsonArray childDraws = new JsonArray();
        final JsonArray events = new JsonArray();
        boolean childSeen;
        boolean rideSeen;

        WorldRandom(long seed) { super(seed); }

        long nextLongWithoutAdvancing() {
            return ReferenceWorldData.peekNextLong(this);
        }

        void capture() {
            if (prefix != null || actor == null) return;
            prefix = new JsonObject();
            prefix.addProperty("baby", actor.isBaby());
            prefix.addProperty("pickup_loot", actor.canPickUpLoot());
            prefix.addProperty("left_handed", actor.isLeftHanded());
            prefix.addProperty("break_doors", actor.canBreakDoors());
            prefix.addProperty("ground_navigation", actor.getNavigation().canNavigateGround());
            prefix.addProperty("world_random_next", Long.toString(nextLongWithoutAdvancing()));
            var vehicle = actor.getVehicle();
            prefix.add("vehicle", vehicle == null ? JsonNull.INSTANCE : new JsonPrimitive(vehicle.getId()));
            if (vehicle != null) {
                prefix.addProperty("chicken_jockey", ((Chicken) vehicle).isChickenJockey());
                prefix.addProperty("passengers", vehicle.getPassengers().size());
            }
            var dimensions = actor.getDimensions(Pose.STANDING);
            prefix.add("dimensions_bits", ExportMobPhysics.bits(dimensions.width(), dimensions.height(), dimensions.eyeHeight()));
        }

        void recordRide() {
            if (!rideSeen && actor != null && actor.getVehicle() != null) {
                var event = new JsonObject();
                event.addProperty("type", "ride");
                event.addProperty("id", actor.getVehicle().getId());
                events.add(event);
                rideSeen = true;
            }
        }

        @Override
        public int next(int bits) {
            if (prefix == null) recordRide();
            if (actor != null && prefix == null) {
                boolean afterGroups = WALKER.walk(frames -> frames.anyMatch(frame ->
                    frame.getMethodName().startsWith("populateDefaultEquipment")
                    // In the pinned Zombie body, BCI 344 starts the subsequent
                    // head/holiday phase. Isolate the prefix on Halloween too.
                    || (frame.getClassName().equals(Zombie.class.getName())
                        && frame.getMethodName().equals("finalizeSpawn") && frame.getByteCodeIndex() >= 344)
                ));
                if (afterGroups) capture();
            }
            boolean chicken = prefix == null && actor != null && WALKER.walk(frames -> frames.anyMatch(frame ->
                frame.getClassName().equals(Chicken.class.getName()) && frame.getMethodName().equals("finalizeSpawn")
            ));
            if (chicken && !childSeen) {
                var event = new JsonObject();
                event.addProperty("type", "initialize_chicken");
                events.add(event);
                childSeen = true;
            }
            int value = super.next(bits);
            if (chicken) {
                var row = new JsonObject();
                row.addProperty("bits", bits);
                row.addProperty("value", value);
                childDraws.add(row);
            }
            return value;
        }
    }

    static class EntityRandom extends LegacyRandomSource {
        WorldRandom world;

        EntityRandom(long seed, WorldRandom world) {
            super(seed);
            this.world = world;
        }

        @Override
        public int next(int bits) {
            if (world != null && world.prefix == null && WALKER.walk(frames -> frames.anyMatch(frame ->
                frame.getClassName().equals(Zombie.class.getName()) && frame.getMethodName().equals("handleAttributes")
            ))) world.capture();
            return super.next(bits);
        }
    }

    @SuppressWarnings("unchecked")
    public static void main(String[] args) throws Throwable {
        if (args.length != 1) throw new IllegalArgumentException("Expected output fixture path");
        SharedConstants.tryDetectVersion();
        Bootstrap.bootStrap();
        var result = new JsonObject();
        result.addProperty("version", SharedConstants.getCurrentVersion().id());
        result.addProperty("protocol", SharedConstants.getCurrentVersion().protocolVersion());
        var cases = new JsonArray();
        var virtualContracts = new JsonArray();
        var chickenCases = new JsonArray();
        try (var data = ReferenceWorldData.load()) {
            var level = ExportMobInitialization.createLevel(data.access());
            var visualCandidates = new JsonObject();
            var variants = data.access().lookupOrThrow(Registries.CHICKEN_VARIANT);
            for (var biome : data.access().lookupOrThrow(Registries.BIOME).listElements().toList()) {
                var context = new SpawnContext(new BlockPos(0, 64, 0), level, EnvironmentAttributeReader.EMPTY, biome);
                var names = new JsonArray();
                PriorityProvider.select(variants.listElements(), entry -> entry.value(), context)
                    .forEach(entry -> names.add(entry.key().identifier().toString()));
                visualCandidates.add(biome.key().identifier().toString(), names);
            }
            result.add("chicken_visual_candidates", visualCandidates);
            var sounds = new JsonArray();
            data.access().lookupOrThrow(Registries.CHICKEN_SOUND_VARIANT).listElements()
                .forEach(entry -> sounds.add(entry.key().identifier().toString()));
            result.add("chicken_sound_variants", sounds);
            var chickenInitialAttributes = ExportMobInitialization.attributes(new Chicken(EntityTypes.CHICKEN, level));
            result.add("chicken_initial_attributes", chickenInitialAttributes);
            var bodyMethod = Zombie.class.getDeclaredMethod("finalizeSpawn", ServerLevelAccessor.class, DifficultyInstance.class, EntitySpawnReason.class, SpawnGroupData.class);
            var body = MethodHandles.privateLookupIn(Zombie.class, MethodHandles.lookup()).unreflectSpecial(bodyMethod, Zombie.class);
            for (String kind : List.of("zombie", "husk", "drowned", "zombified_piglin", "zombie_villager")) {
                for (long seed : new long[]{0, 1, 2, 11, 16, 34, 42, 76, 85, 108, 117, 771, 813, 868, 977, 1045, 1150, 1158, 1623, 1960}) {
                    for (int groupMode = -1; groupMode < 4; groupMode++) {
                        for (int layout = 0; layout < 4; layout++) {
                            for (var reason : new EntitySpawnReason[]{EntitySpawnReason.SPAWN_ITEM_USE, EntitySpawnReason.CONVERSION, EntitySpawnReason.LOAD}) {
                                for (boolean hard : new boolean[]{false, true}) {
                                    level.difficulty = hard ? new DifficultyInstance(Difficulty.HARD, 1440000, 3600000, 1)
                                        : new DifficultyInstance(Difficulty.NORMAL, 18000, 0, 1);
                                    ExportMobPhysics.field(level, Level.class, "random", RandomSource.create(seed));
                                    var type = BuiltInRegistries.ENTITY_TYPE.getValue(Identifier.withDefaultNamespace(kind));
                                    var actor = (Zombie) type.create(level, reason);
                                    actor.setId(17);
                                    actor.snapTo(.5, 64, .5, 0, 0);
                                    boolean initialBaby = groupMode == 0 && (seed & 1) != 0;
                                    actor.setBaby(initialBaby);
                                    actor.setCanPickUpLoot((seed & 1) != 0);
                                    actor.setCanBreakDoors((seed & 2) != 0);
                                    boolean initialDoors = actor.canBreakDoors();
                                    var chickens = new ArrayList<Chicken>();
                                    int count = layout == 0 ? 0 : layout == 1 ? 1 : 3;
                                    for (int index = 0; index < count; index++) {
                                        var chicken = new Chicken(EntityTypes.CHICKEN, level);
                                        chicken.setId(100 + index);
                                        chicken.snapTo(layout == 3 && index == 2 ? 20.5 : .5, 64, .5, 0, 0);
                                        if (layout == 3 && index == 0) {
                                            var occupant = new Zombie(EntityTypes.ZOMBIE, level);
                                            occupant.setId(50);
                                            if (!occupant.startRiding(chicken, false, false)) throw new IllegalStateException("Failed to occupy fixture chicken");
                                        }
                                        chickens.add(chicken);
                                    }
                                    var random = new WorldRandom(seed);
                                    random.actor = actor;
                                    ExportMobPhysics.field(level, Level.class, "random", random);
                                    ExportMobPhysics.field(actor, Entity.class, "random", new EntityRandom(seed + 10000, random));
                                    var queries = new JsonArray();
                                    var spawned = new ArrayList<Entity>();
                                    var world = (ServerLevelAccessor) Proxy.newProxyInstance(ServerLevelAccessor.class.getClassLoader(), new Class[]{ServerLevelAccessor.class}, (proxy, method, values) -> switch (method.getName()) {
                                        case "environmentAttributes" -> EnvironmentAttributeReader.EMPTY;
                                        case "getRandom" -> random;
                                        case "getLevel" -> level;
                                        case "getDifficulty" -> level.difficulty.getDifficulty();
                                        case "getCurrentDifficultyAt" -> level.difficulty;
                                        case "getBiome" -> level.biome;
                                        case "registryAccess" -> data.access();
                                        case "getEntitiesOfClass" -> {
                                            var found = new ArrayList<Chicken>();
                                            var box = (AABB) values[1];
                                            for (var chicken : chickens) {
                                                if (((Class<?>) values[0]).isInstance(chicken) && box.intersects(chicken.getBoundingBox())
                                                    && ((Predicate<Entity>) values[2]).test(chicken)) found.add(chicken);
                                            }
                                            var query = new JsonObject();
                                            var ids = new JsonArray();
                                            found.forEach(chicken -> ids.add(chicken.getId()));
                                            query.add("eligible", ids);
                                            query.add("bounds_bits", ExportMobPhysics.bits(box.minX, box.minY, box.minZ, box.maxX, box.maxY, box.maxZ));
                                            queries.add(query);
                                            var event = new JsonObject();
                                            event.addProperty("type", "query");
                                            random.events.add(event);
                                            yield found;
                                        }
                                        case "addFreshEntity" -> {
                                            var child = (Entity) values[0];
                                            if (!(child instanceof Chicken) || !spawned.isEmpty()) throw new IllegalStateException("Unexpected child entity");
                                            child.setId(200);
                                            random.recordRide();
                                            spawned.add(child);
                                            var event = new JsonObject();
                                            event.addProperty("type", "add");
                                            event.addProperty("id", child.getId());
                                            random.events.add(event);
                                            yield true;
                                        }
                                        default -> throw new UnsupportedOperationException("Unexpected world call: " + method.getName());
                                    });
                                    SpawnGroupData inputGroup = groupMode < 0 ? null : groupMode == 3 ? new AgeableMob.AgeableMobGroupData(true)
                                        : new Zombie.ZombieGroupData(groupMode > 0, groupMode != 2);
                                    // Isolate the actual shared Zombie body. Virtual
                                    // subclass post-processing is a separate phase.
                                    var outputGroup = body.bindTo(actor).invoke(world, level.difficulty, reason, inputGroup);
                                    if (random.prefix == null) throw new IllegalStateException("The actual initialization phase boundary was not observed");
                                    var row = new JsonObject();
                                    row.addProperty("kind", kind);
                                    row.addProperty("seed", Long.toString(seed));
                                    row.addProperty("group", groupMode);
                                    row.addProperty("layout", layout);
                                    row.addProperty("reason", reason.name().toLowerCase(Locale.ROOT));
                                    row.addProperty("special_bits", ExportMobInitialization.floatBits(level.difficulty.getSpecialMultiplier()));
                                    row.addProperty("initial_baby", initialBaby);
                                    row.addProperty("initial_pickup", (seed & 1) != 0);
                                    row.addProperty("initial_doors", initialDoors);
                                    row.addProperty("same_group", outputGroup == inputGroup);
                                    if (outputGroup instanceof Zombie.ZombieGroupData group) {
                                        row.addProperty("group_baby", group.isBaby);
                                        row.addProperty("group_jockey", group.canSpawnJockey);
                                    }
                                    row.add("prefix", random.prefix);
                                    row.add("queries", queries);
                                    row.add("child_draws", random.childDraws);
                                    row.add("events", random.events.deepCopy());
                                    row.addProperty("created", spawned.size());
                                    if (!spawned.isEmpty()) row.add("chicken", chickenState((Chicken) spawned.getFirst(), chickenInitialAttributes));
                                    cases.add(row);

                                    // Retain the incompatible-group virtual contract
                                    // discovered during the initial full-call probe.
                                    if (groupMode == 3 && layout == 0 && seed == 0 && !hard) {
                                        var contract = new JsonObject();
                                        contract.addProperty("kind", kind);
                                        contract.addProperty("reason", reason.name().toLowerCase(Locale.ROOT));
                                        contract.addProperty("after_base_body", true);
                                        try {
                                            var returned = actor.finalizeSpawn(world, level.difficulty, reason, inputGroup);
                                            contract.addProperty("result_class", returned.getClass().getName());
                                        } catch (ClassCastException failure) {
                                            contract.addProperty("error_class", failure.getClass().getName());
                                        }
                                        virtualContracts.add(contract);
                                    }
                                }
                            }
                        }
                    }
                }
            }
            // Exercise actual fresh-chicken initialization in every vanilla
            // biome, independently of the parent group's selected branch.
            for (var biome : data.access().lookupOrThrow(Registries.BIOME).listElements().toList()) {
                level.biome = biome;
                for (long seed : new long[]{0, 1, 2, 11, 16, 34, 42, 76, 85, 108, 117, 771, 813, 868, 1150, 1960}) {
                    var actor = new Chicken(EntityTypes.CHICKEN, level);
                    actor.snapTo(.5, 64, .5, 0, 0);
                    var random = RandomSource.create(seed);
                    ExportMobPhysics.field(level, Level.class, "random", random);
                    var world = (ServerLevelAccessor) Proxy.newProxyInstance(ServerLevelAccessor.class.getClassLoader(), new Class[]{ServerLevelAccessor.class}, (proxy, method, values) -> switch (method.getName()) {
                        case "environmentAttributes" -> EnvironmentAttributeReader.EMPTY;
                        case "getRandom" -> random;
                        case "getLevel" -> level;
                        case "getDifficulty" -> level.difficulty.getDifficulty();
                        case "getCurrentDifficultyAt" -> level.difficulty;
                        case "getBiome" -> biome;
                        case "registryAccess" -> data.access();
                        default -> throw new UnsupportedOperationException("Unexpected chicken initialization call: " + method.getName());
                    });
                    actor.finalizeSpawn(world, level.difficulty, EntitySpawnReason.JOCKEY, null);
                    actor.setChickenJockey(true);
                    var row = new JsonObject();
                    row.addProperty("biome", biome.key().identifier().toString());
                    row.addProperty("seed", Long.toString(seed));
                    row.add("state", chickenState(actor, chickenInitialAttributes));
                    row.addProperty("world_random_next", Long.toString(random.nextLong()));
                    chickenCases.add(row);
                }
            }
        }
        result.add("cases", cases);
        result.add("virtual_group_contracts", virtualContracts);
        result.add("chicken_cases", chickenCases);
        Files.writeString(Path.of(args[0]), new GsonBuilder().serializeNulls().setPrettyPrinting().create().toJson(result) + "\n");
        System.out.println("Exported actual zombie spawn-group prefixes: " + cases.size());
    }
}
