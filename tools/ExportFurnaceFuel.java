import java.lang.reflect.Method;
import java.nio.file.*;
import com.google.gson.*;
import net.minecraft.SharedConstants;
import net.minecraft.core.BlockPos;
import net.minecraft.core.component.DataComponents;
import net.minecraft.core.registries.BuiltInRegistries;
import net.minecraft.server.Bootstrap;
import net.minecraft.server.level.ServerLevel;
import net.minecraft.world.item.ItemStack;
import net.minecraft.world.level.block.Blocks;
import net.minecraft.world.level.block.entity.AbstractFurnaceBlockEntity;
import net.minecraft.world.level.block.entity.BlastFurnaceBlockEntity;
import net.minecraft.world.level.block.entity.FurnaceBlockEntity;
import net.minecraft.world.level.block.entity.SmokerBlockEntity;

/**
 * Each fuel's burn time and cooking speed multiplier as each furnace block reads them from the
 * item's cooking_fuel component (AbstractFurnaceBlockEntity.getBurnDuration and getSpeedMultiplier).
 */
public class ExportFurnaceFuel {
    public static void main(String[] args) throws Exception {
        if (args.length != 1) throw new IllegalArgumentException("Expected output path");
        SharedConstants.tryDetectVersion();
        Bootstrap.bootStrap();
        var root = new JsonObject();
        root.addProperty("version", SharedConstants.getCurrentVersion().id());
        try (var data = ReferenceWorldData.load()) {
            var level = ExportMobInitialization.createLevel(data.access());
            // The loot context the fuel providers read resolves them through the server's reloadable registries.
            var resources = Class.forName("net.minecraft.server.MinecraftServer$ReloadableResources").getDeclaredConstructors()[0];
            resources.setAccessible(true);
            ExportMobPhysics.field(level.getServer(), net.minecraft.server.MinecraftServer.class, "resources", resources.newInstance(data.resources(), data.reloadable()));
            Method burn = AbstractFurnaceBlockEntity.class.getDeclaredMethod("getBurnDuration", ServerLevel.class, ItemStack.class);
            Method speed = AbstractFurnaceBlockEntity.class.getDeclaredMethod("getSpeedMultiplier", ServerLevel.class, ItemStack.class);
            burn.setAccessible(true);
            speed.setAccessible(true);
            AbstractFurnaceBlockEntity[] furnaces = {
                new FurnaceBlockEntity(BlockPos.ZERO, Blocks.FURNACE.defaultBlockState()),
                new BlastFurnaceBlockEntity(BlockPos.ZERO, Blocks.BLAST_FURNACE.defaultBlockState()),
                new SmokerBlockEntity(BlockPos.ZERO, Blocks.SMOKER.defaultBlockState()),
            };
            var fuel = new JsonObject();
            for (var furnace : furnaces) {
                furnace.setLevel(level);
                var items = new JsonObject();
                for (var item : BuiltInRegistries.ITEM) {
                    if (item.components().get(DataComponents.COOKING_FUEL) == null) continue;
                    var stack = new ItemStack(item);
                    var entry = new JsonArray();
                    entry.add((int) burn.invoke(furnace, level, stack));
                    entry.add((float) speed.invoke(furnace, level, stack));
                    items.add(BuiltInRegistries.ITEM.getKey(item).getPath(), entry);
                }
                fuel.add(BuiltInRegistries.BLOCK_ENTITY_TYPE.getKey(furnace.getType()).getPath(), items);
            }
            root.add("fuel", fuel);
        }
        Files.writeString(Path.of(args[0]), new GsonBuilder().setPrettyPrinting().disableHtmlEscaping().create().toJson(root) + "\n");
    }
}
