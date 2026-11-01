import java.nio.file.*;
import java.util.HexFormat;
import com.google.gson.*;
import io.netty.buffer.Unpooled;
import net.minecraft.SharedConstants;
import net.minecraft.network.RegistryFriendlyByteBuf;
import net.minecraft.network.protocol.game.ClientboundUpdateRecipesPacket;
import net.minecraft.server.Bootstrap;
import net.minecraft.core.registries.BuiltInRegistries;
import net.minecraft.world.item.crafting.RecipeType;
import net.minecraft.world.item.crafting.ShapedRecipe;
import net.minecraft.world.item.crafting.display.RecipeDisplay;
import net.minecraft.world.item.crafting.display.RecipeDisplayEntry;
import net.minecraft.world.level.WorldDataConfiguration;

/**
 * The recipe book as the official server builds it: every recipe display entry in RecipeDisplayId
 * order, encoded for the network, with its recipe and whether unlocking it shows a toast; the
 * learnable recipes in RecipeManager order; the update_recipes payload sent on joining; and
 * how the recipe book places each crafting and furnace recipe.
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
                // The display alone, as place_ghost_recipe sends it.
                var ghost = new RegistryFriendlyByteBuf(Unpooled.buffer(), access);
                RecipeDisplay.STREAM_CODEC.encode(ghost, info.display().display());
                var ghostBytes = new byte[ghost.readableBytes()];
                ghost.readBytes(ghostBytes);
                display.addProperty("display", hex.formatHex(ghostBytes));
                displays.add(display);
            }
            root.add("displays", displays);
            var learnable = new JsonArray();
            for (var recipe : manager.getLearnableRecipes()) learnable.add(recipe.id().identifier().toString());
            root.add("learnable", learnable);
            var recipes = new JsonArray();
            // PlacementInfo for the crafting and furnace recipes the book can place: the recipe type,
            // each ingredient's items, the grid slots' ingredient indices, and a shaped recipe's size.
            var placements = new JsonObject();
            for (var recipe : manager.getRecipes()) {
                recipes.add(recipe.id().identifier().toString());
                var type = recipe.value().getType();
                if (type != RecipeType.CRAFTING && type != RecipeType.SMELTING && type != RecipeType.BLASTING && type != RecipeType.SMOKING || recipe.value().isSpecial()) continue;
                var info = recipe.value().placementInfo();
                var placement = new JsonObject();
                placement.addProperty("type", BuiltInRegistries.RECIPE_TYPE.getKey(type).getPath());
                placement.addProperty("impossible", info.isImpossibleToPlace());
                var ingredients = new JsonArray();
                for (var ingredient : info.ingredients()) {
                    var items = new JsonArray();
                    ingredient.items().forEach(item -> items.add(BuiltInRegistries.ITEM.getId(item.value())));
                    ingredients.add(items);
                }
                placement.add("ingredients", ingredients);
                var slots = new JsonArray();
                info.slotsToIngredientIndex().forEach(slots::add);
                placement.add("slots", slots);
                if (recipe.value() instanceof ShapedRecipe shaped) {
                    placement.addProperty("width", shaped.getWidth());
                    placement.addProperty("height", shaped.getHeight());
                }
                placements.add(recipe.id().identifier().toString(), placement);
            }
            root.add("recipes", recipes);
            root.add("placements", placements);
            var buffer = new RegistryFriendlyByteBuf(Unpooled.buffer(), access);
            ClientboundUpdateRecipesPacket.STREAM_CODEC.encode(buffer, new ClientboundUpdateRecipesPacket(manager.getSynchronizedItemProperties(), manager.getSynchronizedStonecutterRecipes()));
            var bytes = new byte[buffer.readableBytes()];
            buffer.readBytes(bytes);
            root.addProperty("update_recipes", hex.formatHex(bytes));
        }
        Files.writeString(Path.of(args[0]), new GsonBuilder().setPrettyPrinting().create().toJson(root) + "\n");
    }
}
