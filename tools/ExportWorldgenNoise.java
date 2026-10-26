import java.nio.file.*;
import java.util.*;
import com.google.gson.*;
import com.mojang.serialization.JsonOps;
import net.minecraft.SharedConstants;
import net.minecraft.server.Bootstrap;
import net.minecraft.data.registries.VanillaRegistries;
import net.minecraft.core.registries.Registries;
import net.minecraft.world.level.levelgen.synth.NormalNoise;
import net.minecraft.util.RandomSource;
import net.minecraft.world.level.levelgen.*;
import net.minecraft.world.level.levelgen.synth.PerlinNoise;

/** Differential fixtures from the pinned Java 26.3 random/noise implementation. */
public class ExportWorldgenNoise {
    static JsonObject operation(String name,Object value){var o=new JsonObject();o.addProperty("operation",name);if(value instanceof Number n)o.addProperty("value",n);else if(value instanceof Boolean b)o.addProperty("value",b);else o.addProperty("value",value.toString());return o;}
    static String longBits(long n){return String.format(Locale.ROOT,"%016x",n);}
    static String doubleBits(double n){return longBits(Double.doubleToRawLongBits(n));}
    static String floatBits(float n){return String.format(Locale.ROOT,"%08x",Float.floatToRawIntBits(n));}
    static void addNormalSamples(String name,NormalNoise value,JsonArray normalSamples){
            for(long seed:new long[]{42,-12345,Long.MIN_VALUE})for(String kind:List.of("legacy","xoroshiro")){
                var random=kind.equals("legacy")?new LegacyRandomSource(seed):new XoroshiroRandomSource(seed);var sampleNoise=value.create(random);var samples=new JsonArray();
                for(double[] pos:new double[][]{{0,0,0},{.125,64.25,-.5},{-17.125,-64,33.875},{29999999,319,-29999999}}){var sample=new JsonObject();sample.add("point",new Gson().toJsonTree(pos));sample.addProperty("bits",floatBits(sampleNoise.get(pos[0],pos[1],pos[2])));sample.addProperty("bits_2d",floatBits(sampleNoise.get(pos[0],pos[2])));samples.add(sample);}
                var sample=new JsonObject();sample.addProperty("name",name);sample.addProperty("kind",kind);sample.addProperty("seed",Long.toString(seed));sample.add("samples",samples);normalSamples.add(sample);
            }
    }
    public static void main(String[] args)throws Exception {
        var root=new JsonObject();var randoms=new JsonArray();var noises=new JsonArray();var arithmetic=new JsonArray();
        long[] seeds={0,1,-1,42,12345,2147483647L,-2147483648L,281474976710655L,Long.MIN_VALUE,Long.MAX_VALUE};
        for(long seed:seeds)for(String kind:List.of("legacy","xoroshiro")){
            RandomSource r=kind.equals("legacy")?new LegacyRandomSource(seed):new XoroshiroRandomSource(seed);
            var entry=new JsonObject();entry.addProperty("seed",Long.toString(seed));entry.addProperty("kind",kind);var ops=new JsonArray();
            int[] bounds={1,2,3,7,16,255,256,65537,1073741825,Integer.MAX_VALUE};
            for(int i=0;i<256;i++){
                switch(i%7){
                    case 0->ops.add(operation("int",r.nextInt()));
                    case 1->{int b=bounds[(i/7)%bounds.length];var o=operation("bounded",r.nextInt(b));o.addProperty("bound",b);ops.add(o);}
                    case 2->ops.add(operation("long",longBits(r.nextLong())));
                    case 3->ops.add(operation("boolean",r.nextBoolean()));
                    case 4->ops.add(operation("float",floatBits(r.nextFloat())));
                    case 5->ops.add(operation("double",doubleBits(r.nextDouble())));
                    case 6->ops.add(operation("gaussian",doubleBits(r.nextGaussian())));
                }
            }
            ops.add(operation("fork",longBits(r.fork().nextLong())));var factory=r.forkPositional();
            for(String name:List.of("","minecraft:offset","octave_-7","🌍seed")){var o=operation("hash",longBits(factory.fromHashOf(name).nextLong()));o.addProperty("name",name);ops.add(o);}
            for(int[] pos:new int[][]{{0,0,0},{1,64,1},{-17,-64,33},{29999999,319,-29999999},{Integer.MIN_VALUE,Integer.MAX_VALUE,-2147483647}}){var o=operation("position",longBits(factory.at(pos[0],pos[1],pos[2]).nextLong()));o.add("position",new Gson().toJsonTree(pos));ops.add(o);}
            for(long derived:seeds){var o=operation("from_seed",longBits(factory.fromSeed(derived).nextLong()));o.addProperty("seed",Long.toString(derived));ops.add(o);}
            r.setSeed(seed);ops.add(operation("reset",doubleBits(r.nextDouble())));entry.add("operations",ops);randoms.add(entry);
            RandomSource noiseRandom=kind.equals("legacy")?new LegacyRandomSource(seed):new XoroshiroRandomSource(seed);var noise=new PerlinNoise(noiseRandom);var samples=new JsonArray();
            double[][] points={{0,0,0},{1,2,3},{-1,-2,-3},{.125,.25,.5},{-16.001,63.999,-32.5},{16777216,0,-16777216},{33554431.999,-64,33554432},{29999999,319,-29999999}};
            for(var point:points){var sample=new JsonObject();sample.add("point",new Gson().toJsonTree(point));sample.addProperty("bits",floatBits(noise.get(point[0],point[1],point[2])));samples.add(sample);}
            var positions=new LegacyRandomSource(12345);
            for(int i=0;i<128;i++){double x=positions.nextDouble()*60000000-30000000;double y=positions.nextDouble()*512-128;double z=positions.nextDouble()*60000000-30000000;var sample=new JsonObject();sample.add("point",new Gson().toJsonTree(new double[]{x,y,z}));sample.addProperty("bits",floatBits(noise.get(x,y,z)));samples.add(sample);}
            var n=new JsonObject();n.addProperty("seed",Long.toString(seed));n.addProperty("kind",kind);n.add("samples",samples);noises.add(n);
        }
        for(long a:seeds)for(long b:seeds){var o=new JsonObject();o.addProperty("a",Long.toString(a));o.addProperty("b",Long.toString(b));o.addProperty("sum",longBits(a+b));o.addProperty("product",longBits(a*b));arithmetic.add(o);}
        SharedConstants.tryDetectVersion();Bootstrap.bootStrap();var provider=VanillaRegistries.createWorldLookup();var registry=provider.lookupOrThrow(Registries.NOISE);
        var normalParameters=new JsonObject();var normalSamples=new JsonArray();
        for(var holder:registry.listElements().sorted(Comparator.comparing(h->h.key().identifier().toString())).toList()){
            String name=holder.key().identifier().toString();var value=holder.value();var parameters=NormalNoise.DIRECT_CODEC.encodeStart(JsonOps.INSTANCE,value).getOrThrow();normalParameters.add(name,parameters);
            addNormalSamples(name,value,normalSamples);
        }
        var custom=JsonParser.parseString("""
        [
          {"base_octave":0},
          {"base_octave":-4,"octave_count":4,"normalize":false,"amplitude_modifiers":[1,0,0.25,2]},
          {"base_octave":-4,"octave_count":4,"normalize":"legacy","amplitude_modifiers":[1,0,0.25,2]},
          {"base_octave":-4,"octave_count":4,"normalize":true,"amplitude_modifiers":[1,0,0.25,2]},
          {"base_octave":0,"octave_count":3,"amplitude_modifiers":[0,0,0]},
          {"base_octave":-32,"octave_count":32},
          {"base_octave":32,"octave_count":32},
          {"base_octave":-1,"base_amplitude":1000000,"amplitude_modifiers":[1000000]}
        ]
        """).getAsJsonArray();
        int customIndex=0;for(var params:custom){String name="test:case_"+(customIndex++);var value=NormalNoise.DIRECT_CODEC.parse(JsonOps.INSTANCE,params).getOrThrow();normalParameters.add(name,params);addNormalSamples(name,value,normalSamples);}
        var invalid=JsonParser.parseString("""
        [ {}, {"base_octave":-33}, {"base_octave":33}, {"base_octave":0,"octave_count":0}, {"base_octave":0,"octave_count":33},
          {"base_octave":0,"base_amplitude":0}, {"base_octave":0,"base_amplitude":1000001}, {"base_octave":0,"base_amplitude":0.000001},
          {"base_octave":0,"amplitude_modifiers":[-1]}, {"base_octave":0,"amplitude_modifiers":[1000001]},
          {"base_octave":0,"normalize":"bad"}, {"base_octave":0,"octave_count":2,"amplitude_modifiers":[1]},
          {"base_octave":0,"base_amplitude":null}, {"base_octave":0,"normalize":null}, {"base_octave":0,"amplitude_modifiers":null}
        ]
        """).getAsJsonArray();
        var validity=new JsonArray();for(var params:invalid){var result=new JsonObject();result.add("parameters",params);result.addProperty("valid",NormalNoise.DIRECT_CODEC.parse(JsonOps.INSTANCE,params).result().isPresent());validity.add(result);}root.add("normal_validity",validity);
        root.add("normal_parameters",normalParameters);root.add("normal_samples",normalSamples);
        root.add("arithmetic",arithmetic);root.add("random",randoms);root.add("perlin",noises);
        Files.writeString(Path.of(args[0]),new GsonBuilder().serializeNulls().setPrettyPrinting().create().toJson(root)+"\n");
    }
}
