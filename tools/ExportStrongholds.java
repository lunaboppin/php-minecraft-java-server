import java.nio.file.*;
import com.google.gson.*;
import net.minecraft.core.registries.Registries;
import net.minecraft.world.level.ChunkPos;
import net.minecraft.world.level.chunk.ChunkGeneratorStructureState;
import net.minecraft.world.level.levelgen.NoiseBasedChunkGenerator;
import net.minecraft.world.level.levelgen.RandomState;
import net.minecraft.world.level.levelgen.presets.WorldPresets;
import net.minecraft.world.level.levelgen.structure.placement.ConcentricRingsStructurePlacement;

/** The Overworld's stronghold ring chunks for a seed (ChunkGeneratorStructureState.getRingPositionsFor). Usage: output seed */
public class ExportStrongholds {
    public static void main(String[] args) throws Exception {
        if (args.length != 2) throw new IllegalArgumentException("Expected output path and seed");
        net.minecraft.SharedConstants.tryDetectVersion();
        net.minecraft.server.Bootstrap.bootStrap();
        long seed = Long.parseLong(args[1]);
        try (var data = ReferenceWorldData.load()) {
            var access = data.access();
            var generator = (NoiseBasedChunkGenerator) access.lookupOrThrow(Registries.WORLD_PRESET).getOrThrow(WorldPresets.NORMAL).value().overworld().orElseThrow().generator();
            RandomState randomState = RandomState.create(access.lookupOrThrow(Registries.NOISE), seed, generator.generatorSettings().value());
            var sets = access.lookupOrThrow(Registries.STRUCTURE_SET);
            var state = ChunkGeneratorStructureState.createForNormal(randomState, seed, ChunkPos.ZERO, generator.getBiomeSource(), sets);
            var placement = (ConcentricRingsStructurePlacement) sets.getOrThrow(net.minecraft.world.level.levelgen.structure.BuiltinStructureSets.STRONGHOLDS).value().placement();
            var out = new JsonArray();
            for (ChunkPos pos : state.getRingPositionsFor(placement)) {
                var entry = new JsonArray();
                entry.add(pos.x());
                entry.add(pos.z());
                out.add(entry);
            }
            Files.writeString(Path.of(args[0]), out + "\n");
        }
    }
}
