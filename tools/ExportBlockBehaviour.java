import java.nio.ByteBuffer;
import java.nio.file.*;
import java.util.Base64;
import com.google.gson.*;
import net.minecraft.core.BlockPos;
import net.minecraft.core.Direction;
import net.minecraft.world.entity.EntityTypes;
import net.minecraft.world.level.EmptyBlockGetter;
import net.minecraft.world.level.NaturalSpawner;
import net.minecraft.world.level.pathfinder.PathComputationType;
import net.minecraft.world.level.block.Block;
import net.minecraft.world.level.block.SupportType;
import net.minecraft.world.level.material.Fluids;
import net.minecraft.world.phys.shapes.BooleanOp;
import net.minecraft.world.phys.shapes.Shapes;
import net.minecraft.world.phys.shapes.VoxelShape;

/**
 * Per block state behaviour that world generation tests: air, isSolid, blocksMotion,
 * canBeReplaced, sturdy faces (full and center support) and fluid. One little-endian int per
 * state id; bit layout in the "bits" field.
 */
public class ExportBlockBehaviour {
    /** WallBlock.TEST_SHAPE_POST and TEST_SHAPES_WALL */
    private static final VoxelShape WALL_POST = Block.column(2.0, 0.0, 16.0);
    private static final java.util.Map<Direction, VoxelShape> WALL_SIDES = Shapes.rotateHorizontal(Block.boxZ(2.0, 16.0, 0.0, 9.0));

    public static void main(String[] args) throws Exception {
        if (args.length != 1) throw new IllegalArgumentException("Expected output path");
        net.minecraft.SharedConstants.tryDetectVersion();
        net.minecraft.server.Bootstrap.bootStrap();
        // Tags are bound once the vanilla data pack is loaded.
        try (var data = ReferenceWorldData.load()) {
            export(args[0]);
        }
    }

    private static void export(String output) throws Exception {
        int count = Block.BLOCK_STATE_REGISTRY.size();
        var flags = ByteBuffer.allocate(count * 4).order(java.nio.ByteOrder.LITTLE_ENDIAN);
        var spawning = new byte[count * 2];
        for (int id = 0; id < count; ++id) {
            var state = Block.BLOCK_STATE_REGISTRY.byId(id);
            int bits = 0;
            if (state.isAir()) bits |= 1;
            if (state.isSolid()) bits |= 2;
            if (state.is(net.minecraft.tags.BlockTags.BLOCKS_MOTION_IN_HEIGHTMAP)) bits |= 4;
            if (state.canBeReplaced()) bits |= 8;
            for (Direction direction : Direction.values()) {
                if (state.isFaceSturdy(EmptyBlockGetter.INSTANCE, BlockPos.ZERO, direction, SupportType.FULL)) bits |= 16 << direction.get3DDataValue();
                if (state.isFaceSturdy(EmptyBlockGetter.INSTANCE, BlockPos.ZERO, direction, SupportType.CENTER)) bits |= 1024 << direction.get3DDataValue();
            }
            var fluid = state.getFluidState();
            if (fluid.is(net.minecraft.tags.FluidTags.WATER)) bits |= 1 << 16;
            if (fluid.is(net.minecraft.tags.FluidTags.LAVA)) bits |= 1 << 17;
            if (fluid.isSource()) bits |= 1 << 18;
            if (state.isCollisionShapeFullBlock(EmptyBlockGetter.INSTANCE, BlockPos.ZERO)) bits |= 1 << 19;
            if (state.canOcclude()) bits |= 1 << 20;
            if (state.is(net.minecraft.tags.BlockTags.BLOCKS_MOTION_IN_HEIGHTMAP_NO_LEAVES)) bits |= 1 << 21;
            // WallBlock.isCovered: whether the bottom face of the state's collision shape covers a
            // wall's post, and its north, east, south and west sides.
            var down = state.getCollisionShape(EmptyBlockGetter.INSTANCE, BlockPos.ZERO).getFaceShape(Direction.DOWN);
            if (!Shapes.joinIsNotEmpty(WALL_POST, down, BooleanOp.ONLY_FIRST)) bits |= 1 << 22;
            Direction[] sides = {Direction.NORTH, Direction.EAST, Direction.SOUTH, Direction.WEST};
            for (int i = 0; i < 4; ++i) {
                if (!Shapes.joinIsNotEmpty(WALL_SIDES.get(sides[i]), down, BooleanOp.ONLY_FIRST)) bits |= 1 << (23 + i);
            }
            if (state.isSolidRender()) bits |= 1 << 27;
            // Redstone: BlockState.isRedstoneConductor, isSignalSource and hasAnalogOutputSignal.
            if (state.isRedstoneConductor(EmptyBlockGetter.INSTANCE, BlockPos.ZERO)) bits |= 1 << 28;
            if (state.isSignalSource()) bits |= 1 << 29;
            if (state.hasAnalogOutputSignal()) bits |= 1 << 30;
            flags.putInt(bits);
            // Light and natural spawning: getLightDampening, propagatesSkylightDown and pathfinding on land, then the light
            // emission and the spawn checks generation runs for creatures (a cow stands for those
            // without their own BlockBehaviour.isValidSpawn rule), parrots and polar bears.
            spawning[id * 2] = (byte) (state.getLightDampening() | (state.propagatesSkylightDown() ? 16 : 0) | (state.isPathfindable(PathComputationType.LAND) ? 32 : 0));
            spawning[id * 2 + 1] = (byte) (state.getLightEmission()
                | (state.isValidSpawn(EmptyBlockGetter.INSTANCE, BlockPos.ZERO, EntityTypes.COW) ? 16 : 0)
                | (state.isValidSpawn(EmptyBlockGetter.INSTANCE, BlockPos.ZERO, EntityTypes.PARROT) ? 32 : 0)
                | (state.isValidSpawn(EmptyBlockGetter.INSTANCE, BlockPos.ZERO, EntityTypes.POLAR_BEAR) ? 64 : 0)
                | (NaturalSpawner.isValidEmptySpawnBlock(EmptyBlockGetter.INSTANCE, BlockPos.ZERO, state, state.getFluidState(), EntityTypes.COW) ? 128 : 0));
        }
        var root = new JsonObject();
        root.addProperty("version", net.minecraft.SharedConstants.getCurrentVersion().id());
        root.addProperty("bits", "1 air, 2 solid, 4 blocks motion in heightmaps, 8 replaceable, 16<<d full sturdy face, 1024<<d center sturdy face (d: down, up, north, south, west, east), 65536 water, 131072 lava, 262144 fluid source, 524288 full collision cube, 1048576 can occlude, 2097152 blocks motion in heightmaps without leaves, 4194304 bottom face covers a wall post, 8388608<<s bottom face covers a wall side (s: north, east, south, west), 134217728 solid render, 268435456 redstone conductor, 536870912 signal source, 1073741824 analog output");
        root.addProperty("states", count);
        root.addProperty("flags", Base64.getEncoder().encodeToString(flags.array()));
        // Block classes decide survival and placement rules; plain blocks are omitted.
        var classes = new JsonObject();
        for (Block block : net.minecraft.core.registries.BuiltInRegistries.BLOCK) {
            if (block.getClass() == Block.class) continue;
            var chain = new JsonArray();
            for (Class<?> type = block.getClass(); type != Block.class && type != Object.class; type = type.getSuperclass()) chain.add(type.getSimpleName());
            classes.add(net.minecraft.core.registries.BuiltInRegistries.BLOCK.getKey(block).getPath(), chain);
        }
        root.add("classes", classes);
        root.addProperty("spawning_bits", "two bytes per state: light dampening | 16 propagates skylight down | 32 pathfindable on land; light emission | 16 valid spawn for a creature | 32 for a parrot | 64 for a polar bear | 128 valid empty spawn block");
        root.addProperty("spawning", Base64.getEncoder().encodeToString(spawning));
        // FireBlock.bootStrap: ignite and burn odds of flammable blocks.
        var fire = (net.minecraft.world.level.block.FireBlock) net.minecraft.world.level.block.Blocks.FIRE;
        var igniteField = net.minecraft.world.level.block.FireBlock.class.getDeclaredField("igniteOdds");
        var burnField = net.minecraft.world.level.block.FireBlock.class.getDeclaredField("burnOdds");
        igniteField.setAccessible(true);
        burnField.setAccessible(true);
        var ignite = (it.unimi.dsi.fastutil.objects.Object2IntMap<?>) igniteField.get(fire);
        var burn = (it.unimi.dsi.fastutil.objects.Object2IntMap<?>) burnField.get(fire);
        var flammable = new JsonObject();
        for (Block block : net.minecraft.core.registries.BuiltInRegistries.BLOCK) {
            if (ignite.containsKey(block)) {
                var odds = new JsonArray();
                odds.add(ignite.getInt(block));
                odds.add(burn.getInt(block));
                flammable.add(net.minecraft.core.registries.BuiltInRegistries.BLOCK.getKey(block).getPath(), odds);
            }
        }
        root.add("flammable", flammable);
        // BlockBehaviour.Properties.ignitedByLava: blocks lava sets fire beside.
        var lava = new JsonArray();
        for (Block block : net.minecraft.core.registries.BuiltInRegistries.BLOCK) {
            if (block.defaultBlockState().ignitedByLava()) lava.add(net.minecraft.core.registries.BuiltInRegistries.BLOCK.getKey(block).getPath());
        }
        root.add("ignited_by_lava", lava);
        // PistonBaseBlock.isPushable: each state's PushReaction other than PUSH_PULL, the states that
        // cannot be broken (destroy speed -1), and the blocks with block entities.
        var reactions = new JsonObject();
        var unbreakable = new JsonArray();
        for (int id = 0; id < Block.BLOCK_STATE_REGISTRY.size(); ++id) {
            var state = Block.BLOCK_STATE_REGISTRY.byId(id);
            var reaction = state.getPistonPushReaction();
            if (reaction != net.minecraft.world.level.material.PushReaction.PUSH_PULL) reactions.addProperty(Integer.toString(id), reaction.name().toLowerCase(java.util.Locale.ROOT));
            if (state.getDestroySpeed(EmptyBlockGetter.INSTANCE, BlockPos.ZERO) == -1.0F) unbreakable.add(id);
        }
        var entityBlocks = new JsonArray();
        for (Block block : net.minecraft.core.registries.BuiltInRegistries.BLOCK) {
            if (block.defaultBlockState().hasBlockEntity()) entityBlocks.add(net.minecraft.core.registries.BuiltInRegistries.BLOCK.getKey(block).getPath());
        }
        var piston = new JsonObject();
        piston.add("push_reactions", reactions);
        piston.add("unbreakable", unbreakable);
        piston.add("block_entities", entityBlocks);
        root.add("piston", piston);
        // Block.getExplosionResistance, which explosion rays lose power to.
        var resistance = new JsonObject();
        for (Block block : net.minecraft.core.registries.BuiltInRegistries.BLOCK) {
            resistance.addProperty(net.minecraft.core.registries.BuiltInRegistries.BLOCK.getKey(block).getPath(), block.getExplosionResistance());
        }
        root.add("explosion_resistance", resistance);
        // LightEngine.getOcclusionShape: for states that use their shape for light occlusion, each
        // face's occlusion shape (down, up, north, south, west, east) as a 16×16 coverage mask,
        // u then v: x, z on y faces; x, y on z faces; z, y on x faces.
        var masks = new java.util.LinkedHashMap<String, Integer>();
        var maskList = new JsonArray();
        var shaped = new JsonObject();
        for (int id = 0; id < count; ++id) {
            var state = Block.BLOCK_STATE_REGISTRY.byId(id);
            if (!state.canOcclude() || !state.useShapeForLightOcclusion()) continue;
            var faces = new JsonArray();
            for (Direction direction : Direction.values()) {
                VoxelShape face = state.getFaceOcclusionShape(direction);
                var bits = new byte[32];
                for (int v = 0; v < 16; ++v) {
                    for (int u = 0; u < 16; ++u) {
                        double u0 = (u + 0.01) / 16, u1 = (u + 0.99) / 16, v0 = (v + 0.01) / 16, v1 = (v + 0.99) / 16;
                        VoxelShape cell = switch (direction.getAxis()) {
                            case Y -> Shapes.box(u0, 0, v0, u1, 1, v1);
                            case Z -> Shapes.box(u0, v0, 0, u1, v1, 1);
                            case X -> Shapes.box(0, v0, u0, 1, v1, u1);
                        };
                        if (Shapes.joinIsNotEmpty(face, cell, BooleanOp.AND)) bits[(v * 16 + u) >> 3] |= (byte) (1 << ((v * 16 + u) & 7));
                    }
                }
                String key = Base64.getEncoder().encodeToString(bits);
                Integer index = masks.get(key);
                if (index == null) {
                    index = masks.size();
                    masks.put(key, index);
                    maskList.add(key);
                }
                faces.add(index);
            }
            shaped.add(Integer.toString(id), faces);
        }
        var lightFaces = new JsonObject();
        lightFaces.add("masks", maskList);
        lightFaces.add("states", shaped);
        root.add("light_faces", lightFaces);
        Files.writeString(Path.of(output), new Gson().toJson(root) + "\n");
    }
}
