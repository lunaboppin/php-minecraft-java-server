import net.minecraft.SharedConstants;
import net.minecraft.commands.Commands;
import net.minecraft.core.RegistryAccess;
import net.minecraft.server.RegistryLayer;
import net.minecraft.core.LayeredRegistryAccess;
import net.minecraft.server.ReloadableServerResources;
import net.minecraft.server.WorldLoader;
import net.minecraft.server.packs.repository.ServerPacksSource;
import net.minecraft.server.packs.resources.CloseableResourceManager;
import net.minecraft.server.permissions.PermissionSet;
import net.minecraft.world.level.WorldDataConfiguration;
import net.minecraft.util.RandomSource;
import net.minecraft.world.level.levelgen.LegacyRandomSource;
import java.util.concurrent.atomic.AtomicLong;

/** Load actual vanilla data and tags; construction-only registry lookups cannot resolve all tags. */
public record ReferenceWorldData(
    CloseableResourceManager resources,
    ReloadableServerResources reloadable,
    LayeredRegistryAccess<RegistryLayer> registries
) implements AutoCloseable {
    public static ReferenceWorldData load() {
        if (!SharedConstants.getCurrentVersion().id().equals("26.3")
            || SharedConstants.getCurrentVersion().protocolVersion() != 777) {
            throw new IllegalStateException("Wrong official reference server version");
        }
        var repository = ServerPacksSource.createVanillaTrustedRepository();
        var packs = new WorldLoader.PackConfig(repository, WorldDataConfiguration.DEFAULT, false, true);
        var config = new WorldLoader.InitConfig(packs, Commands.CommandSelection.DEDICATED, PermissionSet.ALL_PERMISSIONS);
        return WorldLoader.<Void, ReferenceWorldData>load(
            config,
            context -> new WorldLoader.DataLoadOutput<>(null, context.datapackDimensions()),
            (resources, reloadable, registries, cookie) -> new ReferenceWorldData(resources, reloadable, registries),
            Runnable::run,
            Runnable::run
        ).join();
    }

    public RegistryAccess.Frozen access() {
        return registries.compositeAccess();
    }

    /** Observe a native legacy stream without consuming the phase's next draws. */
    public static long peekNextLong(RandomSource random) {
        if (!(random instanceof LegacyRandomSource)) throw new IllegalArgumentException("Expected the native legacy random source");
        try {
            var seed = LegacyRandomSource.class.getDeclaredField("seed");
            seed.setAccessible(true);
            var copy = new LegacyRandomSource(0);
            ((AtomicLong) seed.get(copy)).set(((AtomicLong) seed.get(random)).get());
            return copy.nextLong();
        } catch (ReflectiveOperationException failure) {
            throw new IllegalStateException(failure);
        }
    }

    @Override
    public void close() {
        resources.close();
    }
}
