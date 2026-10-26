import java.nio.file.*;
import java.util.*;
import com.google.gson.*;
import com.mojang.brigadier.tree.*;
import net.minecraft.SharedConstants;
import net.minecraft.server.Bootstrap;
import net.minecraft.commands.*;
import net.minecraft.commands.synchronization.*;
import net.minecraft.core.registries.BuiltInRegistries;
import net.minecraft.data.registries.VanillaRegistries;
import net.minecraft.world.flag.FeatureFlags;
import net.minecraft.server.permissions.*;

/** Export actual dedicated-server registration; no command is executed. */
public class ExportCommands {
 static List<CommandNode<CommandSourceStack>> children(CommandNode<CommandSourceStack> node){
  var children=new ArrayList<>(node.getChildren());
  // Literal keys are unambiguous, but gamerule registration iterates an
  // unordered set. Canonicalize only literal-only sibling sets. Preserve all
  // argument sibling priority and mixed-node ordering exactly as registered.
  if(children.stream().allMatch(child->child instanceof LiteralCommandNode))children.sort(Comparator.comparing(CommandNode::getName));
  return children;
 }
 @SuppressWarnings({"rawtypes","unchecked"})
 public static void main(String[] args)throws Exception {
  if(args.length<1||args.length>2||(args.length==2&&!args[1].equals("--all-features")))throw new IllegalArgumentException("Expected output path and optional --all-features");
  SharedConstants.tryDetectVersion();Bootstrap.bootStrap();var provider=VanillaRegistries.createWorldLookup();
  if(!SharedConstants.getCurrentVersion().id().equals("26.3")||SharedConstants.getCurrentVersion().protocolVersion()!=777)throw new IllegalStateException("Wrong reference server version");
  BuiltInRegistries.DATA_COMPONENT_INITIALIZERS.build(provider).forEach(net.minecraft.core.component.DataComponentInitializers.PendingComponents::apply);
  var features=args.length>1&&args[1].equals("--all-features")?FeatureFlags.REGISTRY.allFlags():FeatureFlags.DEFAULT_FLAGS;
  var commands=new Commands(Commands.CommandSelection.DEDICATED,CommandBuildContext.simple(provider,features));
  var root=commands.getDispatcher().getRoot();var nodes=new ArrayList<CommandNode<CommandSourceStack>>();var ids=new IdentityHashMap<CommandNode<CommandSourceStack>,Integer>();
  nodes.add(root);ids.put(root,0);
  for(int i=0;i<nodes.size();++i){
   var node=nodes.get(i);var connected=children(node);if(node.getRedirect()!=null)connected.add(node.getRedirect());
   for(var child:connected)if(!ids.containsKey(child)){ids.put(child,nodes.size());nodes.add(child);}
  }
  var output=new JsonObject();output.addProperty("scope","Official Java 26.3 dedicated dispatcher; no runtime command execution");
  output.addProperty("version",SharedConstants.getCurrentVersion().id());output.addProperty("protocol",SharedConstants.getCurrentVersion().protocolVersion());output.addProperty("null_permission_result","The predicate requires a runtime source context; not accepted as verified permission behavior");
  output.addProperty("literal_sibling_order","Canonical name order only for literal-only sibling sets; argument priority and mixed sibling order preserved");
  var featureNames=new JsonArray();for(var feature:FeatureFlags.REGISTRY.toNames(features).stream().sorted(Comparator.comparing(Object::toString)).toList())featureNames.add(feature.toString());output.add("features",featureNames);var rows=new JsonArray();
  for(var node:nodes){
   var row=new JsonObject();row.addProperty("name",node.getName());row.addProperty("kind",node instanceof RootCommandNode?"root":node instanceof LiteralCommandNode?"literal":"argument");row.addProperty("executable",node.getCommand()!=null);row.addProperty("fork",node.isFork());
   var childIds=new JsonArray();for(var child:children(node))childIds.add(ids.get(child));row.add("children",childIds);if(node.getRedirect()!=null)row.addProperty("redirect",ids.get(node.getRedirect()));
   var permissions=new JsonObject();
   for(var level:PermissionLevel.values()){
    try{permissions.addProperty(Integer.toString(level.id()),node.canUse(Commands.createCompilationContext(LevelBasedPermissionSet.forLevel(level))));}
    catch(NullPointerException ex){permissions.add(Integer.toString(level.id()),JsonNull.INSTANCE);}
   }row.add("permissions",permissions);
   if(node instanceof ArgumentCommandNode argument){
    ArgumentTypeInfo info=ArgumentTypeInfos.byClass(argument.getType());var template=info.unpack(argument.getType());var properties=new JsonObject();info.serializeToJson(template,properties);
    row.addProperty("argument",BuiltInRegistries.COMMAND_ARGUMENT_TYPE.getKey(info).toString());row.addProperty("argument_class",argument.getType().getClass().getName());row.add("properties",properties);
    var buffer=new net.minecraft.network.FriendlyByteBuf(io.netty.buffer.Unpooled.buffer());info.serializeToNetwork(template,buffer);var bytes=new byte[buffer.readableBytes()];buffer.readBytes(bytes);buffer.release();row.addProperty("argument_hex",HexFormat.of().formatHex(bytes));
    row.addProperty("suggestions",argument.getCustomSuggestions()!=null);
   }rows.add(row);
  }
  output.add("nodes",rows);Files.writeString(Path.of(args[0]),new GsonBuilder().setPrettyPrinting().create().toJson(output)+"\n");
  System.out.println("Exported "+root.getChildren().size()+" command roots and "+nodes.size()+" graph nodes");
 }
}
