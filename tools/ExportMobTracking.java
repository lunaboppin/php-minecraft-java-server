import java.nio.file.*;
import java.util.*;
import java.util.function.Predicate;
import com.google.gson.*;
import io.netty.buffer.Unpooled;
import net.minecraft.SharedConstants;
import net.minecraft.server.Bootstrap;
import net.minecraft.server.level.*;
import net.minecraft.core.*;
import net.minecraft.core.registries.BuiltInRegistries;
import net.minecraft.network.*;
import net.minecraft.network.protocol.Packet;
import net.minecraft.network.protocol.game.*;
import net.minecraft.network.syncher.SynchedEntityData;
import net.minecraft.world.entity.*;
import net.minecraft.world.entity.monster.Giant;
import net.minecraft.world.entity.ai.attributes.AttributeMap;
import net.minecraft.world.entity.ai.attributes.DefaultAttributes;
import net.minecraft.world.phys.Vec3;

/** Calls the actual ServerEntity tracker with isolated initialized entity state. */
public class ExportMobTracking {
 static class Recorder implements ServerEntity.Synchronizer {
  final JsonArray packets=new JsonArray();
  final VecDeltaCodec observed=new VecDeltaCodec();
  Recorder(){observed.setBase(new Vec3(.5,64,.5));}
  final net.minecraft.network.ProtocolInfo<ClientGamePacketListener> protocol=GameProtocols.CLIENTBOUND_TEMPLATE.bind(RegistryFriendlyByteBuf.decorator(RegistryAccess.fromRegistryOfRegistries(BuiltInRegistries.REGISTRY)));
  void record(Packet<? super ClientGamePacketListener> packet,String delivery){
   var buffer=Unpooled.buffer();protocol.codec().encode(buffer,packet);var bytes=new byte[buffer.readableBytes()];buffer.readBytes(bytes);buffer.release();
   if(packet instanceof ClientboundMoveEntityPacket move && move.hasPosition())observed.setBase(move.getPositionDelta().decode(observed).endPosition());
   if(packet instanceof ClientboundEntityPositionSyncPacket absolute)observed.setBase(absolute.position().endPosition());
   var row=new JsonObject();row.addProperty("type",packet.type().id().toString());row.addProperty("delivery",delivery);row.addProperty("hex",HexFormat.of().formatHex(bytes));packets.add(row);
  }
  public void sendToTrackingPlayers(Packet<? super ClientGamePacketListener> packet){record(packet,"tracking");}
  public void sendToTrackingPlayersAndSelf(Packet<? super ClientGamePacketListener> packet){record(packet,"tracking_and_self");}
  public void sendToTrackingPlayersFiltered(Packet<? super ClientGamePacketListener> packet,Predicate<ServerPlayer> filter){throw new IllegalStateException("Unexpected filtered packet in the ordinary tracker probe");}
 }
 static java.lang.reflect.Method method(Class<?> clazz,String name,Class<?>... parameters)throws Exception{
  for(Class<?> owner=clazz;owner!=null;owner=owner.getSuperclass())try{var result=owner.getDeclaredMethod(name,parameters);result.setAccessible(true);return result;}catch(NoSuchMethodException ignored){}
  throw new NoSuchMethodException(clazz.getName()+"."+name);
 }
 @SuppressWarnings({"rawtypes","unchecked"})
 static LivingEntity actor(Vec3 position,Vec3 velocity,boolean ground,String kind,Pose pose)throws Exception {
  var unsafeField=sun.misc.Unsafe.class.getDeclaredField("theUnsafe");unsafeField.setAccessible(true);var unsafe=(sun.misc.Unsafe)unsafeField.get(null);
  Class<?> clazz=switch(kind){case "giant"->Giant.class;case "shulker"->net.minecraft.world.entity.monster.Shulker.class;case "warden"->net.minecraft.world.entity.monster.warden.Warden.class;default->throw new IllegalArgumentException(kind);};
  var type=BuiltInRegistries.ENTITY_TYPE.getValue(net.minecraft.resources.Identifier.withDefaultNamespace(kind));
  var probe=(LivingEntity)unsafe.allocateInstance(clazz);
  ExportMobPhysics.field(probe,Entity.class,"type",type);
  ExportMobPhysics.field(probe,Entity.class,"passengers",com.google.common.collect.ImmutableList.of());
  ExportMobPhysics.field(probe,Entity.class,"position",position);
  ExportMobPhysics.field(probe,Entity.class,"onGround",ground);
  ExportMobPhysics.field(probe,LivingEntity.class,"attributes",new AttributeMap(DefaultAttributes.getSupplier((EntityType)type)));
  var level=(ServerLevel)unsafe.allocateInstance(ServerLevel.class);ExportMobPhysics.field(probe,Entity.class,"level",level);
  var builder=new SynchedEntityData.Builder(probe);
  ExportMobPhysics.baseData(builder,"DATA_SHARED_FLAGS_ID",(byte)0);ExportMobPhysics.baseData(builder,"DATA_AIR_SUPPLY_ID",probe.getMaxAirSupply());ExportMobPhysics.baseData(builder,"DATA_CUSTOM_NAME_VISIBLE",false);ExportMobPhysics.baseData(builder,"DATA_CUSTOM_NAME",Optional.empty());ExportMobPhysics.baseData(builder,"DATA_SILENT",false);ExportMobPhysics.baseData(builder,"DATA_NO_GRAVITY",false);ExportMobPhysics.baseData(builder,"DATA_POSE",pose);ExportMobPhysics.baseData(builder,"DATA_TICKS_FROZEN",0);
  method(clazz,"defineSynchedData",SynchedEntityData.Builder.class).invoke(probe,builder);ExportMobPhysics.field(probe,Entity.class,"entityData",builder.build());
  ExportMobPhysics.field(probe,Entity.class,"interpolationHandler",method(clazz,"createInterpolationHandler").invoke(probe));probe.setId(17);probe.setUUID(UUID.fromString("00010203-0405-0607-0809-0a0b0c0d0e0f"));probe.setDeltaMovement(velocity);return probe;
 }
 static JsonObject state(Vec3 position,Vec3 velocity,float yaw,float pitch,float head,boolean ground,boolean needs,boolean syncVelocity,boolean syncPosition,boolean precise,Vec3 predicted){
  var row=new JsonObject();row.add("position_bits",ExportMobPhysics.bits(position.x,position.y,position.z));row.add("velocity_bits",ExportMobPhysics.bits(velocity.x,velocity.y,velocity.z));row.add("predicted_bits",ExportMobPhysics.bits(predicted.x,predicted.y,predicted.z));
  row.addProperty("yaw_bits",String.format(Locale.ROOT,"%08x",Float.floatToRawIntBits(yaw)));row.addProperty("pitch_bits",String.format(Locale.ROOT,"%08x",Float.floatToRawIntBits(pitch)));row.addProperty("head_bits",String.format(Locale.ROOT,"%08x",Float.floatToRawIntBits(head)));
  row.addProperty("ground",ground);row.addProperty("needs_sync",needs);row.addProperty("sync_velocity",syncVelocity);row.addProperty("sync_position",syncPosition);row.addProperty("precise",precise);return row;
 }
 static void scenario(JsonArray cases,String name,int interval,boolean trackDelta,int count,int mode)throws Exception{
  scenario(cases,name,interval,trackDelta,count,mode,"giant",Pose.STANDING,0);
 }
 static void scenario(JsonArray cases,String name,int interval,boolean trackDelta,int count,int mode,String kind,Pose pose,int startTick)throws Exception{
  var position=new Vec3(.5,64,.5);var velocity=Vec3.ZERO;var probe=actor(position,velocity,true,kind,pose);var recorder=new Recorder();var tracker=new ServerEntity((ServerLevel)probe.level(),probe,UpdateInterval.periodic(interval),trackDelta,recorder);
  ExportMobPhysics.field(tracker,ServerEntity.class,"tickCount",startTick);
  var row=new JsonObject();row.addProperty("pose",pose.name().toLowerCase(Locale.ROOT));var initialBuffer=Unpooled.buffer();recorder.protocol.codec().encode(initialBuffer,probe.getAddEntityPacket(tracker));var initialBytes=new byte[initialBuffer.readableBytes()];initialBuffer.readBytes(initialBytes);initialBuffer.release();row.addProperty("initial_pairing_hex",HexFormat.of().formatHex(initialBytes));row.addProperty("kind",kind);row.addProperty("stepped",probe.getInterpolation().interpolationTracker() instanceof SteppedInterpolationTracker);row.addProperty("initial_tick",startTick);row.addProperty("spawn_data",((ClientboundAddEntityPacket)probe.getAddEntityPacket(tracker)).getData());row.addProperty("name",name);row.addProperty("interval",interval);row.addProperty("track_delta",trackDelta);row.add("initial",state(position,velocity,0,0,0,true,false,false,false,false,Vec3.ZERO));var frames=new JsonArray();
  var random=net.minecraft.util.RandomSource.create(42);
  for(int tick=0;tick<count;tick++){
   float yaw=0,pitch=0,head=0;boolean ground=true,needs=false,syncVelocity=false,syncPosition=false,precise=false;var predicted=Vec3.ZERO;
   if(mode==1){position=new Vec3(.5+.0125*tick,64,.5-Math.scalb((double)tick,-10));velocity=new Vec3(.0125,0,-Math.scalb(1,-10));yaw=(float)(tick*.7-180);head=yaw+22.5F;pitch=(float)((tick%31)-15);}
   if(mode==2){position=new Vec3(position.x+(random.nextDouble()-.5)*.2,64+(tick%13)*.02,position.z+(random.nextDouble()-.5)*.2);velocity=new Vec3(random.nextDouble()*.1-.05,random.nextDouble()*.1-.05,random.nextDouble()*.1-.05);yaw=(float)(random.nextDouble()*1440-720);pitch=(float)(random.nextDouble()*180-90);head=(float)(random.nextDouble()*1440-720);ground=tick%17<8;needs=tick%11==0;syncVelocity=tick%7==0;syncPosition=tick%5==0;precise=tick%23==0;predicted=tick%19==0?new Vec3(.1,0,-.02):Vec3.ZERO;}
   if(mode==3){double[] edges={0,1.0/8192,Math.nextDown(1.0/8192),Math.nextUp(1.0/8192),32767.0/4096,8,-8,Math.nextDown(-8),29999999,-29999999};double edge=edges[tick%edges.length];position=new Vec3(edge,64+edge*.000001,-edge);ground=tick%7!=0;needs=tick%4==0;syncPosition=tick%6==0;precise=tick%13==0;}
   if(mode==4){float[] angles={0,.1F,-.1F,1.40625F,-1.40625F,179.9F,180,180.1F,-180,359.9F,360,-360,100000,Float.MAX_VALUE,-Float.MAX_VALUE,Float.NaN};yaw=angles[tick%angles.length];head=angles[(tick+3)%angles.length];pitch=angles[(tick+7)%angles.length];needs=tick%5==0;}
   ExportMobPhysics.field(probe,Entity.class,"position",position);probe.setDeltaMovement(velocity);ExportMobPhysics.field(probe,Entity.class,"onGround",ground);ExportMobPhysics.field(probe,Entity.class,"yRot",yaw);ExportMobPhysics.field(probe,Entity.class,"xRot",pitch);probe.yHeadRot=head;probe.needsSync=needs;probe.syncVelocity=syncVelocity;probe.syncPosition=syncPosition;probe.setRequiresPrecisePosition(precise);probe.getInterpolation().applyPredictedMovement(predicted);
   var observedPosition=probe.trackingPosition();var observedVelocity=probe.getDeltaMovement();
   var input=state(observedPosition,observedVelocity,probe.getYRot(),probe.getXRot(),probe.getYHeadRot(),probe.onGround(),needs,syncVelocity,syncPosition,precise,predicted);input.add("requested_velocity_bits",ExportMobPhysics.bits(velocity.x,velocity.y,velocity.z));
   recorder.packets.asList().clear();tracker.sendChanges();var frame=new JsonObject();frame.add("state",input);frame.add("packets",recorder.packets.deepCopy());var flags=new JsonObject();flags.addProperty("needs_sync",probe.needsSync);flags.addProperty("sync_velocity",probe.syncVelocity);flags.addProperty("sync_position",probe.syncPosition);frame.add("after_flags",flags);var spawnBuffer=Unpooled.buffer();recorder.protocol.codec().encode(spawnBuffer,probe.getAddEntityPacket(tracker));var spawnBytes=new byte[spawnBuffer.readableBytes()];spawnBuffer.readBytes(spawnBytes);spawnBuffer.release();frame.addProperty("pairing_hex",HexFormat.of().formatHex(spawnBytes));var seen=recorder.observed.getBase();frame.add("observed_bits",ExportMobPhysics.bits(seen.x,seen.y,seen.z));var pairingPosition=tracker.getPositionBase();frame.add("pairing_position_bits",ExportMobPhysics.bits(pairingPosition.x,pairingPosition.y,pairingPosition.z));frames.add(frame);
  }
  row.add("frames",frames);cases.add(row);
 }
 public static void main(String[] args)throws Exception{
  if(args.length!=1)throw new IllegalArgumentException("Expected output fixture path");SharedConstants.tryDetectVersion();Bootstrap.bootStrap();
  if(!SharedConstants.getCurrentVersion().id().equals("26.3") || SharedConstants.getCurrentVersion().protocolVersion()!=777)throw new IllegalStateException("Wrong official server version");
  var root=new JsonObject();root.addProperty("version","26.3");root.addProperty("protocol",777);var cases=new JsonArray();
  scenario(cases,"stationary-forced-updates",3,true,1250,0);scenario(cases,"walking-rotation",3,true,180,1);scenario(cases,"position-boundaries",3,true,120,3);scenario(cases,"angle-boundaries",3,true,128,4);
  for(int interval:new int[]{1,2,3,10,60})for(boolean delta:new boolean[]{false,true})scenario(cases,"flags-interval-"+interval+"-delta-"+delta,interval,delta,240,2);
  scenario(cases,"shulker-linear-flags",3,true,240,2,"shulker",Pose.STANDING,0);
  scenario(cases,"shulker-stationary-forced",3,true,1250,0,"shulker",Pose.STANDING,0);
  scenario(cases,"warden-emerging",3,true,64,1,"warden",Pose.EMERGING,0);
  scenario(cases,"warden-standing",3,true,64,2,"warden",Pose.STANDING,0);
  scenario(cases,"stepped-tick-overflow",3,true,80,2,"giant",Pose.STANDING,Integer.MAX_VALUE-20);
  scenario(cases,"linear-tick-overflow",3,true,80,2,"shulker",Pose.STANDING,Integer.MAX_VALUE-20);
  root.add("cases",cases);Files.writeString(Path.of(args[0]),new GsonBuilder().setPrettyPrinting().create().toJson(root)+"\n");System.out.println("Exported actual ServerEntity tracker scenarios: "+cases.size());
 }
}
