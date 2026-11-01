import java.nio.file.*;
import java.util.HexFormat;
import com.google.gson.*;
import io.netty.buffer.Unpooled;
import net.minecraft.SharedConstants;
import net.minecraft.network.RegistryFriendlyByteBuf;
import net.minecraft.network.protocol.game.ClientboundUpdateRecipesPacket;
import net.minecraft.server.Bootstrap;
import net.minecraft.world.item.crafting.display.RecipeDisplayEntry;
import net.minecraft.world.level.WorldDataConfiguration;

/**
 * The recipe book as the official server builds it: every recipe display entry in RecipeDisplayId
 * order, encoded for the network, with its recipe and whether unlocking it shows a toast; the
 * learnable recipes in RecipeManager order; and the update_recipes payload sent on joining.
 */
public class ExportRecipeBook {
    public static void main(String[] args) throws Exception {
        if (args.length != 1) throw new IllegalArgumentException("Expected output path");
        SharedConstants.tryDetectVersion(); Bootstrap.bootStrap();
        var root = new JsonObject();
        root.addProperty("version", SharedConstants.getCurrentVersion().id());
        try (var data = ReferenceWorldData.load()) {
            var access = data.access();
            var manager = data.reloadable().getRecipeManager();
            manager.finalizeRecipeLoading(WorldDataConfiguration.DEFAULT.enabledFeatures());
            var hex = HexFormat.of();
            var displays = new JsonArray();
            for (int id = 0; ; id++) {
                var info = manager.getRecipeFromDisplay(new net.minecraft.world.item.crafting.display.RecipeDisplayId(id));
                if (info == null) break;
                var buffer = new RegistryFriendlyByteBuf(Unpooled.buffer(), access);
                RecipeDisplayEntry.STREAM_CODEC.encode(buffer, info.display());
                var bytes = new byte[buffer.readableBytes()];
                buffer.readBytes(bytes);
                var display = new JsonObject();
                display.addProperty("recipe", info.parent().id().identifier().toString());
                display.addProperty("entry", hex.formatHex(bytes));
                display.addProperty("notification", info.parent().value().showNotification());
                displays.add(display);
            }
            root.add("displays", displays);
            var learnable = new JsonArray();
            for (var recipe : manager.getLearnableRecipes()) learnable.add(recipe.id().identifier().toString());
            root.add("learnable", learnable);
            var recipes = new JsonArray();
            for (var recipe : manager.getRecipes()) recipes.add(recipe.id().identifier().toString());
            root.add("recipes", recipes);
            var buffer = new RegistryFriendlyByteBuf(Unpooled.buffer(), access);
            ClientboundUpdateRecipesPacket.STREAM_CODEC.encode(buffer, new ClientboundUpdateRecipesPacket(manager.getSynchronizedItemProperties(), manager.getSynchronizedStonecutterRecipes()));
            var bytes = new byte[buffer.readableBytes()];
            buffer.readBytes(bytes);
            root.addProperty("update_recipes", hex.formatHex(bytes));
        }
        Files.writeString(Path.of(args[0]), new GsonBuilder().setPrettyPrinting().create().toJson(root) + "\n");
    }
}
