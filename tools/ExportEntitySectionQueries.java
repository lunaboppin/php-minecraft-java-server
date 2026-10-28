import java.nio.file.*;
import java.util.*;
import com.google.gson.*;
import net.minecraft.SharedConstants;
import net.minecraft.core.SectionPos;
import net.minecraft.server.Bootstrap;
import net.minecraft.util.*;
import net.minecraft.world.entity.*;
import net.minecraft.world.level.Level;
import net.minecraft.world.level.entity.*;
import net.minecraft.world.phys.*;

/** Native EntitySectionStorage queries after explicit add/move/remove/visibility events. */
public class ExportEntitySectionQueries {
    static final Map<Integer, Entity> ENTITIES = new LinkedHashMap<>();
    static final Map<Integer, Long> MEMBERSHIP = new HashMap<>();
    static final Map<Long, Visibility> VISIBILITY = new HashMap<>();
    static final EntitySectionStorage<Entity> STORAGE = new EntitySectionStorage<>(Entity.class, key -> Visibility.TICKING);

    static JsonArray query(AABB box) {
        var result = new JsonArray();
        STORAGE.getEntities(box, entity -> { result.add(entity.getId()); return Continuation.CONTINUE; });
        return result;
    }

    static JsonArray bounds(AABB box) {
        return ExportMobPhysics.bits(box.minX, box.minY, box.minZ, box.maxX, box.maxY, box.maxZ);
    }

    static void remove(int id) {
        var entity = ENTITIES.remove(id);
        Long key = MEMBERSHIP.remove(id);
        if (entity != null) {
            var section = STORAGE.getOrCreateSection(key);
            section.remove(entity);
            if (section.isEmpty()) STORAGE.remove(key);
        }
    }

    static void upsert(Entity entity, Vec3 position, AABB bounds) {
        long key = SectionPos.asLong(entity.blockPosition());
        Long previous = MEMBERSHIP.get(entity.getId());
        if (previous != null && previous != key) remove(entity.getId());
        if (previous == null || previous != key) {
            var section = STORAGE.getOrCreateSection(key);
            section.updateChunkStatus(VISIBILITY.getOrDefault(key, Visibility.TICKING));
            section.add(entity);
        }
        MEMBERSHIP.put(entity.getId(), key);
        ENTITIES.put(entity.getId(), entity);
    }

    public static void main(String[] args) throws Exception {
        if (args.length != 1) throw new IllegalArgumentException("Expected output fixture path");
        SharedConstants.tryDetectVersion(); Bootstrap.bootStrap();
        var root = new JsonObject();
        root.addProperty("version", SharedConstants.getCurrentVersion().id());
        root.addProperty("protocol", SharedConstants.getCurrentVersion().protocolVersion());
        var events = new JsonArray();
        try (var data = ReferenceWorldData.load()) {
            var level = ExportMobInitialization.createLevel(data.access());
            ExportMobPhysics.field(level, Level.class, "random", RandomSource.create(0));
            var random = new Random(771);
            double[] coordinates = {-17, -1, 0, Math.nextDown(16.0), 16, Math.nextUp(16.0), 32};
            var positions = new ArrayList<Vec3>();
            for (double x : coordinates) for (double y : coordinates) for (double z : coordinates) positions.add(new Vec3(x, y, z));
            positions.add(new Vec3(29999984, 64, 29999984));
            positions.add(new Vec3(-29999984, -64, -29999984));
            Collections.shuffle(positions, random);
            for (int step = 0; step < positions.size() + 512; step++) {
                var row = new JsonObject();
                int id = step < positions.size() ? step + 100 : random.nextInt(positions.size()) + 100;
                Entity entity = ENTITIES.get(id);
                var position = positions.get(step < positions.size() ? step : random.nextInt(positions.size()));
                int operation = step < positions.size() ? 0 : random.nextInt(5);
                if (operation == 1) {
                    remove(id); row.addProperty("op", "remove"); row.addProperty("id", id);
                } else if (operation == 2) {
                    long key = SectionPos.asLong(SectionPos.posToSectionCoord(position.x), SectionPos.posToSectionCoord(position.y), SectionPos.posToSectionCoord(position.z));
                    var status = Visibility.values()[random.nextInt(Visibility.values().length)];
                    VISIBILITY.put(key, status);
                    STORAGE.getOrCreateSection(key).updateChunkStatus(status);
                    row.addProperty("op", "visibility");
                    var coordinatesRow = new JsonArray(); coordinatesRow.add(SectionPos.x(key)); coordinatesRow.add(SectionPos.y(key)); coordinatesRow.add(SectionPos.z(key));
                    row.add("section", coordinatesRow); row.addProperty("accessible", status.isAccessible());
                } else {
                    if (entity == null) { entity = new ExportEntityPushing.Probe(level); entity.setId(id); }
                    entity.setPos(position);
                    float width = new float[]{.6F, 3.6F, 16F}[step % 3];
                    float height = new float[]{1.8F, 12F, 2F}[step % 3];
                    var box = EntityDimensions.scalable(width, height).makeBoundingBox(position);
                    entity.setBoundingBox(box);
                    upsert(entity, position, box);
                    row.addProperty("op", "upsert"); row.addProperty("id", id);
                    row.add("position_bits", ExportMobPhysics.bits(position.x, position.y, position.z));
                    row.add("bounds_bits", bounds(box));
                }
                var queries = new JsonArray();
                for (var box : List.of(new AABB(-40, -40, -40, 40, 60, 40), new AABB(position.x - 2, position.y - 1, position.z - 2, position.x + 2, position.y + 4, position.z + 2))) {
                    var query = new JsonObject(); query.add("bounds_bits", bounds(box)); query.add("ids", query(box)); queries.add(query);
                }
                row.add("queries", queries); events.add(row);
            }
        }
        root.add("events", events);
        Files.writeString(Path.of(args[0]), new GsonBuilder().setPrettyPrinting().create().toJson(root) + "\n");
        System.out.println("Exported " + events.size() + " native section membership events and " + events.size() * 2 + " ordered queries");
    }
}
