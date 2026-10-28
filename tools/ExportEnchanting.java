import java.nio.file.*;
import java.lang.reflect.*;
import java.util.*;
import com.google.gson.*;
import net.minecraft.SharedConstants;
import net.minecraft.core.*;
import net.minecraft.core.component.DataComponents;
import net.minecraft.core.registries.*;
import net.minecraft.resources.Identifier;
import net.minecraft.server.Bootstrap;
import net.minecraft.util.RandomSource;
import net.minecraft.world.*;
import net.minecraft.world.entity.*;
import net.minecraft.world.item.*;
import net.minecraft.world.item.enchantment.*;
import net.minecraft.world.item.enchantment.providers.*;
import net.minecraft.world.level.Level;
import net.minecraft.world.level.ServerLevelAccessor;

/** Actual native candidate, weighted selection and difficulty-provider results. */
public class ExportEnchanting {
    record Variant(Item item, String mode, JsonObject metadata) {}
    static final JsonArray SELECTIONS = new JsonArray();
    static final Map<String, Integer> SELECTION_IDS = new LinkedHashMap<>();
    static final JsonArray STATES = new JsonArray();
    static final Map<String, Integer> STATE_IDS = new LinkedHashMap<>();
    static final JsonArray SLOT_METADATA = new JsonArray();
    static final Map<String, Integer> METADATA_IDS = new LinkedHashMap<>();

    static Method method(Class<?> clazz, String name, Class<?>... parameters) throws Exception {
        for (var owner = clazz; owner != null; owner = owner.getSuperclass()) {
            try {
                var result = owner.getDeclaredMethod(name, parameters);
                result.setAccessible(true);
                return result;
            } catch (NoSuchMethodException ignored) {}
        }
        throw new NoSuchMethodException(clazz.getName() + "." + name);
    }

    static int selection(List<EnchantmentInstance> values) {
        var list = new JsonArray();
        for (var value : values) {
            var row = new JsonObject();
            row.addProperty("name", value.enchantment().unwrapKey().orElseThrow().identifier().toString());
            row.addProperty("level", value.level());
            list.add(row);
        }
        String key = list.toString();
        if (!SELECTION_IDS.containsKey(key)) {
            SELECTION_IDS.put(key, SELECTIONS.size());
            SELECTIONS.add(list);
        }
        return SELECTION_IDS.get(key);
    }

    static ItemStack stack(Item item, String mode) {
        var stack = new ItemStack(item);
        mode(stack, mode);
        return stack;
    }

    static void mode(ItemStack stack, String mode) {
        switch (mode) {
            case "default" -> {}
            case "remove_enchantable" -> stack.remove(DataComponents.ENCHANTABLE);
            case "enchantable_one" -> stack.set(DataComponents.ENCHANTABLE, new Enchantable(1));
            case "enchantable_max" -> stack.set(DataComponents.ENCHANTABLE, new Enchantable(Integer.MAX_VALUE));
            case "remove_storage" -> stack.remove(stack.is(Items.ENCHANTED_BOOK) ? DataComponents.STORED_ENCHANTMENTS : DataComponents.ENCHANTMENTS);
            default -> throw new IllegalArgumentException(mode);
        }
    }

    static JsonObject metadata(ItemStack stack, List<Holder.Reference<Enchantment>> enchantments) {
        var row = new JsonObject();
        row.addProperty("id", BuiltInRegistries.ITEM.getId(stack.getItem()));
        var enchantable = stack.get(DataComponents.ENCHANTABLE);
        row.add("enchantability", enchantable == null ? JsonNull.INSTANCE : new JsonPrimitive(enchantable.value()));
        row.addProperty("book", stack.is(Items.BOOK));
        row.addProperty("stored_book", stack.is(Items.ENCHANTED_BOOK));
        row.addProperty("can_store", EnchantmentHelper.canStoreEnchantments(stack));
        var primary = new JsonArray();
        for (var enchantment : enchantments) {
            if (enchantment.value().isPrimaryItem(stack)) primary.add(enchantment.key().identifier().toString());
        }
        row.add("primary", primary);
        return row;
    }

    static int state(ItemStack stack) {
        var row = new JsonObject();
        row.addProperty("item", BuiltInRegistries.ITEM.getId(stack.getItem()));
        row.addProperty("count", stack.getCount());
        if (stack.getDamageValue() != 0) row.addProperty("damage", stack.getDamageValue());
        var values = EnchantmentHelper.getEnchantmentsForCrafting(stack);
        var sorted = new TreeMap<String, Integer>();
        for (var enchantment : values.keySet()) sorted.put(enchantment.unwrapKey().orElseThrow().identifier().toString(), values.getLevel(enchantment));
        if (!sorted.isEmpty()) {
            var map = new JsonObject();
            sorted.forEach(map::addProperty);
            row.add(stack.is(Items.ENCHANTED_BOOK) ? "storedEnchantments" : "enchantments", map);
        }
        String key = row.toString();
        if (!STATE_IDS.containsKey(key)) {
            STATE_IDS.put(key, STATES.size());
            STATES.add(row);
        }
        return STATE_IDS.get(key);
    }

    static JsonArray equipment(Mob actor) {
        var state = new JsonArray();
        for (var slot : EquipmentSlot.values()) state.add(state(actor.getItemBySlot(slot)));
        return state;
    }

    public static void main(String[] args) throws Exception {
        if (args.length != 1) throw new IllegalArgumentException("Expected output fixture path");
        SharedConstants.tryDetectVersion();
        Bootstrap.bootStrap();
        var root = new JsonObject();
        root.addProperty("version", SharedConstants.getCurrentVersion().id());
        root.addProperty("protocol", SharedConstants.getCurrentVersion().protocolVersion());
        try (var data = ReferenceWorldData.load()) {
            var access = data.access();
            var registry = access.lookupOrThrow(Registries.ENCHANTMENT);
            var enchantments = registry.listElements().toList();
            var definitions = new JsonObject();
            var compatibility = new JsonObject();
            for (var holder : enchantments) {
                var enchantment = holder.value();
                var row = new JsonObject();
                row.addProperty("id", registry.getId(enchantment));
                row.addProperty("weight", enchantment.getWeight());
                row.addProperty("min_level", enchantment.getMinLevel());
                row.addProperty("max_level", enchantment.getMaxLevel());
                var costs = new JsonObject();
                for (int level = enchantment.getMinLevel(); level <= enchantment.getMaxLevel(); level++) {
                    var range = new JsonArray(); range.add(enchantment.getMinCost(level)); range.add(enchantment.getMaxCost(level));
                    costs.add(Integer.toString(level), range);
                }
                row.add("costs", costs);
                String name = holder.key().identifier().toString();
                definitions.add(name, row);
                var compatible = new JsonObject();
                for (var other : enchantments) compatible.addProperty(other.key().identifier().toString(), Enchantment.areCompatible(holder, other));
                compatibility.add(name, compatible);
            }
            root.add("enchantments", definitions);
            root.add("compatibility", compatibility);

            var provider = access.lookupOrThrow(Registries.ENCHANTMENT_PROVIDER).getOrThrow(VanillaEnchantmentProviders.MOB_SPAWN_EQUIPMENT).value();
            if (!(provider instanceof EnchantmentsByCostWithDifficulty difficultyProvider)) throw new IllegalStateException("Unexpected native mob provider implementation");
            var all = new JsonArray(); enchantments.forEach(holder -> all.add(holder.key().identifier().toString()));
            var mob = new JsonArray(); difficultyProvider.enchantments().stream().forEach(holder -> mob.add(holder.unwrapKey().orElseThrow().identifier().toString()));
            var pools = new JsonObject(); pools.add("all", all); pools.add("mob", mob); pools.add("empty", new JsonArray());
            root.add("pools", pools);
            var providerData = new JsonObject();
            providerData.addProperty("min_cost", difficultyProvider.minCost());
            providerData.addProperty("max_cost_span", difficultyProvider.maxCostSpan());
            providerData.add("pool", mob);
            root.add("provider", providerData);
            root.addProperty("air_item", BuiltInRegistries.ITEM.getId(Items.AIR));
            var mobSlots = new JsonArray();
            for (var slot : EquipmentSlot.values()) {
                if (slot != EquipmentSlot.MAINHAND && slot.getType() != EquipmentSlot.Type.HUMANOID_ARMOR) continue;
                var entry = new JsonObject();
                entry.addProperty("slot", slot.ordinal());
                entry.addProperty("chance", slot == EquipmentSlot.MAINHAND ? (double) .25F : (double) .5F);
                mobSlots.add(entry);
            }
            root.add("mob_slots", mobSlots);

            var variants = new ArrayList<Variant>();
            var items = new JsonArray();
            for (var item : BuiltInRegistries.ITEM) {
                var base = stack(item, "default");
                boolean special = item == Items.BOOK || item == Items.ENCHANTED_BOOK || item == Items.STONE || item == Items.AIR;
                if (base.get(DataComponents.ENCHANTABLE) == null && !special) continue;
                for (String mode : special || item == Items.IRON_SWORD ? List.of("default", "remove_enchantable", "enchantable_one", "enchantable_max", "remove_storage") : List.of("default")) {
                    var entry = metadata(stack(item, mode), enchantments);
                    entry.addProperty("name", BuiltInRegistries.ITEM.getKey(item).toString());
                    entry.addProperty("mode", mode);
                    variants.add(new Variant(item, mode, entry));
                    items.add(entry);
                }
            }
            root.add("items", items);

            var seeds = new LinkedHashSet<Long>(List.of(0L, 1L, 42L, -1L, Long.MIN_VALUE, Long.MAX_VALUE));
            var seedRandom = RandomSource.create(42);
            while (seeds.size() < 12) seeds.add(seedRandom.nextLong());
            var availableCases = new JsonArray();
            var selectionCases = new JsonArray();
            var providerCases = new JsonArray();
            var difficulties = List.of(
                new DifficultyInstance(Difficulty.NORMAL, 18000, 0, 1),
                new DifficultyInstance(Difficulty.NORMAL, 1512000, 2400000, 0),
                new DifficultyInstance(Difficulty.HARD, 1512000, 3600000, 1),
                new DifficultyInstance(Difficulty.NORMAL, 18000, 0, Float.NaN)
            );
            for (int itemIndex = 0; itemIndex < variants.size(); itemIndex++) {
                var variant = variants.get(itemIndex);
                var boundaries = new TreeSet<Integer>(List.of(Integer.MIN_VALUE, -1, 0, 1, 30, Integer.MAX_VALUE));
                var example = stack(variant.item(), variant.mode());
                for (var enchantment : enchantments) if (variant.metadata().get("book").getAsBoolean() || enchantment.value().isPrimaryItem(example)) {
                    for (int level = enchantment.value().getMinLevel(); level <= enchantment.value().getMaxLevel(); level++) {
                        for (int boundary : new int[]{enchantment.value().getMinCost(level), enchantment.value().getMaxCost(level)}) {
                            boundaries.add(boundary - 1); boundaries.add(boundary); boundaries.add(boundary + 1);
                        }
                    }
                }
                for (String poolName : List.of("all", "mob", "empty")) {
                    var holders = new ArrayList<Holder<Enchantment>>();
                    for (var name : pools.getAsJsonArray(poolName)) holders.add(registry.getOrThrow(net.minecraft.resources.ResourceKey.create(Registries.ENCHANTMENT, Identifier.parse(name.getAsString()))));
                    for (int cost : boundaries) {
                        var row = new JsonObject();
                        row.addProperty("item", itemIndex); row.addProperty("pool", poolName); row.addProperty("cost", cost);
                        row.addProperty("selection", selection(EnchantmentHelper.getAvailableEnchantmentResults(cost, example, holders.stream())));
                        availableCases.add(row);
                    }
                    for (int cost : new int[]{Integer.MIN_VALUE, -1, 0, 1, 5, 15, 30, 100, Integer.MAX_VALUE}) for (long seed : seeds) {
                        var random = RandomSource.create(seed);
                        var selected = EnchantmentHelper.selectEnchantment(random, example, cost, holders.stream());
                        var row = new JsonObject();
                        row.addProperty("item", itemIndex); row.addProperty("pool", poolName); row.addProperty("cost", cost);
                        row.addProperty("seed", Long.toString(seed)); row.addProperty("selection", selection(selected));
                        row.addProperty("random_next", Long.toString(ReferenceWorldData.peekNextLong(random)));
                        selectionCases.add(row);
                    }
                }
                for (int difficultyIndex = 0; difficultyIndex < difficulties.size(); difficultyIndex++) for (long seed : seeds) for (boolean existing : new boolean[]{false, true}) {
                    var stack = stack(variant.item(), variant.mode());
                    if (stack.isDamageableItem()) stack.setDamageValue(7);
                    if (existing) {
                        var values = new ItemEnchantments.Mutable(ItemEnchantments.EMPTY);
                        values.set(registry.getOrThrow(net.minecraft.resources.ResourceKey.create(Registries.ENCHANTMENT, Identifier.withDefaultNamespace("sharpness"))), 3);
                        values.set(registry.getOrThrow(net.minecraft.resources.ResourceKey.create(Registries.ENCHANTMENT, Identifier.withDefaultNamespace("unbreaking"))), 255);
                        EnchantmentHelper.setEnchantments(stack, values.toImmutable());
                    }
                    var random = RandomSource.create(seed);
                    var difficulty = difficulties.get(difficultyIndex);
                    var row = new JsonObject();
                    row.addProperty("item", itemIndex); row.addProperty("seed", Long.toString(seed));
                    row.addProperty("special_bits", ExportMobInitialization.floatBits(difficulty.getSpecialMultiplier()));
                    row.addProperty("can_store", EnchantmentHelper.canStoreEnchantments(stack));
                    row.addProperty("initial", state(stack));
                    var frames = new JsonArray();
                    for (int repeat = 0; repeat < 2; repeat++) {
                        EnchantmentHelper.enchantItemFromProvider(stack, access, VanillaEnchantmentProviders.MOB_SPAWN_EQUIPMENT, difficulty, random);
                        var frame = new JsonObject(); frame.addProperty("state", state(stack));
                        frame.addProperty("random_next", Long.toString(ReferenceWorldData.peekNextLong(random))); frames.add(frame);
                    }
                    row.add("frames", frames); providerCases.add(row);
                }
            }
            root.add("available_cases", availableCases);
            root.add("selection_cases", selectionCases);
            root.add("provider_cases", providerCases);
            var mobOwners = new JsonObject();
            var mobCases = new JsonArray();
            var level = ExportMobInitialization.createLevel(access);
            var world = (ServerLevelAccessor) Proxy.newProxyInstance(ServerLevelAccessor.class.getClassLoader(), new Class[]{ServerLevelAccessor.class}, (proxy, method, values) -> {
                if (method.getName().equals("registryAccess")) return access;
                throw new UnsupportedOperationException("Unexpected mob enchanting world call: " + method.getName());
            });
            for (String kind : List.of("giant", "zombie", "husk", "drowned", "zombified_piglin", "zombie_villager")) {
                for (long seed : seeds) for (var difficulty : difficulties) for (int layout = 0; layout < 3; layout++) {
                    for (String mode : List.of("default", "existing", "remove_enchantable", "remove_storage")) {
                        ExportMobPhysics.field(level, Level.class, "random", RandomSource.create(seed));
                        var type = BuiltInRegistries.ENTITY_TYPE.getValue(Identifier.withDefaultNamespace(kind));
                        var actor = (Mob) type.create(level, new EntitySpawnRequest(EntitySpawnReason.SPAWN_ITEM_USE, true));
                        ExportMobEquipment.layout(actor, layout);
                        for (var slot : EquipmentSlot.values()) {
                            var stack = actor.getItemBySlot(slot);
                            if (stack.isEmpty()) continue;
                            if (mode.equals("existing")) {
                                var values = new ItemEnchantments.Mutable(ItemEnchantments.EMPTY);
                                values.set(registry.getOrThrow(net.minecraft.resources.ResourceKey.create(Registries.ENCHANTMENT, Identifier.withDefaultNamespace("sharpness"))), 3);
                                values.set(registry.getOrThrow(net.minecraft.resources.ResourceKey.create(Registries.ENCHANTMENT, Identifier.withDefaultNamespace("unbreaking"))), 255);
                                EnchantmentHelper.setEnchantments(stack, values.toImmutable());
                            } else mode(stack, mode);
                        }
                        var owner = new JsonObject();
                        var populate = method(actor.getClass(), "populateDefaultEquipmentEnchantments", ServerLevelAccessor.class, RandomSource.class, DifficultyInstance.class);
                        owner.addProperty("populate", populate.getDeclaringClass().getName());
                        owner.addProperty("weapon", method(actor.getClass(), "enchantSpawnedWeapon", ServerLevelAccessor.class, RandomSource.class, DifficultyInstance.class).getDeclaringClass().getName());
                        owner.addProperty("armor", method(actor.getClass(), "enchantSpawnedArmor", ServerLevelAccessor.class, RandomSource.class, EquipmentSlot.class, DifficultyInstance.class).getDeclaringClass().getName());
                        mobOwners.add(kind, owner);
                        var row = new JsonObject();
                        row.addProperty("kind", kind); row.addProperty("seed", Long.toString(seed));
                        row.addProperty("layout", layout); row.addProperty("mode", mode);
                        row.addProperty("special_bits", ExportMobInitialization.floatBits(difficulty.getSpecialMultiplier()));
                        row.add("initial", equipment(actor));
                        var metadata = new JsonArray();
                        for (var slot : EquipmentSlot.values()) {
                            var entry = metadata(actor.getItemBySlot(slot), enchantments);
                            String key = entry.toString();
                            if (!METADATA_IDS.containsKey(key)) { METADATA_IDS.put(key, SLOT_METADATA.size()); SLOT_METADATA.add(entry); }
                            metadata.add(METADATA_IDS.get(key));
                        }
                        row.add("metadata", metadata);
                        var random = RandomSource.create(seed);
                        var frames = new JsonArray();
                        for (int repeat = 0; repeat < 2; repeat++) {
                            populate.invoke(actor, world, random, difficulty);
                            var frame = new JsonObject(); frame.add("equipment", equipment(actor));
                            frame.addProperty("random_next", Long.toString(ReferenceWorldData.peekNextLong(random))); frames.add(frame);
                        }
                        row.add("frames", frames); mobCases.add(row);
                    }
                }
            }
            root.add("mob_owners", mobOwners);
            root.add("mob_cases", mobCases);
            root.add("slot_metadata", SLOT_METADATA);
            var rules = new JsonObject();
            for (String field : List.of("version", "protocol", "enchantments", "compatibility", "pools", "provider", "air_item", "mob_slots")) {
                rules.add(field, root.get(field));
            }
            var defaults = new JsonObject();
            for (var item : BuiltInRegistries.ITEM) {
                var entry = metadata(new ItemStack(item), enchantments);
                entry.addProperty("name", BuiltInRegistries.ITEM.getKey(item).toString());
                defaults.add(Integer.toString(BuiltInRegistries.ITEM.getId(item)), entry);
            }
            rules.add("default_items", defaults);
            var components = new JsonObject();
            components.addProperty("enchantments", BuiltInRegistries.DATA_COMPONENT_TYPE.getId(DataComponents.ENCHANTMENTS));
            components.addProperty("stored_enchantments", BuiltInRegistries.DATA_COMPONENT_TYPE.getId(DataComponents.STORED_ENCHANTMENTS));
            components.addProperty("enchantable", BuiltInRegistries.DATA_COMPONENT_TYPE.getId(DataComponents.ENCHANTABLE));
            rules.add("component_ids", components);
            Files.writeString(Path.of(args[0]).resolveSibling("enchanting-rules.json"), new GsonBuilder().serializeNulls().setPrettyPrinting().create().toJson(rules) + "\n");
            root.add("selections", SELECTIONS);
            root.add("states", STATES);
        }
        Files.writeString(Path.of(args[0]), new GsonBuilder().serializeNulls().setPrettyPrinting().create().toJson(root) + "\n");
        System.out.println("Exported actual native enchanting metadata, candidates, selections and provider states");
    }
}
