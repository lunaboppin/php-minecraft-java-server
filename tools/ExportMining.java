import java.nio.file.*;
import java.util.*;
import com.google.gson.*;
import net.minecraft.SharedConstants;
import net.minecraft.core.component.DataComponents;
import net.minecraft.core.registries.BuiltInRegistries;
import net.minecraft.core.registries.Registries;
import net.minecraft.server.Bootstrap;
import net.minecraft.world.entity.ai.attributes.Attributes;
import net.minecraft.world.item.component.Tool;
import net.minecraft.world.item.enchantment.EnchantmentEffectComponents;
import net.minecraft.world.item.enchantment.Enchantments;
import net.minecraft.world.level.block.Block;

/**
 * Player.getDestroySpeed's inputs: each item's default tool component (rules in order, with their
 * blocks), the efficiency enchantment's mining_efficiency amount by level, and each block's
 * destroy time and correct-tool requirement.
 */
public class ExportMining {
    public static void main(String[] args) throws Exception {
        if (args.length != 1) throw new IllegalArgumentException("Expected output path");
        SharedConstants.tryDetectVersion();
        Bootstrap.bootStrap();
        var root = new JsonObject();
        root.addProperty("version", SharedConstants.getCurrentVersion().id());
        // Loading the reference world data binds the items' default components.
        try (var data = ReferenceWorldData.load()) {
            var items = new JsonObject();
            for (var item : BuiltInRegistries.ITEM) {
                Tool tool = item.components().get(DataComponents.TOOL);
                if (tool == null) continue;
                var row = new JsonObject();
                row.addProperty("default_speed", tool.defaultMiningSpeed());
                row.addProperty("damage_per_block", tool.damagePerBlock());
                row.addProperty("can_destroy_blocks_in_creative", tool.canDestroyBlocksInCreative());
                var rules = new JsonArray();
                for (var rule : tool.rules()) {
                    var entry = new JsonObject();
                    var blocks = new JsonArray();
                    rule.blocks().stream().map(holder -> holder.unwrapKey().orElseThrow().identifier().getPath()).sorted().forEach(blocks::add);
                    entry.add("blocks", blocks);
                    rule.speed().ifPresent(speed -> entry.addProperty("speed", speed));
                    rule.correctForDrops().ifPresent(correct -> entry.addProperty("correct", correct));
                    rules.add(entry);
                }
                row.add("rules", rules);
                items.add(BuiltInRegistries.ITEM.getKey(item).getPath(), row);
            }
            root.add("items", items);
            var blocks = new JsonObject();
            for (Block block : BuiltInRegistries.BLOCK) {
                var state = block.defaultBlockState();
                var row = new JsonObject();
                row.addProperty("destroy_time", state.getDestroySpeed(null, null));
                row.addProperty("requires_correct_tool", state.requiresCorrectToolForDrops());
                blocks.add(BuiltInRegistries.BLOCK.getKey(block).getPath(), row);
            }
            root.add("blocks", blocks);
            var efficiency = data.access().lookupOrThrow(Registries.ENCHANTMENT).getOrThrow(Enchantments.EFFICIENCY).value();
            var amounts = new JsonObject();
            for (int level = 1; level <= 255; level++) {
                double total = 0;
                for (var effect : efficiency.getEffects(EnchantmentEffectComponents.ATTRIBUTES)) {
                    if (!effect.attribute().is(Attributes.MINING_EFFICIENCY)) throw new IllegalStateException("Unexpected efficiency attribute");
                    total += effect.amount().calculate(level);
                }
                amounts.addProperty(Integer.toString(level), total);
            }
            root.add("efficiency", amounts);
        }
        Files.writeString(Path.of(args[0]), new GsonBuilder().setPrettyPrinting().disableHtmlEscaping().create().toJson(root) + "\n");
    }
}
