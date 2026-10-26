# Command modules

Each of the 22 existing command handlers lives in its own file under `src/Server/Commands/`. For example, `GiveCommand.php` implements `executeGive`, and `TeleportCommand.php` implements the shared handler for `/tp` and `/teleport`.

`CommandRegistry` lists the supported names, aliases, handler methods and operator requirements. It supplies the same list for command advertisement, help and dispatch. A player-supplied name can select only a registered handler. `CommandArguments` handles tokenization; shared coordinate, player lookup and block-state helpers remain in the `Server/Commands.php` bridge.

Handlers are traits, following the server's existing trait-based organization. They are composed into the server so their private methods use the existing world/player services directly. No handler needs to expose those services publicly or bind closures into private state. `CommandDefinition` is an immutable registration record.

When adding a command:

1. Create its named handler file in `src/Server/Commands/` with an explicitly typed private execution method. Keep behavior specific to that command in that file.
2. Compose the handler into the bridge and add its name, aliases and permission requirement to `CommandRegistry`.
3. Put reusable parsing/services outside the individual handler. Keep arguments and local variables clearly named and format the whole file.
4. Add relevant behavior, permission, error and Java comparison coverage. An advertised command must execute its real behavior; accepting syntax alone is not implementation.

Run `python3 tools/php_style.py` to format command modules and `python3 tools/php_style.py --check` to check them. Optional paths apply the same configuration to other changed PHP files. The helper verifies the pinned official PHP CS Fixer PHAR checksum and does not add a runtime dependency. CI checks command formatting.

This refactor preserves the existing commands and permissions. It does not complete the native command set: the parser is still the existing whitespace-based parser, and native contexts, selector syntax, permission levels, full argument types, redirects and result semantics remain in #10. Console/RCON command sources remain in #14. The full command inventory must come from the pinned Java 26.3 dispatcher.
