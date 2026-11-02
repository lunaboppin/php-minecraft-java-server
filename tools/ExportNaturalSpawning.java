import java.nio.file.*;
import java.util.*;
import com.google.gson.*;
import com.mojang.serialization.JsonOps;
import net.minecraft.SharedConstants;
import net.minecraft.core.BlockPos;
import net.minecraft.core.registries.BuiltInRegistries;
import net.minecraft.core.registries.Registries;
import net.minecraft.resources.Identifier;
import net.minecraft.resources.ResourceKey;
import net.minecraft.server.Bootstrap;
import net.minecraft.tags.BlockTags;
import net.minecraft.tags.FluidTags;
import net.minecraft.util.valueproviders.IntProviders;
import net.minecraft.world.entity.EntitySpawnReason;
import net.minecraft.world.entity.EntityType;
import net.minecraft.world.entity.Mob;
import net.minecraft.world.entity.SpawnPlacementTypes;
import net.minecraft.world.entity.SpawnPlacements;
import net.minecraft.world.level.EmptyBlockGetter;
import net.minecraft.world.level.block.Block;
import net.minecraft.world.level.block.state.BlockState;

/**
 * NaturalSpawner's per-type and per-state inputs: each spawnable mob type's placement, heightmap,
 * far-spawn and cluster limits; for every block state, as bitsets in state ID order shared by
 * identical sets, whether each type may stand on it and whether it is dangerous to that type,
 * and the state properties the spawn checks read; and each dimension's monster light rules.
 */
public class ExportNaturalSpawning {
    static final List<String> BITSETS = new ArrayList<>();

    static int bitset(BitSet bits, int size) {
        var bytes = Arrays.copyOf(bits.toByteArray(), (size + 7) / 8);
        var encoded = Base64.getEncoder().encodeToString(bytes);
        int index = BITSETS.indexOf(encoded);
        if (index < 0) {
            BITSETS.add(encoded);
            index = BITSETS.size() - 1;
        }
        return index;
    }

    interface StateTest { boolean test(BlockState state); }

    static int states(StateTest test) {
        var bits = new BitSet();
        int size = Block.BLOCK_STATE_REGISTRY.size();
        for (int id = 0; id < size; id++) {
            if (test.test(Block.BLOCK_STATE_REGISTRY.byId(id))) bits.set(id);
        }
        return bitset(bits, size);
    }

    public static void main(String[] args) throws Throwable {
        if (args.length != 1) throw new IllegalArgumentException("Expected output path");
        SharedConstants.tryDetectVersion(); Bootstrap.bootStrap();
        var root = new JsonObject();
        root.addProperty("version", SharedConstants.getCurrentVersion().id());
        root.addProperty("states", Block.BLOCK_STATE_REGISTRY.size());
        var getter = EmptyBlockGetter.INSTANCE;
        var origin = BlockPos.ZERO;
        try (var data = ReferenceWorldData.load()) {
            var access = data.access();
            var ops = net.minecraft.resources.RegistryOps.create(JsonOps.INSTANCE, access);
            var blocks = new JsonObject();
            blocks.addProperty("full_collision", states(state -> state.isCollisionShapeFullBlock(getter, origin)));
            blocks.addProperty("signal_source", states(state -> state.isSignalSource()));
            blocks.addProperty("redstone_conductor", states(state -> state.isRedstoneConductor(getter, origin)));
            blocks.addProperty("prevent_spawn_inside", states(state -> state.is(BlockTags.PREVENT_MOB_SPAWNING_INSIDE)));
            blocks.addProperty("water", states(state -> state.getFluidState().is(FluidTags.WATER)));
            blocks.addProperty("lava", states(state -> state.getFluidState().is(FluidTags.LAVA)));
            blocks.addProperty("fluid", states(state -> !state.getFluidState().isEmpty()));
            var tags = new JsonObject();
            for (var tag : BuiltInRegistries.BLOCK.getTags().map(named -> named.key()).toList()) {
                var path = tag.location().getPath();
                if (path.endsWith("spawnable_on") || path.equals("valid_spawn")) {
                    tags.addProperty(tag.location().toString(), states(state -> state.is(tag)));
                }
            }
            blocks.add("tags", tags);
            root.add("blocks", blocks);

            var level = ExportMobInitialization.createLevel(access);
            var mobs = new JsonObject();
            var registered = SpawnPlacements.class.getDeclaredField("DATA_BY_TYPE");
            registered.setAccessible(true);
            var placements = (Map<?, ?>) registered.get(null);
            for (var type : BuiltInRegistries.ENTITY_TYPE) {
                if (!placements.containsKey(type)) continue;
                var mob = new JsonObject();
                var placement = SpawnPlacements.getPlacementType(type);
                mob.addProperty("placement", placement == SpawnPlacementTypes.ON_GROUND ? "on_ground" : placement == SpawnPlacementTypes.IN_WATER ? "in_water" : placement == SpawnPlacementTypes.IN_LAVA ? "in_lava" : "no_restrictions");
                mob.addProperty("heightmap", SpawnPlacements.getHeightmapType(type).getSerializationKey());
                mob.addProperty("category", type.getCategory().getName());
                mob.addProperty("far", type.canSpawnFarFromPlayer());
                mob.addProperty("summon", type.canSummon());
                mob.addProperty("valid_spawn", states(state -> state.isValidSpawn(getter, origin, type)));
                mob.addProperty("dangerous", states(state -> type.isBlockDangerous(state)));
                // A few types need a whole level to be built; none of those spawn naturally, and
                // they keep Mob's defaults: clusters of 4 and no group limit.
                Object created;
                try {
                    created = type.create(level, EntitySpawnReason.NATURAL);
                } catch (RuntimeException failure) {
                    created = null;
                }
                if (created instanceof Mob instance) {
                    mob.addProperty("cluster", instance.getMaxSpawnClusterSize());
                    for (int size = 0; size <= 64; size++) {
                        if (instance.isMaxGroupSizeReached(size)) {
                            mob.addProperty("max_group", size);
                            break;
                        }
                    }
                }
                mobs.add(BuiltInRegistries.ENTITY_TYPE.getKey(type).getPath(), mob);
            }
            root.add("mobs", mobs);

            var dimensions = new JsonObject();
            var types = access.lookupOrThrow(Registries.DIMENSION_TYPE);
            for (var name : List.of("overworld", "the_nether", "the_end")) {
                var type = types.getOrThrow(ResourceKey.create(Registries.DIMENSION_TYPE, Identifier.withDefaultNamespace(name))).value();
                var dimension = new JsonObject();
                dimension.addProperty("block_light_limit", type.monsterSpawnBlockLightLimit());
                dimension.add("light_test", IntProviders.CODEC.encodeStart(ops, type.monsterSpawnLightTest()).getOrThrow());
                dimensions.add(name, dimension);
            }
            root.add("dimensions", dimensions);
        }
        var bitsets = new JsonArray();
        BITSETS.forEach(bitsets::add);
        root.add("bitsets", bitsets);
        Files.writeString(Path.of(args[0]), new GsonBuilder().setPrettyPrinting().create().toJson(root) + "\n");
    }
}
