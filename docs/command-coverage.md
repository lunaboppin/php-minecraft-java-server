# Native command inventory

Target: the checksum-pinned official Java 26.3 dedicated server. This is an implementation backlog, not a claim of command parity.

The exported dispatcher has **92 roots/aliases** across the default and all-registered-feature profiles. **44** have an existing PHP handler; **48** have none. **Every native acceptance row remains unverified or incomplete.** Registration alone never marks a command complete.

The references preserve every graph node, redirect/fork, executable flag, argument type, JSON/network argument properties, suggestion-provider presence and permission-predicate results for the five official permission levels. These are structural and permission-query observations; no command is executed by the exporter.

Literal-only sibling sets use canonical name order because gamerule registration iterates an unordered set. Argument sibling priority and mixed-node ordering are retained. Unknown permission queries are recorded as null, never as successful checks.

| Reference profile | Flags | Graph nodes |
| --- | --- | ---: |
| [commands.json](../reference/26.3/commands.json) | minecraft:vanilla | 2467 |
| [commands-all-features.json](../reference/26.3/commands-all-features.json) | minecraft:minecart_improvements, minecraft:redstone_experiments, minecraft:trade_rebalance, minecraft:vanilla | 2471 |

| Command | PHP module | Native acceptance | Redirect |
| --- | --- | --- | --- |
| `/advancement` | — | Unimplemented | — |
| `/attribute` | — | Unimplemented | — |
| `/ban` | [BanCommand.php](../src/Server/Commands/BanCommand.php) | Partial handler; native behavior incomplete | — |
| `/ban-ip` | — | Unimplemented | — |
| `/banlist` | [BanListCommand.php](../src/Server/Commands/BanListCommand.php) | Partial handler; native behavior incomplete | — |
| `/bossbar` | — | Unimplemented | — |
| `/clear` | [ClearCommand.php](../src/Server/Commands/ClearCommand.php) | Partial handler; native behavior incomplete | — |
| `/clone` | — | Unimplemented | — |
| `/compute` | — | Unimplemented | — |
| `/damage` | [DamageCommand.php](../src/Server/Commands/DamageCommand.php) | Partial handler; native behavior incomplete | — |
| `/data` | — | Unimplemented | — |
| `/datapack` | — | Unimplemented | — |
| `/debug` | — | Unimplemented | — |
| `/defaultgamemode` | [DefaultGameModeCommand.php](../src/Server/Commands/DefaultGameModeCommand.php) | Partial handler; native behavior incomplete | — |
| `/deop` | [DeOpCommand.php](../src/Server/Commands/DeOpCommand.php) | Partial handler; native behavior incomplete | — |
| `/dialog` | — | Unimplemented | — |
| `/difficulty` | [DifficultyCommand.php](../src/Server/Commands/DifficultyCommand.php) | Partial handler; native behavior incomplete | — |
| `/effect` | — | Unimplemented | — |
| `/enchant` | [EnchantCommand.php](../src/Server/Commands/EnchantCommand.php) | Partial handler; native behavior incomplete | — |
| `/execute` | — | Unimplemented | — |
| `/experience` | [ExperienceCommand.php](../src/Server/Commands/ExperienceCommand.php) | Partial handler; native behavior incomplete | — |
| `/fetchprofile` | — | Unimplemented | — |
| `/fill` | [FillCommand.php](../src/Server/Commands/FillCommand.php) | Partial handler; native behavior incomplete | — |
| `/fillbiome` | — | Unimplemented | — |
| `/forceload` | — | Unimplemented | — |
| `/function` | — | Unimplemented | — |
| `/gamemode` | [GameModeCommand.php](../src/Server/Commands/GameModeCommand.php) | Partial handler; native behavior incomplete | — |
| `/gamerule` | [GameRuleCommand.php](../src/Server/Commands/GameRuleCommand.php) | Partial handler; native behavior incomplete | — |
| `/give` | [GiveCommand.php](../src/Server/Commands/GiveCommand.php) | Partial handler; native behavior incomplete | — |
| `/help` | [HelpCommand.php](../src/Server/Commands/HelpCommand.php) | Partial handler; native behavior incomplete | — |
| `/item` | — | Unimplemented | — |
| `/jfr` | — | Unimplemented | — |
| `/kick` | [KickCommand.php](../src/Server/Commands/KickCommand.php) | Partial handler; native behavior incomplete | — |
| `/kill` | [KillCommand.php](../src/Server/Commands/KillCommand.php) | Partial handler; native behavior incomplete | — |
| `/list` | [ListCommand.php](../src/Server/Commands/ListCommand.php) | Partial handler; native behavior incomplete | — |
| `/locate` | — | Unimplemented | — |
| `/loot` | — | Unimplemented | — |
| `/me` | [MeCommand.php](../src/Server/Commands/MeCommand.php) | Partial handler; native behavior incomplete | — |
| `/msg` | [MessageCommand.php](../src/Server/Commands/MessageCommand.php) | Partial handler; native behavior incomplete | — |
| `/op` | [OpCommand.php](../src/Server/Commands/OpCommand.php) | Partial handler; native behavior incomplete | — |
| `/pardon` | [PardonCommand.php](../src/Server/Commands/PardonCommand.php) | Partial handler; native behavior incomplete | — |
| `/pardon-ip` | — | Unimplemented | — |
| `/particle` | — | Unimplemented | — |
| `/perf` | — | Unimplemented | — |
| `/place` | — | Unimplemented | — |
| `/playsound` | — | Unimplemented | — |
| `/posteffect` | — | Unimplemented | — |
| `/random` | — | Unimplemented | — |
| `/recipe` | — | Unimplemented | — |
| `/reload` | — | Unimplemented | — |
| `/return` | — | Unimplemented | — |
| `/ride` | — | Unimplemented | — |
| `/rotate` | — | Unimplemented | — |
| `/save-all` | [SaveAllCommand.php](../src/Server/Commands/SaveAllCommand.php) | Partial handler; native behavior incomplete | — |
| `/save-off` | [SaveOffCommand.php](../src/Server/Commands/SaveOffCommand.php) | Partial handler; native behavior incomplete | — |
| `/save-on` | [SaveOnCommand.php](../src/Server/Commands/SaveOnCommand.php) | Partial handler; native behavior incomplete | — |
| `/say` | — | Unimplemented | — |
| `/schedule` | — | Unimplemented | — |
| `/scoreboard` | [ScoreboardCommand.php](../src/Server/Commands/ScoreboardCommand.php) | Partial handler; native behavior incomplete | — |
| `/seed` | [SeedCommand.php](../src/Server/Commands/SeedCommand.php) | Partial handler; native behavior incomplete | — |
| `/setblock` | [SetBlockCommand.php](../src/Server/Commands/SetBlockCommand.php) | Partial handler; native behavior incomplete | — |
| `/setidletimeout` | — | Unimplemented | — |
| `/setworldspawn` | [SetWorldSpawnCommand.php](../src/Server/Commands/SetWorldSpawnCommand.php) | Partial handler; native behavior incomplete | — |
| `/spawnpoint` | [SpawnPointCommand.php](../src/Server/Commands/SpawnPointCommand.php) | Partial handler; native behavior incomplete | — |
| `/spectate` | — | Unimplemented | — |
| `/spreadplayers` | — | Unimplemented | — |
| `/stop` | [StopCommand.php](../src/Server/Commands/StopCommand.php) | Partial handler; native behavior incomplete | — |
| `/stopsound` | — | Unimplemented | — |
| `/stopwatch` | — | Unimplemented | — |
| `/summon` | [SummonCommand.php](../src/Server/Commands/SummonCommand.php) | Partial handler; native behavior incomplete | — |
| `/swing` | — | Unimplemented | — |
| `/tag` | — | Unimplemented | — |
| `/team` | [TeamCommand.php](../src/Server/Commands/TeamCommand.php) | Partial handler; native behavior incomplete | — |
| `/teammsg` | — | Unimplemented | — |
| `/teleport` | [TeleportCommand.php](../src/Server/Commands/TeleportCommand.php) | Partial handler; native behavior incomplete | — |
| `/tell` | [MessageCommand.php](../src/Server/Commands/MessageCommand.php) | Partial handler; native behavior incomplete | `/msg` |
| `/tellraw` | [TellrawCommand.php](../src/Server/Commands/TellrawCommand.php) | Partial handler; native behavior incomplete | — |
| `/test` | — | Unimplemented | — |
| `/tick` | — | Unimplemented | — |
| `/time` | [TimeCommand.php](../src/Server/Commands/TimeCommand.php) | Partial handler; native behavior incomplete | — |
| `/title` | [TitleCommand.php](../src/Server/Commands/TitleCommand.php) | Partial handler; native behavior incomplete | — |
| `/tm` | — | Unimplemented | `/teammsg` |
| `/tp` | [TeleportCommand.php](../src/Server/Commands/TeleportCommand.php) | Partial handler; native behavior incomplete | `/teleport` |
| `/transfer` | — | Unimplemented | — |
| `/trigger` | [TriggerCommand.php](../src/Server/Commands/TriggerCommand.php) | Partial handler; native behavior incomplete | — |
| `/version` | [VersionCommand.php](../src/Server/Commands/VersionCommand.php) | Partial handler; native behavior incomplete | — |
| `/w` | — | Unimplemented | `/msg` |
| `/waypoint` | — | Unimplemented | — |
| `/weather` | [WeatherCommand.php](../src/Server/Commands/WeatherCommand.php) | Partial handler; native behavior incomplete | — |
| `/whitelist` | [WhitelistCommand.php](../src/Server/Commands/WhitelistCommand.php) | Partial handler; native behavior incomplete | — |
| `/worldborder` | [WorldBorderCommand.php](../src/Server/Commands/WorldBorderCommand.php) | Partial handler; native behavior incomplete | — |
| `/xp` | [ExperienceCommand.php](../src/Server/Commands/ExperienceCommand.php) | Partial handler; native behavior incomplete | `/experience` |

Existing additional project commands: `/dimension`, `/heal`, `/spawn`.

Completion under #10 requires the complete branch/argument inventory, native results and side effects, permissions, selectors, suggestions/errors, execution contexts and lifecycle evidence. The matrix must gain explicit behavioral evidence before any row can be accepted. Default/all-feature snapshots do not establish every runtime server configuration or execution context.

Regenerate with `python3 tools/command_coverage.py`; verify the committed inventory with `--check`. Rebuild graph data using `tools/ExportCommands.java` through the reference exporter. No game feature flags are enabled on the hosted server by this inventory work.
