import java.nio.file.*;
import java.util.*;
import java.util.stream.*;
import com.google.gson.*;
import com.mojang.serialization.*;
import com.mojang.authlib.GameProfile;
import io.netty.buffer.*;
import net.minecraft.SharedConstants;
import net.minecraft.server.Bootstrap;
import net.minecraft.core.*;
import net.minecraft.core.registries.*;
import net.minecraft.data.registries.VanillaRegistries;
import net.minecraft.nbt.*;
import net.minecraft.resources.*;
import net.minecraft.network.*;
import net.minecraft.network.codec.*;
import net.minecraft.network.chat.Component;
import net.minecraft.network.protocol.configuration.*;
import net.minecraft.network.protocol.login.*;
import net.minecraft.network.protocol.game.*;
import net.minecraft.world.level.*;
import net.minecraft.world.level.block.*;
import net.minecraft.world.level.biome.*;
import net.minecraft.world.level.chunk.*;
import net.minecraft.world.level.storage.*;
import net.minecraft.world.entity.*;
import net.minecraft.world.phys.*;

/** Exports reference data and wire fixtures with the official 26.3 codecs. */
public class ExportReference {
    static Path out;
    static Gson gson = new GsonBuilder().setPrettyPrinting().create();
    static JsonObject fixtures = new JsonObject();
    static <B extends ByteBuf,T> void fixture(String name, StreamCodec<B,T> codec, B buf, T value) {
        codec.encode(buf, value);
        byte[] bytes = new byte[buf.readableBytes()]; buf.readBytes(bytes);
        fixtures.addProperty(name, HexFormat.of().formatHex(bytes)); buf.release();
    }
    @SuppressWarnings({"rawtypes", "unchecked"})
    public static void main(String[] args) throws Exception {
        out=Path.of(args[0]); Files.createDirectories(out.resolve("registries"));
        SharedConstants.tryDetectVersion(); Bootstrap.bootStrap();
        var provider = VanillaRegistries.createWorldLookup();
        BuiltInRegistries.DATA_COMPONENT_INITIALIZERS.build(provider).forEach(net.minecraft.core.component.DataComponentInitializers.PendingComponents::apply);
        var ops = provider.createSerializationContext(NbtOps.INSTANCE);
        var ids = new JsonObject();
        var mapped = new ArrayList<Registry<?>>();
        for (var definition : RegistryDataLoader.SYNCHRONIZED_REGISTRIES) {
            var key = definition.key();
            var entries = provider.lookupOrThrow(key).listElements().sorted(Comparator.comparing(h -> h.key().identifier().toString())).toList();
            var packed = new ArrayList<RegistrySynchronization.PackedRegistryEntry>();
            var names = new JsonArray();
            MappedRegistry registry = new MappedRegistry(key, Lifecycle.stable());
            for (var entry : entries) {
                names.add(entry.key().identifier().toString());
                Tag nbt = (Tag)((Codec)definition.elementCodec()).encodeStart(ops,entry.value()).getOrThrow();
                packed.add(new RegistrySynchronization.PackedRegistryEntry(entry.key().identifier(),Optional.of(nbt)));
                registry.register(entry.key(),entry.value(),RegistrationInfo.BUILT_IN);
            }
            registry.bindAllTagsToEmpty(); mapped.add(registry.freeze());
            ids.add(key.identifier().toString(),names);
            var buf = new FriendlyByteBuf(Unpooled.buffer());
            ClientboundRegistryDataPacket.STREAM_CODEC.encode(buf,new ClientboundRegistryDataPacket(key,packed));
            byte[] bytes=new byte[buf.readableBytes()];buf.readBytes(bytes);buf.release();
            Files.write(out.resolve("registries/"+key.identifier().getPath().replace('/','_')+".bin"),bytes);
        }
        Files.writeString(out.resolve("dynamic-registries.json"),gson.toJson(ids)+"\n");
        var staticRegs=RegistryAccess.fromRegistryOfRegistries(BuiltInRegistries.REGISTRY).registries().map(RegistryAccess.RegistryEntry::value).toList();
        mapped.addAll(staticRegs);
        var access = new RegistryAccess.ImmutableRegistryAccess(mapped).freeze();
        var gameplay=new JsonObject();var flags=new JsonArray();var blockInfo=new JsonObject();var items=new JsonObject();
        // The default suffocation predicate reads the causes_suffocation tag, unbound here: bit 512
        // marks blocks keeping it, and bit 256 is any other block's own predicate.
        var propertiesField=net.minecraft.world.level.block.state.BlockBehaviour.class.getDeclaredField("properties");propertiesField.setAccessible(true);
        var suffocationField=net.minecraft.world.level.block.state.BlockBehaviour.Properties.class.getDeclaredField("isSuffocating");suffocationField.setAccessible(true);
        var defaultSuffocation=suffocationField.get(net.minecraft.world.level.block.state.BlockBehaviour.Properties.of());
        for(var state:Block.BLOCK_STATE_REGISTRY) {
            var defaultPredicate=suffocationField.get(propertiesField.get(state.getBlock()))==defaultSuffocation;
            flags.add((state.isAir()?1:0) | (state.isSolid()?2:0) | (!state.getFluidState().isEmpty()?4:0) | (state.canOcclude()?8:0) | (state.getLightEmission()<<4)
                | (!defaultPredicate && state.isSuffocating(net.minecraft.world.level.EmptyBlockGetter.INSTANCE,net.minecraft.core.BlockPos.ZERO)?256:0)
                | (defaultPredicate?512:0));
        }
        for(var block:BuiltInRegistries.BLOCK) {
            var state=block.defaultBlockState();var info=new JsonObject();
            info.addProperty("hardness",state.getDestroySpeed(net.minecraft.world.level.EmptyBlockGetter.INSTANCE,BlockPos.ZERO));
            info.addProperty("full_cube",state.isCollisionShapeFullBlock(net.minecraft.world.level.EmptyBlockGetter.INSTANCE,BlockPos.ZERO));
            info.addProperty("requires_tool",state.requiresCorrectToolForDrops());
            info.addProperty("replaceable",state.canBeReplaced());
            if(block instanceof net.minecraft.world.level.block.EntityBlock entityBlock){
                var entity=entityBlock.newBlockEntity(BlockPos.ZERO,state);
                if(entity!=null){info.addProperty("block_entity_type",BuiltInRegistries.BLOCK_ENTITY_TYPE.getKey(entity.getType()).getPath());}
            }
            blockInfo.add(BuiltInRegistries.BLOCK.getKey(block).getPath(),info);
        }
        for(var item:BuiltInRegistries.ITEM) {
            var stack=new net.minecraft.world.item.ItemStack(item);var info=new JsonObject();
            info.addProperty("id",BuiltInRegistries.ITEM.getId(item));info.addProperty("max_stack",stack.getMaxStackSize());info.addProperty("durability",stack.getMaxDamage());
            var food=stack.get(net.minecraft.core.component.DataComponents.FOOD);
            if(food!=null) {info.addProperty("nutrition",food.nutrition());info.addProperty("saturation",food.saturation());}
            items.add(BuiltInRegistries.ITEM.getKey(item).getPath(),info);
        }
        var simulation=new JsonObject();var pickItems=new JsonObject();for(var block:BuiltInRegistries.BLOCK){pickItems.addProperty(BuiltInRegistries.BLOCK.getKey(block).getPath(),BuiltInRegistries.ITEM.getId(block.asItem()));}simulation.add("pick_items",pickItems);var instruments=new JsonObject();var blockInstruments=new JsonObject();var songs=new JsonObject();
        for(var instrument:net.minecraft.world.level.block.state.properties.NoteBlockInstrument.values()) {
            var info=new JsonObject();info.addProperty("sound",BuiltInRegistries.SOUND_EVENT.getId(instrument.getSoundEvent().value()));
            info.addProperty("tunable",instrument.isTunable());info.addProperty("above",instrument.worksAboveNoteBlock());info.addProperty("custom",instrument.hasCustomSound());
            instruments.add(instrument.getSerializedName(),info);
        }
        for(var block:BuiltInRegistries.BLOCK){var instrument=block.defaultBlockState().instrument();if(instrument!=net.minecraft.world.level.block.state.properties.NoteBlockInstrument.HARP)blockInstruments.addProperty(BuiltInRegistries.BLOCK.getKey(block).getPath(),instrument.getSerializedName());}
        for(var item:BuiltInRegistries.ITEM){var playable=new net.minecraft.world.item.ItemStack(item).get(net.minecraft.core.component.DataComponents.JUKEBOX_PLAYABLE);
            if(playable!=null){var info=new JsonObject();info.addProperty("song",playable.song().unwrapKey().orElseThrow().identifier().getPath());info.addProperty("ticks",playable.song().value().lengthInTicks()+20);info.addProperty("comparator",playable.song().value().comparatorOutput());songs.add(BuiltInRegistries.ITEM.getKey(item).getPath(),info);}}
        simulation.add("instruments",instruments);simulation.add("block_instruments",blockInstruments);simulation.add("songs",songs);simulation.addProperty("note_block_id",BuiltInRegistries.BLOCK.getId(Blocks.NOTE_BLOCK));
        Files.writeString(out.resolve("simulation.json"),gson.toJson(simulation)+"\n");
        var tracking=new JsonObject();var ranges=new JsonObject();var poses=new JsonObject();
        for(var type:BuiltInRegistries.ENTITY_TYPE)ranges.addProperty(BuiltInRegistries.ENTITY_TYPE.getKey(type).getPath(),type.clientTrackingRange()*16);
        for(var pose:net.minecraft.world.entity.Pose.values())poses.addProperty(pose.name().toLowerCase(Locale.ROOT),pose.ordinal());
        var poseField=net.minecraft.world.entity.Entity.class.getDeclaredField("DATA_POSE");poseField.setAccessible(true);
        tracking.addProperty("pose_index",((net.minecraft.network.syncher.EntityDataAccessor<?>)poseField.get(null)).id());
        tracking.addProperty("pose_serializer",net.minecraft.network.syncher.EntityDataSerializers.getSerializedId(net.minecraft.network.syncher.EntityDataSerializers.POSE));
        tracking.add("ranges",ranges);tracking.add("poses",poses);
        Files.writeString(out.resolve("tracking.json"),gson.toJson(tracking)+"\n");
        var mobs=new JsonObject();var mobTypes=new JsonObject();var eggs=new JsonObject();var attributeIds=new JsonObject();var syncAttributes=new JsonArray();
        for(var attribute:BuiltInRegistries.ATTRIBUTE){var name=BuiltInRegistries.ATTRIBUTE.getKey(attribute).getPath();attributeIds.addProperty(name,BuiltInRegistries.ATTRIBUTE.getId(attribute));if(attribute.isClientSyncable())syncAttributes.add(name);}
        for(var type:BuiltInRegistries.ENTITY_TYPE){
            var info=new JsonObject();info.addProperty("id",BuiltInRegistries.ENTITY_TYPE.getId(type));
            // Entity dimensions are floats widened to doubles during collision.
            // Retain those exact values rather than shortening them to decimals.
            info.addProperty("width",(double)type.getWidth());info.addProperty("height",(double)type.getHeight());info.addProperty("eye_height",(double)type.getDimensions().eyeHeight());
            info.addProperty("category",type.getCategory().getName());info.addProperty("fire_immune",type.fireImmune());info.addProperty("summonable",type.canSummon());
            info.addProperty("peaceful",type.isAllowedInPeaceful());info.addProperty("enabled",type.isEnabled(net.minecraft.world.flag.FeatureFlags.DEFAULT_FLAGS));
            var stats=new JsonObject();
            if(net.minecraft.world.entity.ai.attributes.DefaultAttributes.hasSupplier(type)){
                var attributes=net.minecraft.world.entity.ai.attributes.DefaultAttributes.getSupplier((EntityType<? extends LivingEntity>)type);
                for(var attribute:BuiltInRegistries.ATTRIBUTE.listElements().toList())if(attributes.hasAttribute(attribute))stats.addProperty(BuiltInRegistries.ATTRIBUTE.getKey(attribute.value()).getPath(),attributes.getBaseValue(attribute));
            }
            info.add("attributes",stats);mobTypes.add(BuiltInRegistries.ENTITY_TYPE.getKey(type).getPath(),info);
        }
        for(var item:BuiltInRegistries.ITEM)if(item instanceof net.minecraft.world.item.SpawnEggItem)eggs.addProperty(BuiltInRegistries.ITEM.getKey(item).getPath(),BuiltInRegistries.ENTITY_TYPE.getKey(net.minecraft.world.item.SpawnEggItem.getType(new net.minecraft.world.item.ItemStack(item))).getPath());
        var healthField=LivingEntity.class.getDeclaredField("DATA_HEALTH_ID");healthField.setAccessible(true);
        mobs.addProperty("health_index",((net.minecraft.network.syncher.EntityDataAccessor<?>)healthField.get(null)).id());
        mobs.addProperty("float_serializer",net.minecraft.network.syncher.EntityDataSerializers.getSerializedId(net.minecraft.network.syncher.EntityDataSerializers.FLOAT));
        mobs.add("entities",mobTypes);mobs.add("spawn_eggs",eggs);mobs.add("attribute_ids",attributeIds);mobs.add("sync_attributes",syncAttributes);
        Files.writeString(out.resolve("mobs.json"),gson.toJson(mobs)+"\n");
        var collision=new JsonObject();var shapes=new JsonArray();var shapeStates=new JsonArray();var outlineStates=new JsonArray();var shapeIds=new LinkedHashMap<String,Integer>();
        for(var state:net.minecraft.world.level.block.Block.BLOCK_STATE_REGISTRY){
          for(int mode=0;mode<2;++mode){
            var boxes=new JsonArray();
            var voxel=mode==0?state.getCollisionShape(net.minecraft.world.level.EmptyBlockGetter.INSTANCE,BlockPos.ZERO):state.getShape(net.minecraft.world.level.EmptyBlockGetter.INSTANCE,BlockPos.ZERO);
            for(var box:voxel.toAabbs()){
                var bounds=new JsonArray();for(double value:new double[]{box.minX,box.minY,box.minZ,box.maxX,box.maxY,box.maxZ})bounds.add(value);boxes.add(bounds);
            }
            var key=boxes.toString();var shape=shapeIds.get(key);if(shape==null){shape=shapes.size();shapeIds.put(key,shape);shapes.add(boxes);}(mode==0?shapeStates:outlineStates).add(shape);
          }
        }
        collision.add("shapes",shapes);collision.add("states",shapeStates);collision.add("outline_states",outlineStates);
        Files.writeString(out.resolve("collision.json"),gson.toJson(collision)+"\n");
        var itemField=net.minecraft.world.entity.item.ItemEntity.class.getDeclaredField("DATA_ITEM");itemField.setAccessible(true);
        var itemAccessor=(net.minecraft.network.syncher.EntityDataAccessor<?>)itemField.get(null);
        gameplay.addProperty("item_metadata_index",itemAccessor.id());
        gameplay.addProperty("item_metadata_serializer",net.minecraft.network.syncher.EntityDataSerializers.getSerializedId(net.minecraft.network.syncher.EntityDataSerializers.ITEM_STACK));
        var airField=net.minecraft.world.entity.Entity.class.getDeclaredField("DATA_AIR_SUPPLY_ID");airField.setAccessible(true);
        var airAccessor=(net.minecraft.network.syncher.EntityDataAccessor<?>)airField.get(null);
        var flagsField=net.minecraft.world.entity.Entity.class.getDeclaredField("DATA_SHARED_FLAGS_ID");flagsField.setAccessible(true);
        var flagsAccessor=(net.minecraft.network.syncher.EntityDataAccessor<?>)flagsField.get(null);
        var entityMetadata=new JsonObject();entityMetadata.addProperty("air_index",airAccessor.id());entityMetadata.addProperty("flags_index",flagsAccessor.id());
        entityMetadata.addProperty("int_serializer",net.minecraft.network.syncher.EntityDataSerializers.getSerializedId(net.minecraft.network.syncher.EntityDataSerializers.INT));
        entityMetadata.addProperty("byte_serializer",net.minecraft.network.syncher.EntityDataSerializers.getSerializedId(net.minecraft.network.syncher.EntityDataSerializers.BYTE));gameplay.add("entity_metadata",entityMetadata);
        var skinField=net.minecraft.world.entity.Avatar.class.getDeclaredField("DATA_PLAYER_MODE_CUSTOMISATION");skinField.setAccessible(true);
        var skinAccessor=(net.minecraft.network.syncher.EntityDataAccessor<Byte>)skinField.get(null);
        var armField=net.minecraft.world.entity.Avatar.class.getDeclaredField("DATA_PLAYER_MAIN_HAND");armField.setAccessible(true);
        var armAccessor=(net.minecraft.network.syncher.EntityDataAccessor<net.minecraft.world.entity.HumanoidArm>)armField.get(null);
        entityMetadata.addProperty("skin_index",skinAccessor.id());entityMetadata.addProperty("main_hand_index",armAccessor.id());
        entityMetadata.addProperty("main_hand_serializer",net.minecraft.network.syncher.EntityDataSerializers.getSerializedId(net.minecraft.network.syncher.EntityDataSerializers.HUMANOID_ARM));
        gameplay.add("flags",flags);gameplay.add("blocks",blockInfo);gameplay.add("items",items);
        Files.writeString(out.resolve("gameplay.json"),gson.toJson(gameplay)+"\n");
        var uuid=UUID.fromString("5627dd98-e6be-3c21-b8a8-e92344183641");
        var mobAttribute=new net.minecraft.world.entity.ai.attributes.AttributeInstance(net.minecraft.world.entity.ai.attributes.Attributes.MAX_HEALTH,a->{});mobAttribute.setBaseValue(10);
        fixture("mob_attributes",ClientboundUpdateAttributesPacket.STREAM_CODEC,new RegistryFriendlyByteBuf(Unpooled.buffer(),access),new ClientboundUpdateAttributesPacket(1,List.of(mobAttribute)));
        fixture("login_finished",ClientboundLoginFinishedPacket.STREAM_CODEC,Unpooled.buffer(),new ClientboundLoginFinishedPacket(new GameProfile(uuid,"Steve"),new UUID(0,1)));
        var spawn = new CommonPlayerSpawnInfo(access.lookupOrThrow(Registries.DIMENSION_TYPE).get(Identifier.withDefaultNamespace("overworld")).orElseThrow(),Level.OVERWORLD,0L,GameType.CREATIVE,Optional.empty(),false,true,Optional.empty(),0,63);
        fixture("play_login",ClientboundLoginPacket.STREAM_CODEC,new RegistryFriendlyByteBuf(Unpooled.buffer(),access),new ClientboundLoginPacket(1,false,Set.of(Level.OVERWORLD),20,2,2,false,true,false,spawn,false,false));
        fixture("client_teleport_confirmation",ServerboundAcceptTeleportationPacket.STREAM_CODEC,new FriendlyByteBuf(Unpooled.buffer()),new ServerboundAcceptTeleportationPacket(1,0.5,64,0.5,0,0));
        fixture("position",ClientboundPlayerPositionPacket.STREAM_CODEC,new FriendlyByteBuf(Unpooled.buffer()),new ClientboundPlayerPositionPacket(1,new PositionMoveRotation(new Vec3(0.5,64,0.5),Vec3.ZERO,0,0),Set.of()));
        fixture("spawn",ClientboundSetDefaultSpawnPositionPacket.STREAM_CODEC,new FriendlyByteBuf(Unpooled.buffer()),new ClientboundSetDefaultSpawnPositionPacket(LevelData.RespawnData.of(Level.OVERWORLD,new BlockPos(0,64,0),0,0)));
        fixture("chat",ClientboundSystemChatPacket.STREAM_CODEC,new RegistryFriendlyByteBuf(Unpooled.buffer(),access),new ClientboundSystemChatPacket(Component.literal("Hello"),false));
        fixture("time_overworld",ClientboundSetTimePacket.STREAM_CODEC,new RegistryFriendlyByteBuf(Unpooled.buffer(),access),new ClientboundSetTimePacket(100,Map.of(access.lookupOrThrow(Registries.WORLD_CLOCK).get(Identifier.withDefaultNamespace("overworld")).orElseThrow(),new net.minecraft.world.clock.ClockNetworkState(6100,0,1))));
        fixture("time",ClientboundSetTimePacket.STREAM_CODEC,new RegistryFriendlyByteBuf(Unpooled.buffer(),access),new ClientboundSetTimePacket(100,Map.of()));
        var biome=access.lookupOrThrow(Registries.BIOME).get(Biomes.PLAINS).orElseThrow();
        var biomeMap=access.lookupOrThrow(Registries.BIOME).asHolderIdMap();
        var blocks=new PalettedContainer<>(Blocks.AIR.defaultBlockState(),Strategy.createForBlockStates(Block.BLOCK_STATE_REGISTRY));
        var biomes=new PalettedContainer<Holder<Biome>>(biome,Strategy.createForBiomes(biomeMap));
        var section=new LevelChunkSection(blocks,biomes);
        var buf=new FriendlyByteBuf(Unpooled.buffer());section.write(buf);byte[] bytes=new byte[buf.readableBytes()];buf.readBytes(bytes);buf.release();fixtures.addProperty("empty_section",HexFormat.of().formatHex(bytes));
        for(int y=0;y<4;y++)for(int z=0;z<16;z++)for(int x=0;x<16;x++)section.setBlockState(x,y,z,(y==0?Blocks.BEDROCK:y==3?Blocks.GRASS_BLOCK:Blocks.DIRT).defaultBlockState());
        buf=new FriendlyByteBuf(Unpooled.buffer());section.write(buf);bytes=new byte[buf.readableBytes()];buf.readBytes(bytes);buf.release();fixtures.addProperty("flat_section",HexFormat.of().formatHex(bytes));
        fixture("entity_teleport",ClientboundTeleportEntityPacket.STREAM_CODEC,new FriendlyByteBuf(Unpooled.buffer()),new ClientboundTeleportEntityPacket(1,new PositionMoveRotation(new Vec3(0.5,64,0.5),Vec3.ZERO,0,0),Set.of(),true));
        fixture("entity_spawn",ClientboundAddEntityPacket.STREAM_CODEC,new RegistryFriendlyByteBuf(Unpooled.buffer(),access),new ClientboundAddEntityPacket(1,uuid,0.5,64,0.5,0,0,BuiltInRegistries.ENTITY_TYPE.getValue(Identifier.withDefaultNamespace("player")),0,Vec3.ZERO,0));
        BitSet mask=new BitSet();mask.set(0,26);byte[] sky=new byte[2048];Arrays.fill(sky,(byte)255);
        fixture("light",ClientboundLightUpdatePacketData.STREAM_CODEC,Unpooled.buffer(),new ClientboundLightUpdatePacketData(mask,new BitSet(),new BitSet(),mask,Collections.nCopies(26,sky),List.of()));
        fixture("creative_stone",ServerboundSetCreativeModeSlotPacket.STREAM_CODEC,new RegistryFriendlyByteBuf(Unpooled.buffer(),access),new ServerboundSetCreativeModeSlotPacket(36,new net.minecraft.world.item.ItemStack(net.minecraft.world.item.Items.STONE,64)));
        fixture("place_stone",ServerboundUseItemOnPacket.STREAM_CODEC,new FriendlyByteBuf(Unpooled.buffer()),new ServerboundUseItemOnPacket(net.minecraft.world.InteractionHand.MAIN_HAND,new BlockHitResult(new Vec3(0.5,64,1.5),Direction.UP,new BlockPos(0,63,1),false,false),1));
        fixture("dig_stone",ServerboundPlayerActionPacket.STREAM_CODEC,new FriendlyByteBuf(Unpooled.buffer()),new ServerboundPlayerActionPacket(ServerboundPlayerActionPacket.Action.START_DESTROY_BLOCK,new BlockPos(0,64,1),Direction.UP,2));
        fixture("client_chat",ServerboundChatPacket.STREAM_CODEC,new FriendlyByteBuf(Unpooled.buffer()),new ServerboundChatPacket("Hello",java.time.Instant.ofEpochMilli(1000),0,Optional.empty(),new net.minecraft.network.chat.LastSeenMessages.Update(0,new BitSet(),(byte)0)));
        fixture("respawn_survival",ClientboundRespawnPacket.STREAM_CODEC,new RegistryFriendlyByteBuf(Unpooled.buffer(),access),new ClientboundRespawnPacket(new CommonPlayerSpawnInfo(spawn.dimensionType(),spawn.dimension(),0L,GameType.SURVIVAL,Optional.empty(),false,true,Optional.empty(),0,63),(byte)0));
        fixture("death",ClientboundPlayerCombatKillPacket.STREAM_CODEC,new RegistryFriendlyByteBuf(Unpooled.buffer(),access),new ClientboundPlayerCombatKillPacket(1,Component.literal("Steve died")));
        fixture("inventory_empty",ClientboundContainerSetContentPacket.STREAM_CODEC,new RegistryFriendlyByteBuf(Unpooled.buffer(),access),new ClientboundContainerSetContentPacket(0,1,Collections.nCopies(46,net.minecraft.world.item.ItemStack.EMPTY),net.minecraft.world.item.ItemStack.EMPTY));
        fixture("open_chest",ClientboundOpenScreenPacket.STREAM_CODEC,new RegistryFriendlyByteBuf(Unpooled.buffer(),access),new ClientboundOpenScreenPacket(1,net.minecraft.world.inventory.MenuType.GENERIC_9x3,Component.literal("Chest")));
        fixture("item_metadata",ClientboundSetEntityDataPacket.STREAM_CODEC,new RegistryFriendlyByteBuf(Unpooled.buffer(),access),new ClientboundSetEntityDataPacket(2,List.of(new net.minecraft.network.syncher.SynchedEntityData.DataValue<>(itemAccessor.id(),net.minecraft.network.syncher.EntityDataSerializers.ITEM_STACK,new net.minecraft.world.item.ItemStack(net.minecraft.world.item.Items.STONE,1)))));
        var worn=new net.minecraft.world.item.ItemStack(net.minecraft.world.item.Items.IRON_PICKAXE);worn.setDamageValue(7);
        fixture("damaged_pickaxe",net.minecraft.world.item.ItemStack.OPTIONAL_STREAM_CODEC,new RegistryFriendlyByteBuf(Unpooled.buffer(),access),worn);
        fixture("click_empty",ServerboundContainerClickPacket.STREAM_CODEC,new RegistryFriendlyByteBuf(Unpooled.buffer(),access),new ServerboundContainerClickPacket(0,1,(short)36,(byte)0,net.minecraft.world.inventory.ContainerInput.PICKUP,new it.unimi.dsi.fastutil.ints.Int2ObjectOpenHashMap<>(),net.minecraft.network.HashedStack.EMPTY));
        fixture("vitals",ClientboundSetEntityDataPacket.STREAM_CODEC,new RegistryFriendlyByteBuf(Unpooled.buffer(),access),new ClientboundSetEntityDataPacket(1,List.of(new net.minecraft.network.syncher.SynchedEntityData.DataValue<>(airAccessor.id(),net.minecraft.network.syncher.EntityDataSerializers.INT,150),new net.minecraft.network.syncher.SynchedEntityData.DataValue<>(flagsAccessor.id(),net.minecraft.network.syncher.EntityDataSerializers.BYTE,(byte)1))));
        fixture("hashed_seed",net.minecraft.network.codec.ByteBufCodecs.LONG,Unpooled.buffer(),net.minecraft.world.level.biome.BiomeManager.obfuscateSeed(12345L));
        var settings=new net.minecraft.server.level.ClientInformation("en_gb",4,net.minecraft.world.entity.player.ChatVisiblity.FULL,true,127,net.minecraft.world.entity.HumanoidArm.LEFT,false,true,net.minecraft.server.level.ParticleStatus.ALL);
        fixture("client_information",net.minecraft.network.protocol.common.ServerboundClientInformationPacket.STREAM_CODEC,new FriendlyByteBuf(Unpooled.buffer()),new net.minecraft.network.protocol.common.ServerboundClientInformationPacket(settings));
        fixture("player_appearance",ClientboundSetEntityDataPacket.STREAM_CODEC,new RegistryFriendlyByteBuf(Unpooled.buffer(),access),new ClientboundSetEntityDataPacket(1,List.of(
            new net.minecraft.network.syncher.SynchedEntityData.DataValue<>(skinAccessor.id(),net.minecraft.network.syncher.EntityDataSerializers.BYTE,(byte)127),
            new net.minecraft.network.syncher.SynchedEntityData.DataValue<>(armAccessor.id(),net.minecraft.network.syncher.EntityDataSerializers.HUMANOID_ARM,net.minecraft.world.entity.HumanoidArm.LEFT))));
        fixture("head_rotation",ClientboundRotateHeadPacket.STREAM_CODEC,new FriendlyByteBuf(Unpooled.buffer()),ClientboundRotateHeadPacket.STREAM_CODEC.decode(new FriendlyByteBuf(Unpooled.wrappedBuffer(new byte[]{1,64}))));
        fixture("note_event",ClientboundBlockEventPacket.STREAM_CODEC,new RegistryFriendlyByteBuf(Unpooled.buffer(),access),new ClientboundBlockEventPacket(new BlockPos(1,64,1),Blocks.NOTE_BLOCK,0,0));
        fixture("record_start",ClientboundLevelEventPacket.STREAM_CODEC,new FriendlyByteBuf(Unpooled.buffer()),new ClientboundLevelEventPacket(1010,new BlockPos(1,64,1),0,false));
        fixture("sign_edit",ClientboundOpenSignEditorPacket.STREAM_CODEC,Unpooled.buffer(),new ClientboundOpenSignEditorPacket(new BlockPos(1,64,1),net.minecraft.world.level.block.entity.SignTextSlot.FRONT));
        fixture("sign_update",ServerboundSignUpdatePacket.STREAM_CODEC,Unpooled.buffer(),new ServerboundSignUpdatePacket(new BlockPos(1,64,1),List.of("Hello","world","",""),net.minecraft.world.level.block.entity.SignTextSlot.FRONT));
        if(args.length>1) verify(Path.of(args[1]),access);
        Files.writeString(out.resolve("fixtures.json"),gson.toJson(fixtures)+"\n");
        System.out.println("Exported "+ids.size()+" registries and "+fixtures.size()+" wire fixtures");
    }
    @SuppressWarnings({"rawtypes", "unchecked"})
    static void verify(Path capture, RegistryAccess access) throws Exception {
        JsonArray frames=JsonParser.parseString(Files.readString(capture)).getAsJsonArray();
        var play=GameProtocols.CLIENTBOUND_TEMPLATE.bind(RegistryFriendlyByteBuf.decorator(access));
        var serverbound=GameProtocols.SERVERBOUND_TEMPLATE.bind(RegistryFriendlyByteBuf.decorator(access), new GameProtocols.Context() {
            public boolean hasInfiniteMaterials() { return true; }
            public boolean canUseCommandBlocks() { return true; }
        });
        int chunks=0;int loadedRegistries=0;
        Map<ResourceKey<? extends Registry<?>>,RegistryDataLoader.NetworkedRegistryData> networked=new HashMap<>();
        List<HolderLookup.RegistryLookup<?>> context=RegistryAccess.fromRegistryOfRegistries(BuiltInRegistries.REGISTRY).listRegistries().toList();
        for(var item:frames) {
            var obj=item.getAsJsonObject();String state=obj.get("state").getAsString();
            byte[] bytes=HexFormat.of().parseHex(obj.get("hex").getAsString());
            var buf=Unpooled.wrappedBuffer(bytes);
            try {
                boolean inbound=obj.has("direction") && obj.get("direction").getAsString().equals("serverbound");
                var codec=inbound ? switch(state) {
                    case "handshake" -> net.minecraft.network.protocol.handshake.HandshakeProtocols.SERVERBOUND.codec();
                    case "play" -> serverbound.codec();
                    case "configuration" -> ConfigurationProtocols.SERVERBOUND.codec();
                    case "login" -> LoginProtocols.SERVERBOUND.codec();
                    case "status" -> net.minecraft.network.protocol.status.StatusProtocols.SERVERBOUND.codec();
                    default -> throw new IllegalArgumentException(state);
                } : switch(state) {
                    case "play" -> play.codec();
                    case "configuration" -> ConfigurationProtocols.CLIENTBOUND.codec();
                    case "login" -> LoginProtocols.CLIENTBOUND.codec();
                    case "status" -> net.minecraft.network.protocol.status.StatusProtocols.CLIENTBOUND.codec();
                    default -> throw new IllegalArgumentException(state);
                };
                var packet=codec.decode(buf);
                if(buf.isReadable())throw new IllegalStateException("Unread bytes: "+buf.readableBytes());
                if(packet instanceof ClientboundBlockEntityDataPacket entity && BuiltInRegistries.BLOCK_ENTITY_TYPE.getKey(entity.getType()).getPath().endsWith("sign")) {
                    var tag=entity.getTag();
                    if(tag.contains("front_text")){net.minecraft.world.level.block.entity.SignText.CODEC.parse(NbtOps.INSTANCE,tag.getCompoundOrEmpty("front_text")).getOrThrow();net.minecraft.world.level.block.entity.SignText.CODEC.parse(NbtOps.INSTANCE,tag.getCompoundOrEmpty("back_text")).getOrThrow();}
                }
                if(packet instanceof ClientboundRegistryDataPacket registry) {
                    networked.put(registry.registry(),new RegistryDataLoader.NetworkedRegistryData(registry.entries(),net.minecraft.tags.TagNetworkSerialization.NetworkPayload.EMPTY));
                }
                if(packet instanceof net.minecraft.network.protocol.common.ClientboundUpdateTagsPacket tags) {
                    for(var entry:tags.tags().entrySet()) {
                        Registry registry=RegistryAccess.fromRegistryOfRegistries(BuiltInRegistries.REGISTRY).lookup(entry.getKey()).orElse(null);
                        if(registry!=null)registry.prepareTagReload(entry.getValue().resolve(registry)).apply();
                        var previous=networked.get(entry.getKey());
                        if(previous!=null)networked.put(entry.getKey(),new RegistryDataLoader.NetworkedRegistryData(previous.elements(),entry.getValue()));
                    }
                }
                if(packet instanceof ClientboundFinishConfigurationPacket) {
                    RegistryDataLoader.load(networked,net.minecraft.server.packs.resources.ResourceProvider.EMPTY,context,RegistryDataLoader.SYNCHRONIZED_REGISTRIES,Runnable::run).join();
                    loadedRegistries+=networked.size();networked.clear();
                }
                if(packet instanceof ClientboundLevelChunkWithLightPacket chunk) {
                    var sections=chunk.chunkData().getReadBuffer();
                    int sectionCount=obj.has("dimension") && !obj.get("dimension").getAsString().equals("overworld")?16:24;
                    for(int i=0;i<sectionCount;i++) {
                        var section=new LevelChunkSection(PalettedContainerFactory.create(access));section.read(sections);
                        if(section.getNoiseBiome(0,0,0)==null || section.getNoiseBiome(0,0,0).value()==null)throw new IllegalStateException("Missing biome");
                        if(!obj.has("terrain") && i==7 && section.getBlockState(15,15,15).getBlock()!=Blocks.GRASS_BLOCK)throw new IllegalStateException("Wrong terrain");
                    }
                    if(sections.isReadable())throw new IllegalStateException("Unread chunk section bytes");
                    if(chunk.lightData().skyYMask().length()>sectionCount+2 || chunk.lightData().skyUpdates().size()!=chunk.lightData().skyYMask().cardinality() || chunk.lightData().skyYMask().intersects(chunk.lightData().emptySkyYMask()))throw new IllegalStateException("Wrong sky light");
                    if(chunk.lightData().blockYMask().length()>sectionCount+2 || chunk.lightData().blockUpdates().size()!=chunk.lightData().blockYMask().cardinality() || chunk.lightData().blockYMask().intersects(chunk.lightData().emptyBlockYMask()))throw new IllegalStateException("Wrong block light");
                    if(!chunk.chunkData().getHeightmaps().containsKey(net.minecraft.world.level.levelgen.Heightmap.Types.MOTION_BLOCKING))throw new IllegalStateException("Missing heightmaps");
                    chunks++;
                }
            } catch(Throwable e) {
                throw new IllegalStateException("Invalid "+(obj.has("direction")?obj.get("direction").getAsString():"clientbound")+" "+state+" packet ID "+(bytes[0]&255)+", bytes "+bytes.length,e);
            } finally {buf.release();}
        }
        System.out.println("Official codecs decoded "+frames.size()+" captured packets, including "+chunks+" complete chunks and "+loadedRegistries+" loaded registries");
    }

}
