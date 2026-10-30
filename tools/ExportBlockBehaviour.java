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
        root.addProperty("bits", "1 air, 2 solid, 4 blocks motion in heightmaps, 8 replaceable, 16<<d full sturdy face, 1024<<d center sturdy face (d: down, up, north, south, west, east), 65536 water, 131072 lava, 262144 fluid source, 524288 full collision cube, 1048576 can occlude, 2097152 blocks motion in heightmaps without leaves, 4194304 bottom face covers a wall post, 8388608<<s bottom face covers a wall side (s: north, east, south, west)");
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
        Files.writeString(Path.of(output), new Gson().toJson(root) + "\n");
    }
}
