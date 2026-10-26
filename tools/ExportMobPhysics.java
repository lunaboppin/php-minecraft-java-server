import java.nio.file.*;
import java.util.*;
import com.google.gson.*;
import io.netty.buffer.Unpooled;
import net.minecraft.SharedConstants;
import net.minecraft.server.Bootstrap;
import net.minecraft.network.LpVec3;
import net.minecraft.network.protocol.game.ClientboundSetEntityMotionPacket;
import net.minecraft.util.RandomSource;
import net.minecraft.world.phys.Vec3;
import net.minecraft.world.entity.*;
import net.minecraft.world.entity.monster.Giant;
import net.minecraft.world.entity.ai.attributes.*;
import net.minecraft.data.registries.VanillaRegistries;
import net.minecraft.core.registries.*;
import net.minecraft.world.damagesource.*;

/** Actual protocol-777 motion codecs and pure entity-physics reference methods. */
public class ExportMobPhysics {
 static void field(Object target,Class<?> declaring,String name,Object value)throws Exception {
  var f=declaring.getDeclaredField(name);f.setAccessible(true);f.set(target,value);
 }
 @SuppressWarnings({"rawtypes","unchecked"})
 static void baseData(net.minecraft.network.syncher.SynchedEntityData.Builder builder,String name,Object value)throws Exception {
  var f=Entity.class.getDeclaredField(name);f.setAccessible(true);builder.define((net.minecraft.network.syncher.EntityDataAccessor)f.get(null),value);
 }
 static JsonArray bits(double... values){
  var out=new JsonArray();for(double value:values)out.add(String.format(Locale.ROOT,"%016x",Double.doubleToRawLongBits(value)));return out;
 }
 public static void main(String[] args)throws Exception {
  if(args.length!=2)throw new IllegalArgumentException("Expected fixture output path and official mob class mapping");
  SharedConstants.tryDetectVersion();Bootstrap.bootStrap();
  var root=new JsonObject();var vectors=new ArrayList<Vec3>();vectors.add(Vec3.ZERO);vectors.add(new Vec3(-0.0,0.0,-0.0));
  for(double boundary:new double[]{LpVec3.ABS_MIN_VALUE,.5,1,2,3,4,7,8,Math.scalb(1,31),Math.scalb(1,33),LpVec3.ABS_MAX_VALUE}){
   for(double value:new double[]{Math.nextDown(boundary),boundary,Math.nextUp(boundary)}){
    vectors.add(new Vec3(value,-value,value/2));vectors.add(new Vec3(-value,value/2,-value));
   }
  }
  vectors.add(new Vec3(Double.NaN,Double.POSITIVE_INFINITY,Double.NEGATIVE_INFINITY));
  var random=RandomSource.create(42);
  for(int i=0;i<512;i++)vectors.add(new Vec3(Math.scalb(random.nextDouble()*2-1,random.nextInt(61)-20),Math.scalb(random.nextDouble()*2-1,random.nextInt(61)-20),Math.scalb(random.nextDouble()*2-1,random.nextInt(61)-20)));
  var rows=new JsonArray();
  for(var vector:vectors){
   var row=new JsonObject();row.add("input_bits",bits(vector.x,vector.y,vector.z));var buffer=Unpooled.buffer();LpVec3.write(buffer,vector);
   var bytes=new byte[buffer.readableBytes()];buffer.getBytes(0,bytes);row.addProperty("hex",HexFormat.of().formatHex(bytes));var decoded=LpVec3.read(buffer);if(buffer.isReadable())throw new IllegalStateException("Trailing LpVec3 bytes");buffer.release();row.add("decoded_bits",bits(decoded.x,decoded.y,decoded.z));
   buffer=Unpooled.buffer();ClientboundSetEntityMotionPacket.STREAM_CODEC.encode(buffer,new ClientboundSetEntityMotionPacket(17,vector));bytes=new byte[buffer.readableBytes()];buffer.readBytes(bytes);buffer.release();row.addProperty("motion_hex",HexFormat.of().formatHex(bytes));rows.add(row);
  }
  root.add("vectors",rows);
  var provider=VanillaRegistries.createWorldLookup();
  var source=new DamageSource(provider.lookupOrThrow(Registries.DAMAGE_TYPE).getOrThrow(DamageTypes.GENERIC));
  var unsafeField=sun.misc.Unsafe.class.getDeclaredField("theUnsafe");unsafeField.setAccessible(true);var unsafe=(sun.misc.Unsafe)unsafeField.get(null);
  var probe=(Giant)unsafe.allocateInstance(Giant.class);field(probe,LivingEntity.class,"attributes",new AttributeMap(Giant.createAttributes().build()));field(probe,Entity.class,"passengers",com.google.common.collect.ImmutableList.of());
  var data=new net.minecraft.network.syncher.SynchedEntityData.Builder(probe);
  baseData(data,"DATA_SHARED_FLAGS_ID",(byte)0);baseData(data,"DATA_AIR_SUPPLY_ID",probe.getMaxAirSupply());baseData(data,"DATA_CUSTOM_NAME_VISIBLE",false);baseData(data,"DATA_CUSTOM_NAME",Optional.empty());baseData(data,"DATA_SILENT",false);baseData(data,"DATA_NO_GRAVITY",false);baseData(data,"DATA_POSE",Pose.STANDING);baseData(data,"DATA_TICKS_FROZEN",0);
  var definitions=Mob.class.getDeclaredMethod("defineSynchedData",net.minecraft.network.syncher.SynchedEntityData.Builder.class);definitions.setAccessible(true);definitions.invoke(probe,data);field(probe,Entity.class,"entityData",data.build());
  var knockbacks=new JsonArray();
  for(double[] velocity:new double[][]{{0,0,0},{.2,.1,-.3},{-1,-.2,.8}})for(boolean ground:new boolean[]{false,true})for(double resistance:new double[]{0,.1,.5,1})for(double strength:new double[]{0,(double).4F,.7,2})for(double[] direction:new double[][]{{1,0},{0,1},{.001,.001},{-.5,2},{0,0}})for(long seed:new long[]{0,42,-1}){
   probe.setDeltaMovement(new Vec3(velocity[0],velocity[1],velocity[2]));probe.needsSync=false;field(probe,Entity.class,"onGround",ground);field(probe,Entity.class,"random",RandomSource.create(seed));probe.getAttribute(Attributes.KNOCKBACK_RESISTANCE).setBaseValue(resistance);
   probe.knockback(strength,direction[0],direction[1],source,1F,false);var after=probe.getDeltaMovement();var row=new JsonObject();row.add("velocity_bits",bits(velocity));row.addProperty("ground",ground);row.addProperty("resistance",resistance);row.addProperty("strength",strength);row.add("direction_bits",bits(direction));row.addProperty("seed",Long.toString(seed));row.add("expected_bits",bits(after.x,after.y,after.z));row.addProperty("synchronize",probe.needsSync);knockbacks.add(row);
  }root.add("knockbacks",knockbacks);
  var inputMethod=Entity.class.getDeclaredMethod("getInputVector",Vec3.class,float.class,float.class);inputMethod.setAccessible(true);var inputs=new JsonArray();
  for(double[] vector:new double[][]{{0,0,0},{.00001,0,.00001},{.1,0,.2},{1,0,1},{3,-.25,-2},{-1,.7,0}})for(float speed:new float[]{0,.02F,.2F,1F})for(float yaw:new float[]{0,22.5F,-90,180,359.9F,720.1F,Float.MAX_VALUE,-Float.MAX_VALUE,Float.NaN}){
   var value=(Vec3)inputMethod.invoke(null,new Vec3(vector[0],vector[1],vector[2]),speed,yaw);var row=new JsonObject();row.add("input_bits",bits(vector));row.addProperty("speed_bits",String.format(Locale.ROOT,"%08x",Float.floatToRawIntBits(speed)));row.addProperty("yaw_bits",String.format(Locale.ROOT,"%08x",Float.floatToRawIntBits(yaw)));row.add("expected_bits",bits(value.x,value.y,value.z));inputs.add(row);
  }root.add("inputs",inputs);
  var frictionMethod=LivingEntity.class.getDeclaredMethod("computeModifiedFriction",float.class,float.class);frictionMethod.setAccessible(true);var frictions=new JsonArray();
  for(float base:new float[]{0,.1F,.6F,.8F,.91F,.98F,1F})for(float modifier:new float[]{0,.5F,1,1.5F,2,4}){
   var row=new JsonObject();row.addProperty("base",(double)base);row.addProperty("modifier",(double)modifier);row.addProperty("bits",String.format(Locale.ROOT,"%08x",Float.floatToRawIntBits((float)frictionMethod.invoke(null,base,modifier))));frictions.add(row);
  }root.add("frictions",frictions);
  var speedMethod=LivingEntity.class.getDeclaredMethod("getFrictionInfluencedSpeed",float.class);speedMethod.setAccessible(true);var speeds=new JsonArray();
  for(boolean ground:new boolean[]{false,true})for(float speed:new float[]{0,.02F,.1F,.2F,.5F,1F})for(float friction:new float[]{.4F,.6F,.8F,.98F,1F}){
   field(probe,Entity.class,"onGround",ground);probe.setSpeed(speed);var row=new JsonObject();row.addProperty("ground",ground);row.addProperty("speed",(double)speed);row.addProperty("friction",(double)friction);row.addProperty("bits",String.format(Locale.ROOT,"%08x",Float.floatToRawIntBits((float)speedMethod.invoke(probe,friction))));speeds.add(row);
  }root.add("speeds",speeds);
  var sineField=net.minecraft.util.Mth.class.getDeclaredField("SIN");sineField.setAccessible(true);var table=(float[])sineField.get(null);var sine=java.nio.ByteBuffer.allocate(table.length*4);for(float value:table)sine.putFloat(value);Files.write(Path.of(args[0]).resolveSibling("entity-sine.bin"),sine.array());
  var constants=new JsonObject();constants.addProperty("default_block_friction",(double).6F);var blockFriction=new JsonObject();
  for(var block:BuiltInRegistries.BLOCK)if(block.getFriction()!=.6F)blockFriction.addProperty(BuiltInRegistries.BLOCK.getKey(block).getPath(),(double)block.getFriction());constants.add("block_friction",blockFriction);
  var bounce=new JsonObject();for(var block:BuiltInRegistries.BLOCK)if(block.getBounceRestitution()!=0F)bounce.addProperty(BuiltInRegistries.BLOCK.getKey(block).getPath(),(double)block.getBounceRestitution());constants.add("block_bounce_restitution",bounce);
  field(probe,Entity.class,"position",Vec3.ZERO);probe.setDeltaMovement(Vec3.ZERO);field(probe,Entity.class,"onGround",true);probe.getAttribute(Attributes.KNOCKBACK_RESISTANCE).setBaseValue(0);probe.dealDefaultKnockback(new DamageSource(provider.lookupOrThrow(Registries.DAMAGE_TYPE).getOrThrow(DamageTypes.GENERIC),new Vec3(1,0,0)),1F,false);constants.addProperty("default_knockback_strength",-probe.getDeltaMovement().x);
  var mapping=JsonParser.parseString(Files.readString(Path.of(args[1]))).getAsJsonObject();var profiles=new JsonObject();var bounds=new JsonArray();
  for(var entry:mapping.entrySet()){
   var clazz=Class.forName(entry.getValue().getAsString());var type=BuiltInRegistries.ENTITY_TYPE.getValue(net.minecraft.resources.Identifier.parse("minecraft:"+entry.getKey()));var profile=new JsonObject();profile.addProperty("update_interval",type.updateInterval());profile.addProperty("travel_owner",clazz.getMethod("travel",Vec3.class).getDeclaringClass().getName());
   for(Class<?> owner=clazz;owner!=null;owner=owner.getSuperclass()){
    try{owner.getDeclaredMethod("omnidirectionalAirMover");profile.addProperty("omnidirectional_owner",owner.getName());break;}catch(NoSuchMethodException ignored){}
   }profiles.add(entry.getKey(),profile);
   for(double[] position:new double[][]{{.5,64,.5},{-17.125,-63.5,32.75},{29999999,255,-29999999}}){
    var box=type.getDimensions().makeBoundingBox(position[0],position[1],position[2]);var row=new JsonObject();row.addProperty("kind",entry.getKey());row.add("position_bits",bits(position));row.add("expected_bits",bits(box.minX,box.minY,box.minZ,box.maxX,box.maxY,box.maxZ));bounds.add(row);
   }
  }constants.add("profiles",profiles);root.add("bounds",bounds);
  Files.writeString(Path.of(args[0]).resolveSibling("movement.json"),new GsonBuilder().setPrettyPrinting().create().toJson(constants)+"\n");
  Files.writeString(Path.of(args[0]),new GsonBuilder().setPrettyPrinting().create().toJson(root)+"\n");System.out.println("Exported "+rows.size()+" actual motion codec, "+knockbacks.size()+" knockback, "+inputs.size()+" input, "+frictions.size()+" friction and "+speeds.size()+" speed cases");
 }
}
