import java.nio.file.*;
import java.util.*;
import com.google.gson.*;
import com.mojang.serialization.JsonOps;
import net.minecraft.SharedConstants;
import net.minecraft.server.Bootstrap;
import net.minecraft.data.registries.VanillaRegistries;
import net.minecraft.core.registries.Registries;
import net.minecraft.world.level.levelgen.*;
import net.minecraft.world.level.levelgen.densityfunction.*;

/** Resource graph and actual scalar samples from checksum-pinned Java 26.3. */
public class ExportWorldgenDensity {
 public static void main(String[] args)throws Exception {
  SharedConstants.tryDetectVersion();Bootstrap.bootStrap();
  var provider=VanillaRegistries.createWorldLookup();var ops=provider.createSerializationContext(JsonOps.INSTANCE);
  var densities=provider.lookupOrThrow(Registries.DENSITY_FUNCTION);var noises=provider.lookupOrThrow(Registries.NOISE);
  var settings=provider.lookupOrThrow(Registries.NOISE_SETTINGS).getOrThrow(NoiseGeneratorSettings.OVERWORLD).value();
  var root=new JsonObject();var definitions=new JsonObject();var observations=new JsonArray();
  var holders=densities.listElements().sorted(Comparator.comparing(h->h.key().identifier().toString())).toList();
  for(var holder:holders)definitions.add(holder.key().identifier().toString(),DensityFunctions.DIRECT_CODEC.encodeStart(ops,holder.value()).getOrThrow());
  int[][] initialPoints={{0,0,0},{1,64,1},{-1,-64,-1},{-17,63,33},{-16,64,32},{-15,65,31},{15,71,15},{16,72,16},{17,73,17},{29999999,319,-29999999},{-29999999,-64,29999999},{0,-65,0},{0,-40,0},{0,240,0},{0,256,0}};
  var points=new ArrayList<int[]>(Arrays.asList(initialPoints));
  for(int distance:new int[]{1023,1024,1025,2048,4096,12345}){points.add(new int[]{distance,64,-distance});points.add(new int[]{-distance,127,distance});}
  var positions=new LegacyRandomSource(2026);for(int i=0;i<16;i++)points.add(new int[]{positions.nextInt(60000001)-30000000,positions.nextInt(512)-128,positions.nextInt(60000001)-30000000});
  for(long seed:new long[]{42,-12345,Long.MIN_VALUE}){
   var state=RandomState.create(noises,seed,settings);
   for(var holder:holders){var entry=new JsonObject();entry.addProperty("name",holder.key().identifier().toString());entry.addProperty("seed",Long.toString(seed));var samples=new JsonArray();
    for(var point:points){var sample=new JsonObject();sample.add("point",new Gson().toJsonTree(point));sample.addProperty("bits",String.format(Locale.ROOT,"%08x",Float.floatToRawIntBits(state.sampleBlockValueUncached(holder.value(),point[0],point[1],point[2]))));samples.add(sample);}
    entry.add("samples",samples);observations.add(entry);
   }
  }
  root.add("definitions",definitions);root.add("observations",observations);
  Files.writeString(Path.of(args[0]),new GsonBuilder().serializeNulls().setPrettyPrinting().create().toJson(root)+"\n");
 }
}
