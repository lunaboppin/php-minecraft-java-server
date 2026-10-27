import java.lang.reflect.*;
import java.nio.file.*;
import java.util.*;
import com.google.gson.*;
import net.minecraft.SharedConstants;
import net.minecraft.core.*;
import net.minecraft.server.Bootstrap;
import net.minecraft.server.level.ServerLevel;
import net.minecraft.tags.BlockTags;
import net.minecraft.util.RandomSource;
import net.minecraft.world.*;
import net.minecraft.world.entity.*;
import net.minecraft.world.entity.monster.Giant;
import net.minecraft.world.flag.*;
import net.minecraft.world.level.*;
import net.minecraft.world.level.biome.Biome;
import net.minecraft.world.level.block.*;
import net.minecraft.world.level.block.state.BlockState;
import net.minecraft.world.level.gameevent.GameEvent;
import net.minecraft.world.level.gamerules.GameRules;
import net.minecraft.world.phys.Vec3;

/** Actual LivingEntity eligibility and climbing calls against explicit block/ticking inputs. */
public class ExportPushEligibility {
    static final JsonArray RESULTS = new JsonArray();
    static final Map<String, Integer> RESULT_IDS = new LinkedHashMap<>();

    static class ProbeLevel extends ServerLevel {
        ExportMobInitialization.ProbeLevel context;
        BlockState feet;
        BlockState below;
        boolean ticking;
        int nextId;
        private ProbeLevel() { super(null, null, null, null, Level.OVERWORLD, null, false, 0, List.of(), true); }
        @Override public int getNextEntityId() { return ++nextId; }
        @Override public int getSeaLevel() { return 63; }
        @Override public void gameEvent(Holder<GameEvent> event, Vec3 position, GameEvent.Context eventContext) {}
        @Override public GameRules getGameRules() { return context.rules; }
        @Override public Holder<Biome> getBiome(BlockPos position) { return context.biome; }
        @Override public Difficulty getDifficulty() { return Difficulty.NORMAL; }
        @Override public FeatureFlagSet enabledFeatures() { return FeatureFlags.DEFAULT_FLAGS; }
        @Override public boolean isPositionEntityTicking(BlockPos position) { return ticking; }
        @Override public BlockState getBlockState(BlockPos position) {
            if (position.equals(new BlockPos(0, 64, 0))) return feet;
            if (position.equals(new BlockPos(0, 63, 0))) return below;
            return Blocks.AIR.defaultBlockState();
        }
    }

    static class Probe extends Giant {
        boolean spectator;
        boolean alwaysTicking;
        Probe(Level level) { super(EntityTypes.GIANT, level); }
        @Override public boolean isSpectator() { return spectator; }
        @Override public boolean isAlwaysTicking() { return alwaysTicking; }
        void flying(boolean value) { setSharedFlag(7, value); }
    }

    static ProbeLevel level(RegistryAccess.Frozen access) throws Exception {
        var base = ExportMobInitialization.createLevel(access);
        ExportMobPhysics.field(base, Level.class, "random", RandomSource.create(0));
        var unsafeField = sun.misc.Unsafe.class.getDeclaredField("theUnsafe"); unsafeField.setAccessible(true);
        var unsafe = (sun.misc.Unsafe) unsafeField.get(null);
        var result = (ProbeLevel) unsafe.allocateInstance(ProbeLevel.class);
        for (Class<?> owner = ServerLevel.class; owner != Object.class; owner = owner.getSuperclass()) {
            for (var field : owner.getDeclaredFields()) {
                if (Modifier.isStatic(field.getModifiers())) continue;
                field.setAccessible(true); field.set(result, field.get(base));
            }
        }
        result.context = base;
        result.feet = result.below = Blocks.AIR.defaultBlockState(); result.ticking = true;
        return result;
    }

    static JsonElement last(LivingEntity entity) throws Exception {
        var field = LivingEntity.class.getDeclaredField("lastClimbablePos"); field.setAccessible(true);
        var position = (Optional<BlockPos>) field.get(entity);
        if (position.isEmpty()) return JsonNull.INSTANCE;
        var row = new JsonArray(); row.add(position.get().getX()); row.add(position.get().getY()); row.add(position.get().getZ()); return row;
    }

    static JsonObject sample(ProbeLevel level, Probe actor, BlockState feet, BlockState below, int mask) throws Exception {
        level.feet = feet; level.below = below; level.ticking = (mask & 8) == 0;
        actor.setHealth((mask & 1) == 0 ? 20 : 0); actor.spectator = (mask & 2) != 0;
        actor.flying((mask & 4) != 0); actor.alwaysTicking = (mask & 16) != 0;
        if (actor.isFallFlying() != ((mask & 4) != 0)) throw new IllegalStateException("Native flying flag not set");
        var initialLast = (mask & 32) == 0 ? Optional.empty() : Optional.of(new BlockPos(9, 8, 7));
        ExportMobPhysics.field(actor, Entity.class, "inBlockState", null);
        ExportMobPhysics.field(actor, LivingEntity.class, "lastClimbablePos", initialLast);
        var result = new JsonObject();
        result.addProperty("on_climbable", actor.onClimbable()); result.add("climbing_last", last(actor));
        ExportMobPhysics.field(actor, LivingEntity.class, "lastClimbablePos", initialLast);
        result.addProperty("pushable", actor.isPushable()); result.add("pushable_last", last(actor));
        String key = result.toString();
        if (!RESULT_IDS.containsKey(key)) { RESULT_IDS.put(key, RESULTS.size()); RESULTS.add(result); }
        var row = new JsonObject(); row.addProperty("feet", Block.BLOCK_STATE_REGISTRY.getId(feet));
        row.addProperty("below", Block.BLOCK_STATE_REGISTRY.getId(below)); row.addProperty("mask", mask);
        row.addProperty("result", RESULT_IDS.get(key)); return row;
    }

    static JsonObject motion(LivingEntity entity) {
        var row = new JsonObject();
        row.add("position_bits", ExportMobPhysics.bits(entity.getX(), entity.getY(), entity.getZ()));
        var velocity = entity.getDeltaMovement(); row.add("velocity_bits", ExportMobPhysics.bits(velocity.x, velocity.y, velocity.z));
        row.addProperty("needs_sync", entity.needsSync); row.addProperty("sync_velocity", entity.syncVelocity);
        row.addProperty("pushable", entity.isPushable()); row.addProperty("no_physics", entity.noPhysics);
        row.addProperty("sleeping", entity.isSleeping()); row.addProperty("vehicle", entity.isVehicle());
        return row;
    }

    public static void main(String[] args) throws Exception {
        if (args.length != 1) throw new IllegalArgumentException("Expected output fixture path");
        SharedConstants.tryDetectVersion(); Bootstrap.bootStrap();
        var root = new JsonObject(); root.addProperty("version", SharedConstants.getCurrentVersion().id());
        root.addProperty("protocol", SharedConstants.getCurrentVersion().protocolVersion());
        var rules = root.deepCopy(); var cases = new JsonArray();
        var virtualCases = new JsonArray();
        var virtualPushes = new JsonArray();
        try (var data = ReferenceWorldData.load()) {
            var level = level(data.access()); var actor = new Probe(level); actor.setPos(0, 64, 0);
            var climbable = new JsonArray(); var glideThrough = new JsonArray(); var trapdoors = new JsonObject(); var ladders = new JsonObject();
            var selected = new LinkedHashSet<BlockState>(); selected.add(Blocks.AIR.defaultBlockState());
            for (var state : Block.BLOCK_STATE_REGISTRY) {
                int id = Block.BLOCK_STATE_REGISTRY.getId(state);
                if (state.is(BlockTags.CLIMBABLE)) climbable.add(id);
                if (state.is(BlockTags.CAN_GLIDE_THROUGH)) glideThrough.add(id);
                if (state.getBlock() instanceof TrapDoorBlock) {
                    var row = new JsonObject(); row.addProperty("open", state.getValue(TrapDoorBlock.OPEN));
                    row.addProperty("facing", state.getValue(TrapDoorBlock.FACING).getName()); trapdoors.add(Integer.toString(id), row);
                }
                if (state.is(Blocks.LADDER)) ladders.addProperty(Integer.toString(id), state.getValue(LadderBlock.FACING).getName());
                cases.add(sample(level, actor, state, Blocks.AIR.defaultBlockState(), 0));
                cases.add(sample(level, actor, state, Blocks.AIR.defaultBlockState(), 4));
            }
            for (var state : List.of(Blocks.LADDER.defaultBlockState(), Blocks.VINE.defaultBlockState(), Blocks.SCAFFOLDING.defaultBlockState(), Blocks.OAK_TRAPDOOR.defaultBlockState(), Blocks.OAK_TRAPDOOR.defaultBlockState().setValue(TrapDoorBlock.OPEN, true))) selected.add(state);
            var belowStates = new ArrayList<BlockState>(); belowStates.add(Blocks.AIR.defaultBlockState()); belowStates.addAll(Blocks.LADDER.getStateDefinition().getPossibleStates());
            for (var feet : Block.BLOCK_STATE_REGISTRY) if (feet.getBlock() instanceof TrapDoorBlock) {
                for (var below : belowStates) cases.add(sample(level, actor, feet, below, 0));
            }
            for (var feet : selected) for (var below : belowStates) for (int mask = 0; mask < 64; mask++) cases.add(sample(level, actor, feet, below, mask));
            var flying = Entity.class.getDeclaredMethod("setSharedFlag", int.class, boolean.class); flying.setAccessible(true);
            for (String kind : List.of("horse", "bat", "parrot", "creaking", "warden")) {
                var type = net.minecraft.core.registries.BuiltInRegistries.ENTITY_TYPE.getValue(net.minecraft.resources.Identifier.withDefaultNamespace(kind));
                var mob = (LivingEntity) type.create(level, new EntitySpawnRequest(EntitySpawnReason.SPAWN_ITEM_USE, true));
                if (mob == null) throw new IllegalStateException("Missing native virtual actor " + kind);
                mob.setPos(0, 64, 0);
                for (int flags = 0; flags < 16; flags++) for (var pose : List.of(Pose.STANDING, Pose.DIGGING, Pose.EMERGING)) {
                    for (boolean canMove : new boolean[]{false, true}) for (var feet : List.of(Blocks.AIR.defaultBlockState(), Blocks.LADDER.defaultBlockState())) {
                        level.feet = feet; level.below = Blocks.AIR.defaultBlockState(); level.ticking = (flags & 1) != 0;
                        mob.setHealth((flags & 2) == 0 ? 0 : 20); flying.invoke(mob, 7, (flags & 4) != 0); mob.setPose(pose);
                        ExportMobPhysics.field(mob, Entity.class, "passengers", (flags & 8) == 0 ? com.google.common.collect.ImmutableList.of() : com.google.common.collect.ImmutableList.of(actor));
                        if (mob instanceof net.minecraft.world.entity.monster.creaking.Creaking creaking) {
                            var field = creaking.getClass().getDeclaredField("CAN_MOVE"); field.setAccessible(true);
                            creaking.getEntityData().set((net.minecraft.network.syncher.EntityDataAccessor<Boolean>) field.get(null), canMove);
                        }
                        ExportMobPhysics.field(mob, Entity.class, "inBlockState", null);
                        ExportMobPhysics.field(mob, LivingEntity.class, "lastClimbablePos", Optional.of(new BlockPos(9, 8, 7)));
                        var row = new JsonObject(); row.addProperty("kind", kind); row.addProperty("alive", mob.isAlive());
                        row.addProperty("ticking", level.ticking); row.addProperty("vehicle", mob.isVehicle());
                        row.addProperty("fall_flying", mob.isFallFlying()); row.addProperty("pose", pose.name().toLowerCase(Locale.ROOT));
                        row.addProperty("can_move", canMove); row.addProperty("feet", Block.BLOCK_STATE_REGISTRY.getId(feet));
                        row.addProperty("below", Block.BLOCK_STATE_REGISTRY.getId(level.below));
                        row.addProperty("pushable", mob.isPushable()); row.add("last", last(mob)); virtualCases.add(row);
                    }
                }
            }
            level.feet = level.below = Blocks.AIR.defaultBlockState(); level.ticking = true;
            var shulker = (LivingEntity) net.minecraft.core.registries.BuiltInRegistries.ENTITY_TYPE.getValue(net.minecraft.resources.Identifier.withDefaultNamespace("shulker")).create(level, new EntitySpawnRequest(EntitySpawnReason.SPAWN_ITEM_USE, true));
            var nativeDoPush = LivingEntity.class.getDeclaredMethod("doPush", Entity.class); nativeDoPush.setAccessible(true);
            for (int action = 0; action < 3; action++) for (int flags = 0; flags < 8; flags++) {
                for (var offset : List.of(new Vec3(2, 0, 0), new Vec3(.5, 0, -2), Vec3.ZERO, new Vec3(.01F, 0, .25))) {
                    actor.setHealth(20); actor.spectator = false; actor.alwaysTicking = false; actor.flying(false);
                    ExportMobPhysics.field(actor, Entity.class, "passengers", com.google.common.collect.ImmutableList.of());
                    actor.setPos(.5 + offset.x, 64, .5 + offset.z); shulker.setPos(.5, 64, .5);
                    actor.setPose((flags & 4) == 0 ? Pose.STANDING : Pose.SLEEPING);
                    actor.noPhysics = (flags & 1) != 0; shulker.noPhysics = (flags & 2) != 0;
                    actor.setDeltaMovement(new Vec3(.2, -.0784, -.1)); shulker.setDeltaMovement(new Vec3(1, 2, 3));
                    actor.needsSync = shulker.needsSync = false; actor.syncVelocity = shulker.syncVelocity = true;
                    ExportMobPhysics.field(actor, Entity.class, "inBlockState", null);
                    ExportMobPhysics.field(shulker, Entity.class, "inBlockState", null);
                    var row = new JsonObject(); row.addProperty("action", action);
                    row.add("giant", motion(actor)); row.add("shulker", motion(shulker));
                    var frames = new JsonArray();
                    for (int repeat = 0; repeat < 2; repeat++) {
                        if (action == 0) actor.push(shulker);
                        else if (action == 1) shulker.push(actor);
                        else nativeDoPush.invoke(shulker, actor);
                        var frame = new JsonObject(); frame.add("giant", motion(actor)); frame.add("shulker", motion(shulker)); frames.add(frame);
                    }
                    row.add("frames", frames); virtualPushes.add(row);
                }
            }
            rules.add("climbable_states", climbable); rules.add("glide_through_states", glideThrough);
            rules.add("trapdoors", trapdoors); rules.add("ladders", ladders);
            var poseField = Avatar.class.getDeclaredField("POSES"); poseField.setAccessible(true);
            var poses = (Map<Pose, EntityDimensions>) poseField.get(null); var dimensions = new JsonObject();
            for (var entry : poses.entrySet()) {
                var row = new JsonArray(); row.add((double) entry.getValue().width()); row.add((double) entry.getValue().height());
                dimensions.add(entry.getKey().name().toLowerCase(Locale.ROOT), row);
            }
            rules.add("player_dimensions", dimensions);
        }
        root.addProperty("block_state_count", Block.BLOCK_STATE_REGISTRY.size()); root.add("cases", cases); root.add("results", RESULTS);
        root.add("virtual_cases", virtualCases);
        root.add("virtual_pushes", virtualPushes);
        var gson = new GsonBuilder().serializeNulls().setPrettyPrinting().create();
        Files.writeString(Path.of(args[0]), gson.toJson(root) + "\n");
        Files.writeString(Path.of(args[0]).resolveSibling("push-eligibility-rules.json"), gson.toJson(rules) + "\n");
        System.out.println("Exported " + cases.size() + " actual climbing/pushability contexts");
    }
}
