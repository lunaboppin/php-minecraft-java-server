import java.nio.file.*;
import java.util.*;
import com.google.gson.*;
import net.minecraft.SharedConstants;
import net.minecraft.server.Bootstrap;
import net.minecraft.util.RandomSource;
import net.minecraft.world.entity.*;
import net.minecraft.world.entity.monster.Giant;
import net.minecraft.world.level.Level;
import net.minecraft.world.phys.*;
import net.minecraft.world.scores.*;

/** Actual native push bodies and team predicate, with explicit dependency flags. */
public class ExportEntityPushing {
    static final JsonArray STATES = new JsonArray();
    static final Map<String, Integer> STATE_IDS = new LinkedHashMap<>();

    static class Probe extends Giant {
        boolean pushable;
        boolean vehicle;
        boolean sameVehicle;
        boolean sleeping;
        boolean spectator;
        PlayerTeam team;

        Probe(Level level) { super(EntityTypes.GIANT, level); }
        @Override public boolean isPushable() { return pushable; }
        @Override public boolean isVehicle() { return vehicle; }
        @Override public boolean isPassengerOfSameVehicle(Entity other) { return sameVehicle; }
        @Override public boolean isSleeping() { return sleeping; }
        @Override public boolean isSpectator() { return spectator; }
        @Override public PlayerTeam getTeam() { return team; }
    }

    static int state(Probe entity) {
        var result = new JsonObject();
        result.add("position_bits", ExportMobPhysics.bits(entity.getX(), entity.getY(), entity.getZ()));
        var velocity = entity.getDeltaMovement();
        result.add("velocity_bits", ExportMobPhysics.bits(velocity.x, velocity.y, velocity.z));
        result.addProperty("needs_sync", entity.needsSync);
        result.addProperty("sync_velocity", entity.syncVelocity);
        result.addProperty("pushable", entity.pushable);
        result.addProperty("vehicle", entity.vehicle);
        result.addProperty("no_physics", entity.noPhysics);
        result.addProperty("sleeping", entity.sleeping);
        result.addProperty("same_vehicle", entity.sameVehicle);
        String key = result.toString();
        if (!STATE_IDS.containsKey(key)) {
            STATE_IDS.put(key, STATES.size());
            STATES.add(result);
        }
        return STATE_IDS.get(key);
    }

    static void reset(Probe entity, double x, double z, Vec3 velocity, int mask, boolean second) throws Exception {
        // Position is assigned directly to admit IEEE edge inputs that the
        // ordinary position setter would sanitize before the phase under test.
        ExportMobPhysics.field(entity, Entity.class, "position", new Vec3(x, 64, z));
        entity.setDeltaMovement(velocity);
        entity.needsSync = (mask & 1) != 0;
        entity.syncVelocity = (mask & 2) != 0;
        entity.pushable = (mask & (second ? 8 : 4)) == 0;
        entity.vehicle = (mask & (second ? 32 : 16)) != 0;
        entity.noPhysics = (mask & (second ? 128 : 64)) != 0;
        entity.sleeping = (mask & (second ? 512 : 256)) != 0;
        entity.sameVehicle = (mask & 1024) != 0;
    }

    public static void main(String[] args) throws Exception {
        if (args.length != 2) throw new IllegalArgumentException("Expected output fixture and mob-class mapping paths");
        SharedConstants.tryDetectVersion(); Bootstrap.bootStrap();
        var root = new JsonObject();
        root.addProperty("version", SharedConstants.getCurrentVersion().id());
        root.addProperty("protocol", SharedConstants.getCurrentVersion().protocolVersion());
        var pushes = new JsonArray();
        var filters = new JsonArray();
        var boxes = new JsonArray();
        var doPushes = new JsonArray();
        try (var data = ReferenceWorldData.load()) {
            var level = ExportMobInitialization.createLevel(data.access());
            ExportMobPhysics.field(level, Level.class, "random", RandomSource.create(0));
            var first = new Probe(level);
            var second = new Probe(level);
            double threshold = (double) .01F;
            double[][] directions = {
                {0, 0}, {Math.nextDown(threshold), 0}, {threshold, 0}, {Math.nextUp(threshold), 0},
                {0, threshold}, {-threshold, 0}, {.5, .25}, {1, 0}, {Math.nextDown(1.0), 1},
                {1, Math.nextUp(1.0)}, {2, -3}, {-16, 32}, {1E-200, 1E-200}, {1E200, -1E200},
                {Double.MAX_VALUE, 1}, {-Double.MAX_VALUE, -Double.MAX_VALUE},
                {Double.NaN, 1}, {1, Double.NaN}, {Double.POSITIVE_INFINITY, 1}, {1, Double.NEGATIVE_INFINITY}
            };
            Vec3[] velocities = {Vec3.ZERO, new Vec3(.2, -.08, -.3), new Vec3(-0.0, 0.0, -0.0)};
            for (int mask = 0; mask < 2048; mask++) {
                for (int index = 0; index < directions.length; index++) {
                    var direction = directions[index];
                    var random = RandomSource.create(771);
                    ExportMobPhysics.field(first, Entity.class, "random", random);
                    reset(first, 0, 0, velocities[mask % velocities.length], mask, false);
                    reset(second, direction[0], direction[1], velocities[(mask + 1) % velocities.length], mask, true);
                    var row = new JsonObject();
                    row.addProperty("mask", mask); row.addProperty("direction", index);
                    row.addProperty("first", state(first)); row.addProperty("second", state(second));
                    first.push(second);
                    var frames = new JsonArray();
                    for (int repeat = 0; repeat < 2; repeat++) {
                        var frame = new JsonObject(); frame.addProperty("first", state(first)); frame.addProperty("second", state(second));
                        frames.add(frame);
                        if (repeat == 0) first.push(second);
                    }
                    row.add("frames", frames);
                    row.addProperty("random_next", Long.toString(ReferenceWorldData.peekNextLong(random)));
                    pushes.add(row);
                }
            }
            var doPush = LivingEntity.class.getDeclaredMethod("doPush", Entity.class);
            doPush.setAccessible(true);
            for (int mask = 0; mask < 2048; mask++) {
                reset(first, 0, 0, velocities[mask % velocities.length], mask, false);
                reset(second, .5, .25, velocities[(mask + 1) % velocities.length], mask, true);
                var row = new JsonObject();
                row.addProperty("mask", mask);
                row.addProperty("first", state(first)); row.addProperty("second", state(second));
                var frames = new JsonArray();
                for (int repeat = 0; repeat < 2; repeat++) {
                    doPush.invoke(first, second);
                    var frame = new JsonObject();
                    frame.addProperty("first", state(first)); frame.addProperty("second", state(second));
                    frames.add(frame);
                }
                row.add("frames", frames); doPushes.add(row);
            }
            var scoreboard = new Scoreboard();
            var teamA = new PlayerTeam(scoreboard, "a");
            var teamB = new PlayerTeam(scoreboard, "b");
            for (int firstRule = -1; firstRule < Team.CollisionRule.values().length; firstRule++) {
                for (int secondRule = -1; secondRule < Team.CollisionRule.values().length; secondRule++) {
                    for (boolean allied : new boolean[]{false, true}) {
                        for (boolean pushable : new boolean[]{false, true}) for (boolean spectator : new boolean[]{false, true}) {
                            first.team = firstRule < 0 ? null : teamA;
                            second.team = secondRule < 0 ? null : (allied ? teamA : teamB);
                            // Allied entities share the actual same team and rule.
                            if (allied && firstRule >= 0 && secondRule >= 0 && firstRule != secondRule) continue;
                            if (first.team != null) first.team.setCollisionRule(Team.CollisionRule.values()[firstRule]);
                            if (second.team != null) second.team.setCollisionRule(Team.CollisionRule.values()[secondRule]);
                            second.pushable = pushable; second.spectator = spectator;
                            var row = new JsonObject();
                            row.addProperty("first_rule", first.team == null ? "always" : first.team.getCollisionRule().name());
                            row.addProperty("second_rule", second.team == null ? "always" : second.team.getCollisionRule().name());
                            row.addProperty("allied", first.team != null && first.team == second.team);
                            row.addProperty("pushable", pushable); row.addProperty("spectator", spectator);
                            row.addProperty("accepted", EntitySelector.pushableBy(first).test(second));
                            filters.add(row);
                        }
                    }
                }
            }
            var origin = new AABB(-1, 0, -1, 1, 2, 1);
            for (double x : new double[]{-3, -2, Math.nextDown(-2.0), Math.nextUp(-2.0), 0, 2, Math.nextDown(2.0), Math.nextUp(2.0), 3}) {
                for (double y : new double[]{-3, -2, Math.nextDown(-2.0), Math.nextUp(-2.0), 0, 2, Math.nextDown(2.0), Math.nextUp(2.0), 3}) {
                    for (double z : new double[]{-3, -2, 0, 2, 3}) {
                        var other = origin.move(x, y, z); var row = new JsonObject();
                        row.add("first_bits", ExportMobPhysics.bits(origin.minX, origin.minY, origin.minZ, origin.maxX, origin.maxY, origin.maxZ));
                        row.add("second_bits", ExportMobPhysics.bits(other.minX, other.minY, other.minZ, other.maxX, other.maxY, other.maxZ));
                        row.addProperty("intersects", origin.intersects(other)); boxes.add(row);
                    }
                }
            }
        }
        root.add("states", STATES);
        root.add("pushes", pushes); root.add("do_pushes", doPushes);
        root.add("filters", filters); root.add("boxes", boxes);
        var profiles = new JsonObject();
        var classes = JsonParser.parseString(Files.readString(Path.of(args[1]))).getAsJsonObject();
        classes.addProperty("player", "net.minecraft.server.level.ServerPlayer");
        for (var entry : classes.entrySet()) {
            var type = Class.forName(entry.getValue().getAsString());
            var profile = new JsonObject();
            profile.addProperty("push", type.getMethod("push", Entity.class).getDeclaringClass().getName());
            profile.addProperty("pushable", type.getMethod("isPushable").getDeclaringClass().getName());
            for (String name : List.of("doPush", "pushEntities")) {
                boolean found = false;
                for (var owner = type; owner != null; owner = owner.getSuperclass()) {
                    try {
                        owner.getDeclaredMethod(name, name.equals("doPush") ? new Class[]{Entity.class} : new Class[]{});
                        profile.addProperty(name, owner.getName()); found = true; break;
                    } catch (NoSuchMethodException ignored) {}
                }
                if (!found) throw new IllegalStateException("Missing native pushing method " + entry.getKey() + "/" + name);
            }
            profiles.add(entry.getKey(), profile);
        }
        var rules = new JsonObject();
        rules.add("version", root.get("version")); rules.add("protocol", root.get("protocol"));
        rules.add("profiles", profiles);
        root.add("profiles", profiles);
        Files.writeString(Path.of(args[0]).resolveSibling("entity-pushing-rules.json"), new GsonBuilder().setPrettyPrinting().create().toJson(rules) + "\n");
        Files.writeString(Path.of(args[0]), new GsonBuilder().setPrettyPrinting().create().toJson(root) + "\n");
        System.out.println("Exported " + pushes.size() + " native push contexts, " + filters.size() + " team filters and " + boxes.size() + " intersections");
    }
}
