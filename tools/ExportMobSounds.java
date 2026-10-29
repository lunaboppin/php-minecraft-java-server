import java.nio.file.*;
import java.lang.reflect.*;
import java.util.*;
import com.google.gson.*;
import net.minecraft.SharedConstants;
import net.minecraft.server.Bootstrap;
import net.minecraft.core.registries.BuiltInRegistries;
import net.minecraft.data.registries.VanillaRegistries;
import net.minecraft.network.syncher.SynchedEntityData;
import net.minecraft.resources.Identifier;
import net.minecraft.sounds.SoundEvent;
import net.minecraft.world.entity.*;
import net.minecraft.world.damagesource.*;
import net.minecraft.util.RandomSource;

/** Calls actual default mob sound getters in isolation; does not simulate entity lifecycles. */
public class ExportMobSounds {
 static Method method(Class<?> type,String name,Class<?>... parameters)throws Exception {
  for(Class<?> c=type;c!=null;c=c.getSuperclass()){
   try{var m=c.getDeclaredMethod(name,parameters);m.setAccessible(true);return m;}catch(NoSuchMethodException ignored){}
  }
  throw new NoSuchMethodException(type.getName()+"."+name);
 }
 static void field(Object target,Class<?> declaring,String name,Object value)throws Exception {
  var f=declaring.getDeclaredField(name);f.setAccessible(true);f.set(target,value);
 }
 @SuppressWarnings({"unchecked","rawtypes"})
 static void baseData(SynchedEntityData.Builder builder,String name,Object value)throws Exception {
  var f=Entity.class.getDeclaredField(name);f.setAccessible(true);builder.define((net.minecraft.network.syncher.EntityDataAccessor)f.get(null),value);
 }
 @SuppressWarnings({"unchecked","rawtypes"})
 static void variants(LivingEntity probe,Class<?> clazz,String kind,JsonObject row,net.minecraft.core.RegistryAccess access,DamageSource damage)throws Exception {
  if(!Set.of("cow","pig","chicken","cat","wolf").contains(kind))return;
  var f=clazz.getDeclaredField("DATA_SOUND_VARIANT_ID");f.setAccessible(true);var accessor=(net.minecraft.network.syncher.EntityDataAccessor)f.get(null);
  row.addProperty("variant_index",accessor.id());row.addProperty("variant_serializer",net.minecraft.network.syncher.EntityDataSerializers.getSerializedId(accessor.serializer()));
  var key=net.minecraft.resources.ResourceKey.createRegistryKey(Identifier.parse("minecraft:"+kind+"_sound_variant"));var registry=access.lookupOrThrow(key);
  var getter=SynchedEntityData.class.getDeclaredMethod("getItem",net.minecraft.network.syncher.EntityDataAccessor.class);getter.setAccessible(true);var item=(SynchedEntityData.DataItem)getter.invoke(probe.getEntityData(),accessor);
  var variants=new JsonObject();
  for(var raw:registry.listElements().toList()){
   var holder=(net.minecraft.core.Holder.Reference)raw;item.setValue(holder);var variant=new JsonObject();
   variant.add("hurt",sound((SoundEvent)method(clazz,"getHurtSound",DamageSource.class).invoke(probe,damage)));variant.add("death",sound((SoundEvent)method(clazz,"getDeathSound").invoke(probe)));
   var buffer=new net.minecraft.network.RegistryFriendlyByteBuf(io.netty.buffer.Unpooled.buffer(),access);
   net.minecraft.network.protocol.game.ClientboundSetEntityDataPacket.STREAM_CODEC.encode(buffer,new net.minecraft.network.protocol.game.ClientboundSetEntityDataPacket(1,List.of(item.value())));
   var bytes=new byte[buffer.readableBytes()];buffer.readBytes(bytes);buffer.release();variant.addProperty("metadata_hex",HexFormat.of().formatHex(bytes));variants.add(holder.key().identifier().toString(),variant);
  }
  row.add("variants",variants);
  var picker=Class.forName(clazz.getPackageName()+"."+clazz.getSimpleName()+"SoundVariants").getMethod("pickRandomSoundVariant",net.minecraft.core.RegistryAccess.class,RandomSource.class);
  var selections=new JsonArray();for(long seed:new long[]{0,1,2,3,42,-1,Long.MIN_VALUE,Long.MAX_VALUE}){
   var selected=(net.minecraft.core.Holder.Reference)picker.invoke(null,access,RandomSource.create(seed));var sample=new JsonObject();sample.addProperty("seed",Long.toString(seed));sample.addProperty("variant",selected.key().identifier().toString());selections.add(sample);
  }row.add("selections",selections);
 }
 static JsonElement sound(SoundEvent value){
  if(value==null)return JsonNull.INSTANCE;
  var row=new JsonObject();row.addProperty("name",BuiltInRegistries.SOUND_EVENT.getKey(value).toString());row.addProperty("id",BuiltInRegistries.SOUND_EVENT.getId(value));
  row.add("fixed_range",value.fixedRange().<JsonElement>map(v->new JsonPrimitive((double)v)).orElse(JsonNull.INSTANCE));
  var ranges=new JsonArray();for(float volume:new float[]{.1F,.4F,.8F,1F,4F,5F}){var r=new JsonObject();r.addProperty("volume",(double)volume);r.addProperty("range",(double)value.getRange(volume));ranges.add(r);}row.add("ranges",ranges);return row;
 }
 public static void main(String[] args)throws Exception {
  SharedConstants.tryDetectVersion();Bootstrap.bootStrap();var provider=VanillaRegistries.createWorldLookup();
  BuiltInRegistries.DATA_COMPONENT_INITIALIZERS.build(provider).forEach(net.minecraft.core.component.DataComponentInitializers.PendingComponents::apply);
  var registries=new ArrayList<net.minecraft.core.Registry<?>>();
  for(var lookup:provider.listRegistries().toList())if(lookup.key().identifier().getPath().endsWith("_variant"))registries.add(copyRegistry(lookup));
  var registryAccess=new net.minecraft.core.RegistryAccess.ImmutableRegistryAccess(registries).freeze();
  var unsafeField=sun.misc.Unsafe.class.getDeclaredField("theUnsafe");unsafeField.setAccessible(true);var unsafe=(sun.misc.Unsafe)unsafeField.get(null);
  var classes=JsonParser.parseString(Files.readString(Path.of(args[0]))).getAsJsonObject();var root=new JsonObject();var mobs=new JsonObject();
  var damageType=provider.lookupOrThrow(net.minecraft.core.registries.Registries.DAMAGE_TYPE).getOrThrow(DamageTypes.GENERIC);var damage=new DamageSource(damageType);
  for(var entry:classes.entrySet()){
   var clazz=Class.forName(entry.getValue().getAsString());var probe=(LivingEntity)unsafe.allocateInstance(clazz);
   field(probe,Entity.class,"type",BuiltInRegistries.ENTITY_TYPE.getValue(Identifier.parse("minecraft:"+entry.getKey())));
   field(probe,Entity.class,"random",RandomSource.create(42));
   field(probe,LivingEntity.class,"equipment",new EntityEquipment());
   // AgeableMob's pure getAge branches on Level.isClientSide; this isolated
   // server-level shell supplies that false flag, not an operational world.
   var level=unsafe.allocateInstance(net.minecraft.server.level.ServerLevel.class);field(level,net.minecraft.world.level.Level.class,"registryAccess",registryAccess);field(probe,Entity.class,"level",level);
   var builder=new SynchedEntityData.Builder(probe);
   // These eight definitions live in Entity's constructor, before the virtual
   // defineSynchedData call. Copy its pinned bytecode defaults, then invoke the
   // actual subclass definitions; fail if any accessor remains undefined.
   baseData(builder,"DATA_SHARED_FLAGS_ID",(byte)0);baseData(builder,"DATA_AIR_SUPPLY_ID",probe.getMaxAirSupply());
   baseData(builder,"DATA_CUSTOM_NAME_VISIBLE",false);baseData(builder,"DATA_CUSTOM_NAME",Optional.empty());
   baseData(builder,"DATA_SILENT",false);baseData(builder,"DATA_NO_GRAVITY",false);baseData(builder,"DATA_POSE",Pose.STANDING);baseData(builder,"DATA_TICKS_FROZEN",0);
   method(clazz,"defineSynchedData",SynchedEntityData.Builder.class).invoke(probe,builder);field(probe,Entity.class,"entityData",builder.build());
   var row=new JsonObject();
   try{
    row.add("hurt",sound((SoundEvent)method(clazz,"getHurtSound",DamageSource.class).invoke(probe,damage)));
    row.add("death",sound((SoundEvent)method(clazz,"getDeathSound").invoke(probe)));
    row.addProperty("volume",(double)(float)method(clazz,"getSoundVolume").invoke(probe));
    // LivingEntity.getFallSounds: the small and big fall damage sounds.
    var falls=probe.getFallSounds();row.add("small_fall",sound(falls.small()));row.add("big_fall",sound(falls.big()));
    row.addProperty("category",probe.getSoundSource().ordinal());
    row.addProperty("pitch_method",clazz.getMethod("getVoicePitch").getDeclaringClass().getSimpleName());
    var pitches=new JsonArray();for(long seed:new long[]{0,1,42,-1,Long.MIN_VALUE,Long.MAX_VALUE}){
     field(probe,Entity.class,"random",RandomSource.create(seed));var sample=new JsonObject();sample.addProperty("seed",Long.toString(seed));sample.addProperty("bits",String.format(Locale.ROOT,"%08x",Float.floatToRawIntBits(probe.getVoicePitch())));pitches.add(sample);
    }row.add("pitches",pitches);
    variants(probe,clazz,entry.getKey(),row,registryAccess,damage);
   }catch(Exception ex){throw new RuntimeException("Default sound getter failed for "+entry.getKey(),ex);}
   mobs.add(entry.getKey(),row);
  }
  root.add("mobs",mobs);
  // LivingEntity.playBlockFallSound: each block's default sound type fall sound, volume and pitch.
  var blocks=new JsonObject();
  for(var block:BuiltInRegistries.BLOCK){
   var type=block.defaultBlockState().getSoundType();var row=new JsonObject();
   var fall=type.getFallSound();row.addProperty("name",BuiltInRegistries.SOUND_EVENT.getKey(fall).toString());row.addProperty("id",BuiltInRegistries.SOUND_EVENT.getId(fall));
   row.add("fixed_range",fall.fixedRange().<JsonElement>map(v->new JsonPrimitive((double)v)).orElse(JsonNull.INSTANCE));
   row.addProperty("volume",(double)type.getVolume());row.addProperty("pitch",(double)type.getPitch());
   blocks.add(BuiltInRegistries.BLOCK.getKey(block).toString(),row);
  }
  root.add("block_falls",blocks);
  // HoneyBlock.fallOn plays its slide sound on landing.
  root.add("honey_block_slide",sound(net.minecraft.sounds.SoundEvents.HONEY_BLOCK_SLIDE));
  var serializers=new JsonObject();
  for(var f:net.minecraft.network.syncher.EntityDataSerializers.class.getFields()){
   if(Set.of("BYTE","INT","FLOAT","BOOLEAN","POSE").contains(f.getName())||f.getName().endsWith("_VARIANT"))serializers.addProperty(f.getName(),net.minecraft.network.syncher.EntityDataSerializers.getSerializedId((net.minecraft.network.syncher.EntityDataSerializer<?>)f.get(null)));
  }root.add("serializers",serializers);
  var fixtures=new JsonArray();var codecAccess=net.minecraft.core.RegistryAccess.fromRegistryOfRegistries(BuiltInRegistries.REGISTRY);
  for(double[] position:new double[][]{{0,64,0},{-0.01,-0.125,0.125},{-2.6,70.75,-3.51},{29999999.125,319,-29999999.125}}){
   var holder=BuiltInRegistries.SOUND_EVENT.get(Identifier.parse("minecraft:entity.cow.hurt")).orElseThrow();
   var buffer=new net.minecraft.network.RegistryFriendlyByteBuf(io.netty.buffer.Unpooled.buffer(),codecAccess);
   net.minecraft.network.protocol.game.ClientboundSoundPacket.STREAM_CODEC.encode(buffer,new net.minecraft.network.protocol.game.ClientboundSoundPacket(holder,net.minecraft.sounds.SoundSource.NEUTRAL,position[0],position[1],position[2],.4F,1.125F,-42L));
   var bytes=new byte[buffer.readableBytes()];buffer.readBytes(bytes);buffer.release();var fixture=new JsonObject();var pos=new JsonArray();for(double p:position)pos.add(p);fixture.add("position",pos);fixture.addProperty("hex",HexFormat.of().formatHex(bytes));fixtures.add(fixture);
  }
  root.add("fixtures",fixtures);Files.writeString(Path.of(args[1]),new GsonBuilder().setPrettyPrinting().serializeNulls().create().toJson(root)+"\n");
 }
 @SuppressWarnings({"unchecked","rawtypes"})
 static <T> net.minecraft.core.Registry<T> copyRegistry(net.minecraft.core.HolderLookup.RegistryLookup<T> lookup){
  var registry=new net.minecraft.core.MappedRegistry<T>((net.minecraft.resources.ResourceKey)lookup.key(),com.mojang.serialization.Lifecycle.stable());
  for(var holder:lookup.listElements().sorted(Comparator.comparing(h->h.key().identifier().toString())).toList())registry.register(holder.key(),holder.value(),net.minecraft.core.RegistrationInfo.BUILT_IN);
  registry.bindAllTagsToEmpty();return registry.freeze();
 }
}
