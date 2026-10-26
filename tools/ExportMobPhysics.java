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

/** Actual protocol-777 motion codecs and pure entity-physics reference methods. */
public class ExportMobPhysics {
 static JsonArray bits(double... values){
  var out=new JsonArray();for(double value:values)out.add(String.format(Locale.ROOT,"%016x",Double.doubleToRawLongBits(value)));return out;
 }
 public static void main(String[] args)throws Exception {
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
  root.add("vectors",rows);Files.writeString(Path.of(args[0]),new GsonBuilder().setPrettyPrinting().create().toJson(root)+"\n");System.out.println("Exported "+rows.size()+" actual low-precision motion codec cases");
 }
}
