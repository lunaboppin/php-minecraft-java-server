import java.nio.file.*;
import java.util.*;
import com.google.gson.*;
import com.mojang.serialization.Lifecycle;
import net.minecraft.SharedConstants;
import net.minecraft.server.Bootstrap;
import net.minecraft.core.*;
import net.minecraft.core.component.DataComponents;
import net.minecraft.core.registries.*;
import net.minecraft.data.registries.VanillaRegistries;
import net.minecraft.network.*;
import net.minecraft.network.protocol.game.*;
import net.minecraft.world.damagesource.*;
import net.minecraft.world.entity.*;
import net.minecraft.world.entity.ai.attributes.*;
import net.minecraft.world.item.*;
import net.minecraft.world.item.component.*;
import io.netty.buffer.*;

/** Default unenchanted item attributes, actual armor results and damage wire codecs. */
public class ExportMobCombat {
 public static void main(String[] args)throws Exception {
  SharedConstants.tryDetectVersion();Bootstrap.bootStrap();var provider=VanillaRegistries.createWorldLookup();
  BuiltInRegistries.DATA_COMPONENT_INITIALIZERS.build(provider).forEach(net.minecraft.core.component.DataComponentInitializers.PendingComponents::apply);
  var root=new JsonObject();var defaults=new JsonObject();
  var player=net.minecraft.world.entity.player.Player.createAttributes().build();double damage=player.getBaseValue(Attributes.ATTACK_DAMAGE),speed=player.getBaseValue(Attributes.ATTACK_SPEED);
  defaults.addProperty("damage",damage);defaults.addProperty("speed",speed);defaults.addProperty("minimum_charge",0);defaults.addProperty("durability_cost",0);root.add("default_item",defaults);
  var items=new JsonObject();
  for(var item:BuiltInRegistries.ITEM){
   var stack=new ItemStack(item);var modifiers=stack.getOrDefault(DataComponents.ATTRIBUTE_MODIFIERS,ItemAttributeModifiers.EMPTY);
   double d=modifiers.compute(Attributes.ATTACK_DAMAGE,damage,EquipmentSlot.MAINHAND),s=modifiers.compute(Attributes.ATTACK_SPEED,speed,EquipmentSlot.MAINHAND);
   float minimum=stack.getOrDefault(DataComponents.MINIMUM_ATTACK_CHARGE,0F);var weapon=stack.get(DataComponents.WEAPON);int cost=weapon==null?0:weapon.itemDamagePerAttack();
   if(d==damage && s==speed && minimum==0 && cost==0)continue;
   var row=new JsonObject();row.addProperty("damage",d);row.addProperty("speed",s);row.addProperty("minimum_charge",minimum);row.addProperty("durability_cost",cost);items.add(BuiltInRegistries.ITEM.getKey(item).getPath(),row);
  }
  root.add("items",items);
  // Invoke the actual player charge methods with only the fields those pure
  // calculations read. This is an isolated oracle, never a running player/session.
  var unsafeField=sun.misc.Unsafe.class.getDeclaredField("theUnsafe");unsafeField.setAccessible(true);var unsafe=(sun.misc.Unsafe)unsafeField.get(null);
  var probe=(net.minecraft.server.level.ServerPlayer)unsafe.allocateInstance(net.minecraft.server.level.ServerPlayer.class);
  var attributesField=LivingEntity.class.getDeclaredField("attributes");attributesField.setAccessible(true);var attributes=new AttributeMap(player);attributesField.set(probe,attributes);
  var ticker=LivingEntity.class.getDeclaredField("attackStrengthTicker");ticker.setAccessible(true);
  var factor=net.minecraft.world.entity.player.Player.class.getDeclaredMethod("baseDamageScaleFactor");factor.setAccessible(true);
  var charged=new JsonArray();
  for(var item:BuiltInRegistries.ITEM){
   String name=BuiltInRegistries.ITEM.getKey(item).getPath();if(!items.has(name)&&!name.equals("air"))continue;
   var params=items.has(name)?items.getAsJsonObject(name):defaults;
   attributes.getInstance(Attributes.ATTACK_SPEED).setBaseValue(params.get("speed").getAsDouble());
   var stack=new ItemStack(item);
   for(int ticks:new int[]{0,1,2,5,10,20,40,100}){
    ticker.setInt(probe,ticks);var row=new JsonObject();row.addProperty("item",name);row.addProperty("ticks",ticks);
    row.addProperty("bits",String.format(Locale.ROOT,"%08x",Float.floatToRawIntBits(params.get("damage").getAsFloat()*(float)factor.invoke(probe))));
    row.addProperty("allowed",!probe.cannotAttackWithItem(stack,1));charged.add(row);
   }
  }
  root.add("charged_attacks",charged);

  var registry=new MappedRegistry<DamageType>(Registries.DAMAGE_TYPE,Lifecycle.stable());
  for(var entry:provider.lookupOrThrow(Registries.DAMAGE_TYPE).listElements().sorted(Comparator.comparing(h->h.key().identifier().toString())).toList())registry.register(entry.key(),entry.value(),RegistrationInfo.BUILT_IN);
  registry.bindAllTagsToEmpty();registry.freeze();var access=new RegistryAccess.ImmutableRegistryAccess(List.of(registry)).freeze();var source=new DamageSource(registry.getOrThrow(DamageTypes.PLAYER_ATTACK));
  var armor=new JsonArray();
  for(float incoming:new float[]{0.25F,1,4,6,8,10,20,100})for(float a:new float[]{0,2,5,10,20,30})for(float toughness:new float[]{0,2,8,20}){
   var row=new JsonObject();row.addProperty("incoming",incoming);row.addProperty("armor",a);row.addProperty("toughness",toughness);
   row.addProperty("bits",String.format(Locale.ROOT,"%08x",Float.floatToRawIntBits(CombatRules.getDamageAfterAbsorb(null,incoming,source,a,toughness))));armor.add(row);
  }
  root.add("armor",armor);var serializers=new JsonObject();serializers.addProperty("boolean",net.minecraft.network.syncher.EntityDataSerializers.getSerializedId(net.minecraft.network.syncher.EntityDataSerializers.BOOLEAN));root.add("serializers",serializers);var fixtures=new JsonObject();
  var event=new RegistryFriendlyByteBuf(Unpooled.buffer(),access);ClientboundDamageEventPacket.STREAM_CODEC.encode(event,new ClientboundDamageEventPacket(1,registry.getOrThrow(DamageTypes.PLAYER_ATTACK),2,2,Optional.empty()));
  var bytes=new byte[event.readableBytes()];event.readBytes(bytes);event.release();fixtures.addProperty("damage_event",HexFormat.of().formatHex(bytes));
  var animation=new FriendlyByteBuf(Unpooled.buffer());ClientboundHurtAnimationPacket.STREAM_CODEC.encode(animation,new ClientboundHurtAnimationPacket(1,90F));bytes=new byte[animation.readableBytes()];animation.readBytes(bytes);animation.release();fixtures.addProperty("hurt_animation",HexFormat.of().formatHex(bytes));root.add("fixtures",fixtures);
  Files.writeString(Path.of(args[0]),new GsonBuilder().setPrettyPrinting().create().toJson(root)+"\n");
 }
}
