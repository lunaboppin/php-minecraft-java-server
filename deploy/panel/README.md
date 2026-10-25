# Pelican / Pterodactyl egg

Download [egg-php-minecraft-java-server.json](https://raw.githubusercontent.com/lunaboppin/php-minecraft-java-server/main/deploy/panel/egg-php-minecraft-java-server.json) and import it into either panel. This is a **Minecraft Java 26.3** egg for this PHP implementation; it does not install Mojang's Java server or PocketMine.

## Create a server

1. In Pelican, open **Admin → Eggs → Import**. In Pterodactyl 1.x, open **Admin → Nests → Import Egg** and select a nest. Pterodactyl 2.x uses **Admin → Eggs → Import Egg**.
2. Import the JSON and select **PHP Minecraft Java Server — 26.3** when creating a server.
3. Keep the default image, `ghcr.io/pelican-eggs/yolks:debian`. Allocate **at least 1 GiB RAM and 2 GiB disk**; allow several minutes and additional temporary node disk for the initial PHP compilation. The installer uses two compiler jobs by default; set **PHP build jobs** to 1 on a small node.
4. Give it a primary **TCP** allocation, such as **25676**. The server binds to `0.0.0.0` and uses that allocation automatically on every start. Do not add a separate `SERVER_PORT` egg variable: Wings supplies it.
5. Set the initial world seed, operators, game mode and other settings, then install and start. Connect with Minecraft Java **26.3** to the allocation's public address and port.

Startup is `exec bash deploy/panel/start.sh`. Readiness is detected from `Listening on `. The panel's Stop action sends **SIGTERM**; the runtime includes `pcntl`, allowing player/world data to be saved during shutdown. Use Stop before backing up or reinstalling. This server currently does not accept Minecraft administration commands from the panel console; use in-game operator commands or edit `server.json` while stopped.

## Downloads and PHP

The egg embeds its complete installer; it works on an empty server directory. Application files come directly from the public repository through GitHub's CDN:

```text
https://codeload.github.com/lunaboppin/php-minecraft-java-server/tar.gz/<SERVER_REF>
```

`SERVER_REF` defaults to `main` and can be an existing branch, tag or commit SHA. Use a full commit SHA for a reproducible application version. No GitHub token, Composer, Java installation, release asset or external server-file host is required.

The installer resolves the latest stable **PHP 8.5 patch** from [PHP's official release feed](https://www.php.net/releases/index.php?json&version=8.5), downloads its official source archive, verifies the feed's SHA-256, and builds a minimal CLI runtime with SQLite, PDO, JSON, iconv, zlib, filter and POSIX signal handling. It refuses PHP below **8.5.12**. PHP lives in `.runtime/php/bin/php`, separate from the node's PHP. The native build targets the node's 64-bit architecture. The runtime yolk and installer are both based on Debian 13; choose the supplied image so its runtime libraries match.

Subsequent reinstalls reuse PHP only when its version matches the official current patch and its extensions/SQLite check passes. When PHP publishes another 8.5 security patch, **Stop → Reinstall → Start** rebuilds PHP and updates the application. Merely restarting does not download or rebuild PHP. Keep the runtime yolk updated through Wings as well. PHP's license and the selected release/checksum are retained under `.runtime/php/`; `.runtime/server-install.json` records the requested application revision and downloaded archive checksum.

## Settings, worlds and updates

Panel variables initialize `server.json` **only when it does not already exist**. After the first install, edit `server.json` to change the MOTD, operators, player limit, view distance, game mode or difficulty. Changes to the panel's initial-setting variables then have no effect. Binding and allocation port are always supplied at startup, overriding any saved host/port.

Reinstall replaces application files and preserves `server.json`, `data/`, and the worlds inside it, including Nether/End databases and player inventories. Back up `server.json` and the **entire `data/` directory** before updating. Keep custom world paths inside `data/`; application directories such as `src/` and `reference/` are updated on reinstall. To change a world seed, choose a new `worldPath` and corresponding `seed` in `server.json`; the server rejects changing the seed of an existing world.

New installations enable online-mode verification. `ONLINE_MODE` initializes that setting only on first install; existing configurations are preserved. Offline usernames remain unverified, and online/offline player inventories use separate save records. See the [authentication coverage](../../docs/authentication.md) before switching an existing server.

## Maintainer checks

```sh
python3 tools/build_panel_egg.py          # Regenerate JSON after editing the installer
python3 tests/panel_egg.py                # Egg/configuration checks
PANEL_TEST_REF=<published-commit-sha> python3 tests/panel_egg.py --container
```

The container test installs the embedded egg script against GitHub's actual CDN, runs the exact runtime yolk as a non-root user, joins over TCP, checks SIGTERM shutdown, reinstalls with changed initial settings, and verifies that configuration and all dimension databases survive. Docker and outbound HTTPS are required; allow several minutes for the first build. The test is run on Linux amd64; native arm64 builds are supported by the build path but are not exercised by that test.

Format references: [Pelican egg guide](https://pelican.dev/docs/eggs/creating-a-custom-egg/) and [Pterodactyl installation scripts](https://docs.pterodactyl.io/v1/guides/egg-creation/egg-install-script).
