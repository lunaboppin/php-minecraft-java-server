import java.nio.file.*;
import com.google.gson.*;
import net.minecraft.SharedConstants;
import net.minecraft.core.registries.BuiltInRegistries;
import net.minecraft.network.syncher.EntityDataAccessor;
import net.minecraft.server.Bootstrap;
import net.minecraft.world.entity.EntitySpawnReason;
import net.minecraft.world.entity.EntityType;
import net.minecraft.world.entity.ExperienceOrb;
import net.minecraft.world.entity.Mob;
import net.minecraft.world.level.ServerLevelAccessor;
import net.minecraft.world.level.block.DropExperienceBlock;

/**
 * Experience orbs as vanilla drops them: the orb's tracking profile and value field, each mob's
 * xpReward and the class whose getBaseExperienceReward applies, and each block's experience range.
 */
public class ExportExperience {
    public static void main(String[] args) throws Exception {
        if (args.length != 1) throw new IllegalArgumentException("Expected output path");
        SharedConstants.tryDetectVersion();
        Bootstrap.bootStrap();
        var root = new JsonObject();
        root.addProperty("version", SharedConstants.getCurrentVersion().id());
        var orb = new JsonObject();
        orb.addProperty("type", BuiltInRegistries.ENTITY_TYPE.getId(net.minecraft.world.entity.EntityTypes.EXPERIENCE_ORB));
        orb.addProperty("update_interval", net.minecraft.world.entity.EntityTypes.EXPERIENCE_ORB.updateInterval());
        orb.addProperty("track_deltas", net.minecraft.world.entity.EntityTypes.EXPERIENCE_ORB.trackDeltas());
        orb.addProperty("client_tracking_range", net.minecraft.world.entity.EntityTypes.EXPERIENCE_ORB.clientTrackingRange());
        orb.addProperty("width", net.minecraft.world.entity.EntityTypes.EXPERIENCE_ORB.getWidth());
        orb.addProperty("height", net.minecraft.world.entity.EntityTypes.EXPERIENCE_ORB.getHeight());
        var value = ExperienceOrb.class.getDeclaredField("DATA_VALUE");
        value.setAccessible(true);
        orb.addProperty("value_index", ((EntityDataAccessor<?>) value.get(null)).id());
        root.add("orb", orb);
        try (var data = ReferenceWorldData.load()) {
            var level = ExportMobInitialization.createLevel(data.access());
            var reward = Mob.class.getDeclaredField("xpReward");
            reward.setAccessible(true);
            var mobs = new JsonObject();
            for (var type : BuiltInRegistries.ENTITY_TYPE) {
                net.minecraft.world.entity.Entity entity;
                try {
                    entity = type.create(level, EntitySpawnReason.COMMAND);
                } catch (RuntimeException error) {
                    continue;
                }
                if (!(entity instanceof Mob mob)) continue;
                var row = new JsonObject();
                row.addProperty("xp_reward", reward.getInt(mob));
                Class<?> declaring = mob.getClass();
                while (true) {
                    try {
                        declaring.getDeclaredMethod("getBaseExperienceReward", net.minecraft.server.level.ServerLevel.class);
                        break;
                    } catch (NoSuchMethodException missing) {
                        declaring = declaring.getSuperclass();
                    }
                }
                row.addProperty("reward_class", declaring.getSimpleName());
                mobs.add(BuiltInRegistries.ENTITY_TYPE.getKey(type).getPath(), row);
            }
            root.add("mobs", mobs);
        }
        var blocks = new JsonObject();
        var range = DropExperienceBlock.class.getDeclaredField("xpRange");
        range.setAccessible(true);
        for (var block : BuiltInRegistries.BLOCK) {
            if (!(block instanceof DropExperienceBlock)) continue;
            var provider = (net.minecraft.util.valueproviders.IntProvider) range.get(block);
            var pair = new JsonArray();
            pair.add(provider.minInclusive());
            pair.add(provider.maxInclusive());
            blocks.add(BuiltInRegistries.BLOCK.getKey(block).getPath(), pair);
        }
        root.add("blocks", blocks);
        Files.writeString(Path.of(args[0]), new GsonBuilder().setPrettyPrinting().disableHtmlEscaping().create().toJson(root) + "\n");
    }
}
