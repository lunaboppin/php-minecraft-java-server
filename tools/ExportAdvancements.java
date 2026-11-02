import java.nio.file.*;
import java.util.HexFormat;
import com.google.gson.*;
import com.mojang.serialization.JsonOps;
import io.netty.buffer.Unpooled;
import net.minecraft.SharedConstants;
import net.minecraft.advancements.Advancement;
import net.minecraft.advancements.triggers.Criterion;
import net.minecraft.advancements.triggers.RecipeUnlockedTrigger;
import net.minecraft.network.RegistryFriendlyByteBuf;
import net.minecraft.network.chat.ComponentSerialization;
import net.minecraft.network.protocol.game.ClientboundUpdateAdvancementsPacket;
import net.minecraft.resources.RegistryOps;
import net.minecraft.server.Bootstrap;

/**
 * Every advancement as the official ServerAdvancementManager loads it, in its iteration order:
 * the tree (parent and children in order), criteria with their triggers and conditions, requirements, rewards,
 * the display flags the server acts on, the decorated name commands and announcements show, and
 * the PositionedAdvancement network entry, laid out by TreeNodePosition where there is a display.
 */
public class ExportAdvancements {
    public static void main(String[] args) throws Exception {
        if (args.length != 1) throw new IllegalArgumentException("Expected output path");
        SharedConstants.tryDetectVersion(); Bootstrap.bootStrap();
        var root = new JsonObject();
        root.addProperty("version", SharedConstants.getCurrentVersion().id());
        try (var data = ReferenceWorldData.load()) {
            var access = data.access();
            var ops = RegistryOps.create(JsonOps.INSTANCE, access);
            var manager = data.reloadable().getAdvancements();
            var tree = manager.tree();
            var hex = HexFormat.of();
            var advancements = new JsonArray();
            for (var holder : manager.getAllAdvancements()) {
                var advancement = holder.value();
                var node = tree.get(holder);
                var out = new JsonObject();
                out.addProperty("id", holder.id().toString());
                advancement.parent().ifPresent(parent -> out.addProperty("parent", parent.toString()));
                var children = new JsonArray();
                for (var child : node.children()) children.add(child.holder().id().toString());
                out.add("children", children);
                var criteria = new JsonObject();
                advancement.criteria().forEach((name, criterion) -> {
                    var encoded = Criterion.CODEC.encodeStart(ops, criterion).getOrThrow().getAsJsonObject();
                    // Recipes are not a registry here, so the codec would inline them: name them instead.
                    if (criterion.triggerInstance() instanceof RecipeUnlockedTrigger.TriggerInstance unlocked) {
                        var ids = new JsonArray();
                        unlocked.recipes().forEach(recipe -> ids.add(recipe.unwrapKey().orElseThrow().identifier().toString()));
                        encoded.getAsJsonObject("conditions").add("recipes", ids);
                    }
                    criteria.add(name, encoded);
                });
                out.add("criteria", criteria);
                var requirements = new JsonArray();
                for (var alternatives : advancement.requirements().requirements()) {
                    var any = new JsonArray();
                    alternatives.forEach(any::add);
                    requirements.add(any);
                }
                out.add("requirements", requirements);
                out.addProperty("experience", advancement.rewards().experience());
                var recipes = new JsonArray();
                advancement.rewards().recipes().forEach(recipe -> recipes.add(recipe.identifier().toString()));
                out.add("recipes", recipes);
                if (!(advancement.rewards().loot().size() == 0) || advancement.rewards().function().isPresent()) {
                    throw new IllegalStateException("Loot and function rewards are not exported: " + holder.id());
                }
                out.add("name", ComponentSerialization.CODEC.encodeStart(ops, Advancement.name(holder)).getOrThrow());
                advancement.display().ifPresent(display -> {
                    out.addProperty("frame", display.type().getSerializedName());
                    out.addProperty("announce", display.announceToChat());
                    out.addProperty("hidden", display.hidden());
                });
                var buffer = new RegistryFriendlyByteBuf(Unpooled.buffer(), access);
                ClientboundUpdateAdvancementsPacket.PositionedAdvancement.STREAM_CODEC.encode(buffer, ClientboundUpdateAdvancementsPacket.PositionedAdvancement.fromNode(node));
                var bytes = new byte[buffer.readableBytes()];
                buffer.readBytes(bytes);
                out.addProperty("entry", hex.formatHex(bytes));
                advancements.add(out);
            }
            root.add("advancements", advancements);
        }
        Files.writeString(Path.of(args[0]), new GsonBuilder().setPrettyPrinting().disableHtmlEscaping().create().toJson(root) + "\n");
    }
}
