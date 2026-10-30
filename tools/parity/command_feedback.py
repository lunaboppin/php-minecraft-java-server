#!/usr/bin/env python3
"""Record exact command feedback from PHP or the official server, and compare two recordings.

An operator (ParityProbe) runs each command while a non-operator witness is connected. Every
system_chat and disconnect payload either client receives after the command is recorded as hex,
so a PHP recording can be compared byte for byte with an official one.
"""
import argparse
import json
import os
from pathlib import Path
import re
import struct
import sys

sys.path.insert(0, str(Path(__file__).resolve().parent))
from protocol import Probe, ROOT, wire
from run import Server

# Commands whose feedback does not depend on timing or randomness. Each entry is
# (command, reconnect the witness first).
SCRIPT = [
    # 26.3 has no spawn-monsters property; without these, night-time monsters kill the witness.
    ('gamerule spawn_monsters false', False), ('gamerule spawn_mobs false', False),
    ('list', False), ('seed', False),
    ('difficulty', False), ('difficulty hard', False), ('difficulty hard', False),
    ('defaultgamemode survival', False), ('defaultgamemode survival', False),
    ('save-all', False), ('save-all flush', False),
    ('save-off', False), ('save-off', False), ('save-on', False), ('save-on', False),
    ('op Witness', False), ('op Witness', False), ('deop Witness', False), ('deop Witness', False),
    # An unknown profile name makes vanilla query Mojang's profile service, so none is used here.
    ('kick NobodyHere', False),
    ('kick Witness Bye  now', False), ('kick Witness', True),
    ('weather clear', True), ('weather rain', False), ('weather thunder', False),
    ('gamemode creative', False), ('gamemode survival', False), ('gamemode survival Witness', False), ('gamemode creative @a', False),
    ('setworldspawn 1 64 2', False), ('setworldspawn 1 64 2 90 10', False),
    ('spawnpoint Witness 3 64 4', False), ('spawnpoint @a 3 64 4 45 0', False),
    # Above the terrain, so both seeds give the same replies.
    ('setblock 5 200 5 minecraft:stone', False), ('setblock 5 200 5 minecraft:stone', False), ('setblock 5 200 5 minecraft:air', False),
    ('fill 6 200 6 7 200 7 minecraft:dirt', False), ('fill 6 200 6 7 200 7 minecraft:dirt', False), ('fill 6 200 6 7 201 7 minecraft:air', False),
    ('give Witness minecraft:dirt 3', False), ('give @a minecraft:dirt 2', False), ('give Witness minecraft:diamond_sword 2', False),
    ('summon minecraft:pig 0 70 0', False), ('summon minecraft:cow 1.5 70 1.5', False),
    ('tp Witness 0 70 0', False), ('tp 1 70 1', False), ('tp Witness ParityProbe', False), ('teleport @a 2 70 2', False), ('tp @s ~ ~1 ~', False),
    ('kill Witness', False), ('kill Witness', False), ('!respawn', False),
    # Entity selectors (random mob UUIDs are normalized for @e commands).
    ('summon minecraft:pig 0 64 3', False), ('summon minecraft:cow 3 64 0', False), ('tp @e[type=cow] 0 64 0', False),
    ('kill @e[type=pig,limit=1,sort=nearest]', False), ('kill @e[type=minecraft:cow]', False), ('kill @e[type=pig]', False),
    ('give @a[gamemode=survival] dirt 1', False), ('give @a[name=!Witness] dirt 2', False), ('give @a[limit=1,sort=furthest] dirt 3', False),
    ('version', False), ('gamerule keep_inventory', False), ('gamerule minecraft:keep_inventory true', False), ('gamerule keep_inventory true', False),
    ('gamerule random_tick_speed 5', False), ('gamerule random_tick_speed', False),
    # Death messages: the witness respawns between deaths.
    ('gamemode survival Witness', False), ('damage Witness 2.5', False),
    ('damage Witness 100 drown', False), ('!respawn', False), ('damage Witness 100 fall', False), ('!respawn', False),
    ('damage Witness 100 player_attack by ParityProbe', False), ('!respawn', False),
    ('damage Witness 1 player_attack by ParityProbe', False), ('damage Witness 100 drown', False), ('!respawn', False),
    ('damage Witness 100 out_of_world', False), ('!respawn', False), ('damage ParityProbe 5', False),
    # /time cases whose replies do not depend on how many ticks pass between commands.
    ('time set 1000', False), ('time set minecraft:night', False), ('time set noon', False),
    ('time pause', False), ('time pause', False), ('time resume', False), ('time resume', False),
    ('time rate 2', False), ('time rate 2', False), ('time rate 1', False),
    # Parse errors: the message, then the input up to the cursor; "!witness" runs a command as the non-operator.
    ('foo bar', False), ('time', False), ('time foo', False), ('time add 1.5.5', False),
    ('time add 1q', False), ('gamemode bogus', False), ('gamemode creative @x', False), ('gamemode creative @e[foo=1]', False),
    ('gamemode creative @a[', False), ('difficulty peaceful extra', False), ('give Witness minecraft:dirt abc', False), ('give Witness minecraft:dirt 0', False),
    ('give Witness minecraft:dirt 5x', False), ('give Witness notanitem', False), ('gamerule keep_inventory maybe', False), ('gamerule random_tick_speed 1.5', False),
    ('gamerule nonexistent_rule', False), ('setblock 1 2', False), ('setblock ~ ~ ^1 minecraft:stone', False), ('setblock a b c minecraft:stone', False),
    ('setblock 1.5 2 3 stone', False), ('tp ~ ~ ~ ~ x', False), ('summon minecraft:nope', False), ('summon minecraft:pig 1 2', False),
    ('damage Witness -1', False), ('damage Witness 5 nope', False), ('weather rain -5', False), ('weather rain 5x', False),
    ('kill @e[type=nope]', False), ('kill @e[limit=0]', False), ('kill @a[distance=-1]', False), ('kill @s[limit=1]', False),
    ('kill @e[type=pig]x', False), ('kill averyveryverylongplayername', False), ('list uuids extra', False), ('time set abc', False),
    ('time set -5', False), ('setblock 1 2 3 minecraft:stone[facing=north]', False), ('setblock 1 2 3 minecraft:oak_stairs[facing=up]', False), ('setblock 1 2 3 minecraft:oak_stairs[facing]', False),
    ('setblock 1 2 3 minecraft:oak_stairs[facing=north,facing=south]', False), ('give @s diamond_sword[nope=5] 1', False), ('give @s diamond_sword{foo:1} 1', False), ('give @s diamond_sword[damage=5,damage=6] 1', False),
    ('give @s diamond_sword[damage=5 1', False), ('gamerule keep_inventory "true"', False), ('!witness gamemode creative', False), ('!witness foo', False),
    ('!witness msg @a hi', False),
    # Block modes and teleport forms above the terrain; local coordinates start from a zero rotation.
    # Destroyed blocks are glass, which drops nothing for players to pick up.
    # Creative players survive falling from y=200 between commands.
    ('gamemode creative @a', False),
    ('setblock 1 200 1 stone keep', False), ('setblock 1 200 1 dirt keep', False), ('setblock 1 200 1 glass replace', False),
    ('setblock 1 200 1 dirt destroy', False), ('setblock 1 200 1 glass replace', False), ('setblock 1 200 1 air destroy', False), ('setblock 1 200 1 air destroy', False),
    ('setblock 1 200 1 stone strict', False), ('setblock 1 200 1 stone strict', False), ('setblock 1 400 1 stone', False),
    ('setblock 1 -100 1 stone', False), ('fill 0 200 0 2 202 2 stone outline', False), ('fill 0 200 0 2 202 2 dirt hollow', False),
    ('fill 0 200 0 2 202 2 dirt hollow', False), ('fill 0 200 0 2 202 2 glass replace dirt', False), ('fill 0 200 0 2 202 2 glass replace dirt', False),
    ('fill 0 200 0 2 202 2 oak_log[axis=x]', False), ('fill 0 200 0 2 202 2 glass replace #minecraft:logs', False), ('fill 0 200 0 2 202 2 dirt replace glass destroy', False),
    ('fill 0 200 0 2 202 2 air keep', False), ('fill 0 200 0 2 202 2 stone keep', False), ('fill 0 200 0 2 202 2 glass', False), ('fill 0 200 0 2 202 2 air destroy', False),
    ('fill 0 200 0 2 202 2 air strict', False), ('fill 0 200 0 2 400 2 stone', False), ('fill 0 200 0 2 202 2 oak_stairs[facing=east] replace #minecraft:nope', False),
    ('tp @s 0 200 0 90 45', False), ('tp @s ~ ~ ~ ~10 ~-5', False), ('tp @s 0 200 0 facing 5 200 5', False),
    ('tp @s 0 200 0 facing entity Witness', False), ('tp @s 0 200 0 facing entity Witness eyes', False), ('tp Witness 3 200 3 0 0', False),
    ('setblock 30000000 200 1 stone', False), ('setblock 5000 200 5000 stone', False), ('tp @s 0 200 0 0 0', False),
    ('tp @s ^ ^ ^2', False), ('tp @s 0 200 0 0 0', False), ('tp @s ^1 ^ ^', False),
    ('tp @s 0 200 0 0 0', False), ('tp @s ^ ^1 ^1', False), ('tp @s 0 200 0 0 0', False),
    ('tp @s ^0.3 ^-0.7 ^1.25', False), ('tp @s 30000000 200 0', False), ('tp @s 29999999 200 0', False),
    ('tp @s 0 20000000 0', False),
    # Experience points, levels and progress, and clearing inventories, from empty inventories.
    ('!quiet clear @a', False), ('!quiet xp set @a 0 levels', False), ('!quiet xp set @a 0 points', False),
    ('xp query Witness points', False), ('xp query Witness levels', False), ('xp add Witness 10', False),
    ('xp query Witness points', False), ('xp query Witness levels', False), ('xp add Witness 5 levels', False),
    ('xp query Witness levels', False), ('xp query Witness points', False), ('xp set Witness 3 points', False),
    ('xp set Witness 100 points', False), ('xp set Witness 0 levels', False), ('xp query Witness points', False),
    ('xp add @a 7', False), ('xp add @a 2 levels', False), ('xp set @a 1 levels', False),
    ('xp add Witness -1000', False), ('xp query Witness levels', False), ('xp add Witness -5 levels', False),
    ('experience add Witness 30 levels', False), ('xp query Witness levels', False), ('xp set Witness 7 points', False),
    ('xp set Witness 99999 points', False), ('xp set @a 3 points', False), ('xp add @e 1', False),
    ('clear Witness', False), ('give Witness dirt 5', False), ('give Witness stone 3', False),
    ('clear Witness dirt 2', False), ('clear Witness dirt 0', False), ('clear Witness', False),
    ('clear Witness', False), ('give @a dirt 4', False), ('clear @a dirt', False),
    ('clear @a', False), ('give Witness dirt 64', False), ('clear Witness dirt 100', False),
    ('clear Witness #minecraft:logs', False), ('clear Witness stone[foo=bar]', False), ('clear Witness nope', False),
    # The world border, restored to its default size and centre afterwards.
    ('worldborder get', False), ('worldborder set 100', False), ('worldborder set 100', False),
    ('worldborder get', False), ('worldborder set 0.5', False), ('worldborder set 59999969', False),
    ('worldborder set 50 10', False), ('worldborder set 200 2s', False), ('worldborder add 10', False),
    ('worldborder add -1000', False), ('worldborder set 1000.25', False), ('worldborder get', False),
    ('worldborder center 0 0', False), ('worldborder center 10 20', False), ('worldborder center 10 20', False),
    ('worldborder center 10.7 -20.2', False), ('worldborder center 30000000 0', False), ('worldborder damage amount 0.2', False),
    ('worldborder damage amount 1.5', False), ('worldborder damage amount 1.5', False), ('worldborder damage buffer 5', False),
    ('worldborder damage buffer 2.5', False), ('worldborder warning distance 5', False), ('worldborder warning distance 10', False),
    ('worldborder warning time 15', False), ('worldborder warning time 300', False), ('worldborder warning time 20s', False),
    ('worldborder set 59999968', False), ('worldborder center 0 0', False),
    # Enchanting main-hand items, from empty inventories.
    ('!quiet clear @a', False), ('enchant Witness sharpness', False), ('give Witness diamond_sword', False),
    ('enchant Witness sharpness', False), ('enchant Witness sharpness 3', False), ('enchant Witness smite', False),
    ('enchant Witness sharpness 6', False), ('enchant Witness minecraft:unbreaking 3', False), ('enchant Witness mending', False),
    ('enchant Witness binding_curse', False), ('give Witness stone', False), ('enchant Witness unbreaking', False),
    ('enchant Witness nope', False), ('enchant @a unbreaking', False), ('give @a book', False),
    ('enchant @a unbreaking', False), ('summon pig 0 64 0', False), ('enchant @e[type=pig] sharpness', False),
    ('enchant Witness binding_curse', False), ('enchant Witness protection 5', False),
    # The whitelist and bans; "!reconnect" brings the witness back after a pardon.
    ('banlist', False), ('banlist players', False), ('banlist ips', False),
    ('whitelist list', False), ('whitelist add Witness', False), ('whitelist add Witness', False),
    ('whitelist list', False), ('whitelist add ParityProbe', False), ('whitelist list', False),
    ('whitelist on', False), ('whitelist on', False), ('whitelist remove ParityProbe', False),
    ('whitelist remove ParityProbe', False), ('whitelist off', False), ('whitelist off', False),
    ('whitelist reload', False), ('whitelist remove Witness', False), ('ban Witness', False),
    ('banlist', False), ('banlist players', False), ('ban Witness', False),
    ('pardon Witness', False), ('pardon Witness', False), ('!reconnect', False),
    ('ban Witness Griefing  the spawn', False), ('banlist', False), ('pardon Witness', False),
    ('!reconnect', False), ('ban @a[name=Witness]', False), ('pardon Witness', False),
    ('!reconnect', False), ('banlist', False),
    # Text component arguments: tellraw and titles.
    ('tellraw @s "hello"', False), ('tellraw @s {text:"hi",color:"red"}', False), ('tellraw @s {"text":"json style","bold":true}', False),
    ('tellraw @s ["a",{text:"b",italic:1b}]', False), ('tellraw @s {translate:"chat.type.text",with:["x","y"]}', False), ('tellraw @s {selector:"@a"}', False),
    ('tellraw @s {text:"x",extra:[{selector:"@s"}]}', False), ('tellraw @s 123', False), ('tellraw @s {text:"unterminated"', False),
    ('tellraw @s {foo:"bar"}', False), ('tellraw @s {text:1}', False), ('tellraw @a {text:"to all"}', False),
    ('tellraw Witness [{text:"click",click_event:{action:"run_command",command:"/help"}}]', False), ('tellraw @s {text:"hover",hover_event:{action:"show_text",value:"tip"}}', False), ("tellraw @s ''", False),
    ('tellraw @s {score:{name:"@s",objective:"x"}}', False), ('tellraw @s {keybind:"key.jump"}', False), ('tellraw @s', False),
    ('title @s title "Big"', False), ('title @s subtitle {text:"Small",color:"gold"}', False), ('title @s actionbar "bar"', False),
    ('title @s times 10 70 20', False), ('title @s clear', False), ('title @s reset', False),
    ('title @a title "All"', False),
    # Item components in /give and /clear, from empty inventories.
    ('!quiet clear @a', False), ('give Witness diamond_sword[damage=5]', False), ('give Witness diamond_sword[enchantments={sharpness:3}]', False),
    ('give Witness diamond_sword[enchantments={"minecraft:sharpness":3,unbreaking:1}]', False), ('give Witness diamond_sword[damage=5,enchantments={sharpness:3}] 2', False), ('give Witness enchanted_book[stored_enchantments={mending:1}]', False),
    ('give Witness stick[enchantments={sharpness:1}]', False), ('give Witness golden_apple[enchantments={sharpness:1}]', False), ('give Witness diamond_sword[!damage]', False),
    ('give Witness diamond_sword[damage="x"]', False), ('give Witness diamond_sword[damage=-1]', False), ('give Witness diamond_sword[max_stack_size=2]', False),
    ('give Witness diamond_sword[enchantments={nope:1}]', False), ('give Witness dirt[damage=1]', False), ('clear Witness diamond_sword[damage=5]', False),
    ('clear Witness diamond_sword', False),
    # Scoreboard objectives, scores, display slots and score components; "kill" respawns both players.
    ('scoreboard objectives list', False), ('scoreboard objectives add kills dummy', False), ('scoreboard objectives add kills dummy', False),
    ('scoreboard objectives add deaths deathCount {text:"Deaths",color:"red"}', False), ('scoreboard objectives add bad nope', False), ('scoreboard objectives add Ab_c-.+ dummy', False),
    ('scoreboard objectives add averyveryveryveryveryveryverylongname dummy', False), ('scoreboard objectives list', False), ('scoreboard objectives modify kills displayname "Kills!"', False),
    ('scoreboard objectives modify kills rendertype hearts', False), ('scoreboard objectives setdisplay sidebar kills', False), ('scoreboard objectives setdisplay sidebar kills', False),
    ('scoreboard objectives setdisplay sidebar', False), ('scoreboard objectives setdisplay list deaths', False), ('scoreboard objectives setdisplay sidebar.team.red kills', False),
    ('scoreboard objectives setdisplay belowName nope', False), ('scoreboard players set Witness kills 5', False), ('scoreboard players set @a kills 3', False),
    ('scoreboard players add Witness kills 2', False), ('scoreboard players remove Witness kills 1', False), ('scoreboard players get Witness kills', False),
    ('scoreboard players get Witness deaths', False), ('scoreboard players get Nobody kills', False), ('scoreboard players set fakeplayer kills 10', False),
    ('scoreboard players set * kills 1', False), ('scoreboard players list', False), ('scoreboard players list Witness', False),
    ('scoreboard players list Nobody', False), ('scoreboard players operation Witness kills += fakeplayer kills', False), ('scoreboard players operation Witness kills *= fakeplayer kills', False),
    ('scoreboard players operation Witness kills %= fakeplayer kills', False), ('scoreboard players operation Witness kills >< fakeplayer kills', False), ('scoreboard players operation Witness kills /= Nobody kills', False),
    ('scoreboard players get Witness kills', False), ('scoreboard players reset fakeplayer kills', False), ('scoreboard players reset fakeplayer', False),
    ('scoreboard players enable Witness kills', False), ('scoreboard objectives add t trigger', False), ('scoreboard players enable Witness t', False),
    ('scoreboard players enable @a t', False), ('tellraw @s {score:{name:"Witness",objective:"kills"}}', False), ('tellraw @s {score:{name:"@s",objective:"kills"}}', False),
    ('kill @a[scores={kills=1..}]', False), ('scoreboard objectives remove kills', False), ('scoreboard objectives remove kills', False),
    ('scoreboard objectives list', False),
    # playerScores grows past 16 holders, keeps holders whose objective is removed, and lists them
    # in its spliterator order; resetting an objective drops every holder it leaves empty.
    ('scoreboard objectives add g dummy', False), ('scoreboard players set f01 g 1', False), ('scoreboard players set f02 g 1', False), ('scoreboard players set f03 g 1', False), ('scoreboard players set f04 g 1', False), ('scoreboard players set f05 g 1', False), ('scoreboard players set f06 g 1', False), ('scoreboard players set f07 g 1', False), ('scoreboard players set f08 g 1', False), ('scoreboard players set f09 g 1', False), ('scoreboard players set f10 g 1', False), ('scoreboard players set f11 g 1', False), ('scoreboard players set f12 g 1', False), ('scoreboard players set f13 g 1', False), ('scoreboard players set f14 g 1', False), ('scoreboard players set f15 g 1', False), ('scoreboard players set f16 g 1', False), ('scoreboard players set f17 g 1', False),
    ('scoreboard players list', False), ('scoreboard objectives remove g', False), ('scoreboard players list', False),
    ('scoreboard objectives add h dummy', False), ('scoreboard players set f05 h 5', False), ('scoreboard objectives setdisplay sidebar h', False),
    ('scoreboard players reset f05', False), ('scoreboard players reset * h', False), ('scoreboard players list', False),
    ('scoreboard objectives remove h', False),
    ('!respawn', False),
    # Summoned mobs with NBT, named and tagged selection; their random loot is cleared quietly.
    # The operator stands inside a closed stone box first, so the crowded mobs can neither fall nor wander off.
    ('!quiet tp @s 3 101 4', False), ('!quiet fill 0 100 1 6 104 7 minecraft:stone hollow', False),
    ('summon pig ~ ~ ~ {NoAI:1b}', False), ('summon pig ~ ~ ~ {CustomName:"Bob"}', False), ('summon pig ~ ~ ~ {CustomName:{text:"Red",color:"red"}}', False),
    ('summon cow ~ ~ ~ {Age:-24000}', False), ('summon cow ~ ~ ~ {Silent:1b,Invulnerable:1b,PersistenceRequired:1b}', False), ('summon pig ~ ~ ~ {Tags:["a","b"]}', False),
    ('summon pig ~ ~ ~ {Health:5f}', False), ('summon pig ~ ~ ~ {Rotation:[90f,0f]}', False), ('summon pig ~ ~ ~ {NoAI:1b', False),
    ('summon pig ~ ~ ~ {}', False), ('summon minecraft:player', False), ('summon pig 0 -10000 0', False),
    ('kill @e[name=Bob]', False), ('kill @e[name=Red]', False), ('kill @e[tag=a]', False),
    ('kill @e[tag=!a,type=pig,name=Pig]', False), ('!quiet kill @e[type=!player]', False),
    # Teams: packets, team-formatted names, selectors and death message visibility.
    ('team list', False), ('team add red', False), ('team add red', False),
    ('team add blue {text:"Blue Team",color:"blue"}', False), ('team list', False), ('team list red', False),
    ('team join red', False), ('team join red Witness', False), ('team join blue Witness', False),
    ('team list red', False), ('team list blue', False), ('team modify red color red', False),
    ('team modify red color red', False), ('team modify red prefix {text:"[R] "}', False), ('team modify red suffix " !"', False),
    ('team modify red friendlyFire false', False), ('team modify red seeFriendlyInvisibles false', False), ('team modify red nametagVisibility hideForOtherTeams', False),
    ('team modify red deathMessageVisibility never', False), ('team modify red collisionRule pushOwnTeam', False), ('team modify red displayName {text:"Reds"}', False),
    ('team modify nope color red', False), ('list', False), ('tellraw @a {selector:"@a[team=red]"}', False),
    ('kill @a[team=blue]', False), ('!respawn', False), ('team join red Witness', False),
    ('kill Witness', False), ('!respawn', False), ('team modify red deathMessageVisibility hideForOwnTeam', False),
    ('kill Witness', False), ('!respawn', False), ('team modify red deathMessageVisibility hideForOtherTeams', False),
    ('kill Witness', False), ('!respawn', False), ('team leave Witness', False),
    ('team leave Witness', False), ('team empty red', False), ('team empty red', False),
    ('team remove blue', False), ('team remove red', False), ('team list', False),
    # /trigger by the non-operator, enabled by the operator.
    ('scoreboard objectives add t trigger', False), ('scoreboard objectives add d dummy', False), ('!witness trigger t', False),
    ('scoreboard players enable Witness t', False), ('!witness trigger t', False), ('!witness trigger t', False),
    ('scoreboard players enable Witness t', False), ('!witness trigger t add 5', False), ('scoreboard players enable Witness t', False),
    ('!witness trigger t set 42', False), ('scoreboard players get Witness t', False), ('!witness trigger d', False),
    ('!witness trigger nope', False), ('!witness trigger t set', False), ('scoreboard objectives setdisplay sidebar t', False),
    ('scoreboard players enable Witness t', False), ('!witness trigger t add -3', False), ('scoreboard objectives remove t', False),
    ('scoreboard objectives remove d', False),
    # Status effects and their packets; long effects are infinite so no 30-second resync lands mid-script.
    ('effect give Witness speed', False), ('effect give Witness speed infinite 1', False), ('effect give Witness speed infinite 1', False),
    ('effect give Witness minecraft:strength infinite 2 true', False), ('effect give @a night_vision infinite', False), ('effect give Witness speed 0', False),
    ('effect give Witness speed 1000001', False), ('effect give Witness speed 10 256', False), ('effect give Witness nope', False),
    ('effect clear Witness speed', False), ('effect clear Witness speed', False), ('effect clear Witness night_vision', False),
    ('effect clear Witness', False), ('effect clear Witness', False), ('effect clear @a', False),
    ('effect give Witness instant_health 1 0', False), ('effect give Witness instant_damage 1 0', False), ('effect give Witness poison 5', False),
    ('effect clear Witness poison', False), ('effect clear', False),
    # Sounds, with their random seeds zeroed before comparison.
    ('tp @a 0 100 0', False), ('playsound minecraft:entity.player.levelup', False), ('playsound minecraft:entity.player.levelup master', False),
    ('playsound entity.player.levelup player @a', False), ('playsound minecraft:block.note_block.harp record Witness 0 100 0 0.5 2', False), ('playsound minecraft:block.note_block.harp record Witness 0 100 0 2 0.5 0.3', False),
    ('playsound minecraft:block.note_block.harp record Witness 1000 100 0', False), ('playsound minecraft:block.note_block.harp record Witness 1000 100 0 1 1 0.5', False), ('playsound custom:my.sound master @s', False),
    ('playsound minecraft:entity.player.levelup nope', False), ('playsound minecraft:entity.player.levelup master @s ~ ~ ~ -1', False), ('playsound minecraft:entity.player.levelup master @s ~ ~ ~ 1 3', False),
    ('stopsound @s', False), ('stopsound @a master', False), ('stopsound Witness * minecraft:entity.player.levelup', False),
    ('stopsound @s player minecraft:entity.player.levelup', False),
    # Game rules with server behaviour: damage types, respawn and debug flags, command feedback.
    ('gamemode survival Witness', False), ('gamerule fall_damage false', False), ('damage Witness 2 fall', False),
    ('gamerule fall_damage true', False), ('damage Witness 2 fall', False), ('gamerule fire_damage false', False),
    ('damage Witness 2 on_fire', False), ('gamerule drowning_damage false', False), ('damage Witness 2 drown', False),
    ('gamerule freeze_damage false', False), ('damage Witness 2 freeze', False), ('gamerule fire_damage true', False),
    ('gamerule drowning_damage true', False), ('gamerule freeze_damage true', False), ('gamerule immediate_respawn true', False),
    ('gamerule immediate_respawn false', False), ('gamerule reduced_debug_info true', False), ('gamerule reduced_debug_info false', False),
    ('gamerule limited_crafting true', False), ('gamerule limited_crafting false', False), ('gamerule send_command_feedback false', False),
    ('time set 100', False), ('seed', False), ('gamerule send_command_feedback true', False),
    ('gamerule log_admin_commands false', False), ('time set 200', False), ('gamerule log_admin_commands true', False),
    # /rotate, with its rotation and look-at packets; the witness stands on stone so vanilla never kicks them for floating.
    ('!quiet fill -1 99 -1 1 99 1 minecraft:stone', False), ('tp Witness 0 100 0 0 0', False), ('tp ParityProbe 3 100 3 0 0', False), ('rotate Witness 90 45', False),
    ('rotate Witness ~10 ~-5', False), ('rotate Witness facing 0 64 0', False), ('rotate Witness facing entity ParityProbe', False),
    ('rotate Witness facing entity ParityProbe eyes', False), ('rotate @a 0 0', False), ('rotate @s 400 100', False),
    # /attribute on players and a pig, with the attribute update every tracker receives.
    ('attribute Witness max_health get', False), ('attribute Witness minecraft:max_health get 2.5', False),
    ('attribute Witness max_health base get', False), ('attribute Witness max_health base set 30', False),
    ('attribute Witness max_health base get', False), ('attribute Witness max_health get', False),
    ('attribute Witness max_health base set 2000', False), ('attribute Witness max_health base reset', False),
    ('attribute Witness movement_speed get', False), ('attribute Witness movement_speed base get 1000', False),
    ('attribute Witness movement_speed modifier add test:fast 0.5 add_multiplied_base', False),
    ('attribute Witness movement_speed modifier add test:fast 0.5 add_multiplied_base', False),
    ('attribute Witness movement_speed modifier value get test:fast', False),
    ('attribute Witness movement_speed modifier value get test:fast 10', False), ('attribute Witness movement_speed get', False),
    ('attribute Witness movement_speed modifier add test:plus 0.1 add_value', False),
    ('attribute Witness movement_speed modifier add test:total 1 add_multiplied_total', False),
    ('attribute Witness movement_speed get', False), ('attribute Witness movement_speed modifier remove test:fast', False),
    ('attribute Witness movement_speed modifier remove test:fast', False),
    ('attribute Witness movement_speed modifier value get test:nope', False),
    ('attribute Witness movement_speed modifier remove test:plus', False),
    ('attribute Witness movement_speed modifier remove test:total', False), ('attribute Witness follow_range get', False),
    ('attribute Witness nope get', False), ('attribute Witness scale base set 2', False),
    ('attribute Witness scale base reset', False), ('!quiet kill @e[type=!player]', False), ('summon pig 0 64 0', False),
    ('attribute @e[type=pig,limit=1] max_health get', False), ('attribute @e[type=pig,limit=1] attack_damage get', False),
    ('kill @e[type=pig]', False),
    # Players paired with a tracker again receive each other's synchronized attribute instances.
    ('!quiet tp @s 0 100 0', False), ('tp @a[name=Witness] 300 100 0', False), ('tp @a[name=Witness] 1 100 0', False),
    # A killed mob loses its scores and team once its death animation ends and it is removed; it has
    # no AI, so it cannot wander off the floor and fall.
    ('scoreboard objectives add dead dummy', False), ('scoreboard objectives setdisplay sidebar dead', False), ('team add gone', False),
    ('summon pig 0 100 0 {NoAI:1b}', False), ('scoreboard players set @e[type=pig] dead 3', False), ('team join gone @e[type=pig]', False),
    ('kill @e[type=pig]', False), ('scoreboard players list @e[type=pig]', False), ('scoreboard players list', False),
    ('team list gone', False), ('scoreboard objectives remove dead', False), ('team remove gone', False),
    # A mob dropped six blocks inside a stone box takes fall damage; /damage hurts and kills mobs too.
    # The failing /damage lines on a giant, which never spawns, wait while still recording damage events.
    ('!quiet fill -2 99 -2 2 108 2 minecraft:stone hollow', False), ('!quiet tp @s 0 100 0', False), ('!quiet kill @e[type=!player]', False),
    ('summon pig 0.5 106 0.5', False), ('damage @e[type=giant,limit=1] 1', False), ('damage @e[type=giant,limit=1] 1', False),
    ('damage @e[type=pig,limit=1] 2', False), ('damage @e[type=giant,limit=1] 1', False), ('damage @e[type=pig,limit=1] 1', False),
    ('damage @e[type=pig,limit=1] 3 in_fire', False), ('damage @e[type=pig,limit=1] 100 fall', False), ('damage @e[type=pig,limit=1] 1', False),
    ('summon chicken 0.5 106 0.5', False), ('damage @e[type=giant,limit=1] 1', False), ('damage @e[type=chicken,limit=1] 1 fall', False),
    ('!quiet kill @e[type=!player]', False),
    # Eyes inside sand suffocate a mob, not inside glass; a survival player suffocates in stone while
    # saturated healing keeps pace.
    ('!quiet gamemode survival @s', False), ('summon pig 0.5 100 0.5 {NoAI:1b}', False), ('!quiet setblock 0 100 0 minecraft:sand', False),
    ('damage @e[type=giant,limit=1] 1', False), ('damage @e[type=giant,limit=1] 1', False), ('!quiet setblock 0 100 0 minecraft:glass', False),
    ('damage @e[type=giant,limit=1] 1', False), ('!quiet kill @e[type=!player]', False), ('!quiet setblock 0 101 0 minecraft:stone', False),
    ('damage @e[type=giant,limit=1] 1', False), ('damage @e[type=giant,limit=1] 1', False), ('!quiet setblock 0 101 0 minecraft:air', False),
    ('!quiet gamemode creative @s', False),
    # A dropped item has 5 health, truncated after each hit, and goes at 0; the operator stands clear of it.
    ('!quiet tp @s 0 110 6', False), ('!quiet setblock 1 104 1 minecraft:dirt', False), ('!quiet setblock 1 104 1 minecraft:air destroy', False),
    ('damage @e[type=item,limit=1] 2', False), ('damage @e[type=item,limit=1] 2.9 in_fire', False), ('damage @e[type=item,limit=1] 1', False),
    ('damage @e[type=item,limit=1] 1', False),
    # Command chat reaches unsigned clients as disguised chat with the bound chat type.
    ('say hello there', False), ('say spaced   out  text', False), ('say @a', False), ('say @e[type=pig]', False), ('!witness say no permission', False),
    ('me waves', False), ('me @e[type=player,limit=1] waves', False), ('!witness me @a stays literal', False),
    ('msg Witness psst', False), ('tell Witness two', False), ('w Witness three', False), ('msg @a everyone', False),
    ('!witness msg ParityProbe back', False), ('!witness msg Witness myself', False),
    ('!witness teammsg nobody', False), ('!quiet team add chatters', False), ('!quiet team join chatters ParityProbe', False),
    ('teammsg hi team', False), ('!quiet team join chatters Witness', False), ('tm short', False), ('!witness teammsg from witness', False),
    ('!quiet team remove chatters', False),
    # Clone modes, overlap, size, loading and dimension checks, inside every scattered spawn's view.
    ('!quiet fill 0 240 0 2 242 2 stone', False), ('!quiet setblock 1 241 1 oak_sign', False), ('!quiet setblock 0 240 0 air', False),
    ('clone 0 240 0 2 242 2 10 240 10', False), ('clone 0 240 0 2 242 2 10 240 10', False), ('clone 0 240 0 2 242 2 1 240 1', False),
    ('clone 0 240 0 2 242 2 1 240 1 replace force', False), ('clone 0 240 0 2 242 2 20 240 0 masked', False),
    ('clone 0 240 0 2 242 2 0 250 0 filtered stone', False), ('clone 0 240 0 2 242 2 0 250 0 filtered #minecraft:logs', False),
    ('clone 0 240 0 2 242 2 0 260 0 strict replace move', False), ('clone 0 240 0 2 242 2 0 260 0 masked', False),
    ('clone 0 240 0 31 300 31 0 240 0 replace force', False), ('clone 0 240 0 100 242 100 0 270 0', False), ('clone 0 240 0 2 242 2 5000 240 5000', False),
    ('clone from overworld 0 260 0 2 262 2 to minecraft:overworld 16 240 16 replace normal', False),
    ('clone from minecraft:nowhere 0 260 0 2 262 2 16 240 16', False), ('clone ~ ~ ~ ~1 ~1 ~1 ~ 280 ~ masked', False),
    ('clone 0 400 0 1 401 1 0 240 0', False), ('clone 0 260 0 2 262 2 0 261 0 masked move', False),
    ('!quiet fill 4 290 4 5 291 5 barrier', False), ('clone 4 290 4 5 291 5 8 290 8', False),
    ('list', False),
]


def collect(client, since, command=''):
    extra = next((packets for prefix, packets in EXTRA.items() if command.removeprefix('!witness ').startswith(prefix)), ())
    # Rain starting, stopping or fading follows each server's random weather, not the command.
    weather = lambda name, payload: name == 'game_event' and payload[0] in (1, 2, 7, 8) and not command.startswith('weather')
    return [{'packet': name, 'hex': payload.hex()} for name, payload in client.seen[since:] if (name in COMPARED or name in extra) and not weather(name, payload)]


# Packets compared for particular commands only; elsewhere they carry random hurt-sound pitches and the like.
EXTRA = {'summon pig 0.5 106': ('damage_event',), 'summon chicken 0.5 106': ('damage_event',), 'tp @a[name=Witness]': ('update_attributes',), 'attribute': ('update_attributes',), 'rotate': ('player_rotation', 'player_look_at'), 'playsound': ('sound',), 'stopsound': ('stop_sound',), 'gamerule': ('game_event', 'entity_event'), 'damage ': ('damage_event',), 'say ': ('disguised_chat',), 'me ': ('disguised_chat',), 'msg ': ('disguised_chat',), 'tell ': ('disguised_chat',), 'w ': ('disguised_chat',), 'teammsg ': ('disguised_chat',), 'tm ': ('disguised_chat',)}

# Chat, disconnects, and the packets commands send directly: scoreboard, teams, titles and the world border.
COMPARED = ('system_chat', 'disconnect', 'set_objective', 'set_score', 'reset_score', 'set_display_objective', 'set_player_team',
            'set_title_text', 'set_subtitle_text', 'set_action_bar_text', 'set_titles_animation', 'clear_titles', 'update_mob_effect', 'remove_mob_effect',
            'set_border_size', 'set_border_lerp_size', 'set_border_center', 'set_border_warning_distance', 'set_border_warning_delay')


def record(kind, output, java, jar, eula, script=SCRIPT):
    server = Server(output / kind, kind, java, jar, eula, difficulty=2)
    server.start()
    results = []
    try:
        operator = Probe(server.port, 'operator').login()
        witness = Probe(server.port, 'witness').login('Witness')
        for client in (operator, witness):
            client.tick_barrier()
        for command, reconnect in script:
            if reconnect:
                witness.close()
                witness = Probe(server.port, 'witness').login('Witness')
                witness.tick_barrier()
                operator.tick_barrier()
            if command == '!respawn':
                # Not a command: dead players leave the death screen before the next step. Both
                # servers ignore the request from a living player. One at a time, since a
                # respawned player moves to the end of the player list.
                for client in (operator, witness):
                    client.send('client_command', wire.vi(0))
                    client.tick_barrier()
                continue
            if command == '!reconnect':
                # The witness joins again, for instance after a kick or a pardon.
                witness.close()
                witness = Probe(server.port, 'witness').login('Witness')
                witness.tick_barrier()
                operator.tick_barrier()
                continue
            if command.startswith('!quiet '):
                # A reset whose reply depends on earlier timing: run by the operator, not compared.
                operator.send('chat_command', wire.string(command.removeprefix('!quiet ')))
                operator.tick_barrier()
                witness.tick_barrier()
                continue
            marks = (len(operator.seen), len(witness.seen))
            # "!witness <command>" runs the command as the non-operator witness.
            sender, other = (witness, operator) if command.startswith('!witness ') else (operator, witness)
            sender.send('chat_command', wire.string(command.removeprefix('!witness ')))
            sender.tick_barrier()
            try:
                other.tick_barrier()
            except (AssertionError, EOFError, OSError, TimeoutError):
                pass  # The witness may have been kicked.
            results.append({'command': command, 'operator': collect(operator, marks[0], command), 'witness': collect(witness, marks[1], command)})
        operator.close()
        witness.close()
    finally:
        server.stop()
    (output / f'{kind}-feedback.json').write_text(json.dumps(results, indent=1))
    return results


def normalize_seeds(items):
    """Zero the random seed that ends every sound packet."""
    return [{**item, 'hex': item['hex'][:-16] + '0' * 16} if item['packet'] == 'sound' else item for item in items]


def normalize_uuids(items):
    """Zero random entity UUIDs (int-array tags and dashed insertion strings) for comparison."""
    uuid_tag = re.compile(r'0b0004' + b'uuid'.hex() + r'00000004[0-9a-f]{32}')
    insertion = re.compile(r'08' + struct.pack('>H', 9).hex() + b'insertion'.hex() + r'0024[0-9a-f]{72}')
    # Score holders and team members name entities by their dashed UUID strings.
    dashed = re.compile(rb'[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}')
    return [{**item, 'hex': dashed.sub(b'0' * 36, bytes.fromhex(insertion.sub('08' + struct.pack('>H', 9).hex() + b'insertion'.hex() + '0024' + '30' * 36,
                                         uuid_tag.sub('0b0004' + b'uuid'.hex() + '00000004' + '0' * 32, item['hex'])))).hex()} for item in items]


# Packets that start with the affected entity's network ID as a VarInt.
ENTITY_ADDRESSED = ('update_mob_effect', 'remove_mob_effect', 'update_attributes', 'damage_event')


def normalize_entity_ids(items):
    """Zero the entity network ID that entity-addressed packets start with.

    Each server numbers entities from its own counter, so IDs drift apart once the scripts have
    created different numbers of short-lived entities (loot, summons, reconnected players).
    """
    def strip(payload):
        data = bytes.fromhex(payload)
        end = next(i for i, byte in enumerate(data) if byte < 0x80) + 1
        return '00' + data[end:].hex()
    # entity_event addresses its entity with a plain int instead.
    return [{**item, 'hex': strip(item['hex'])} if item['packet'] in ENTITY_ADDRESSED
            else {**item, 'hex': '00000000' + item['hex'][8:]} if item['packet'] == 'entity_event' else item for item in items]


def normalize_attribute_order(items):
    """Sort update_attributes entries: vanilla keys them by identity hash, so their order varies per run."""
    def varint(data, at):
        value = shift = 0
        while True:
            byte = data[at]
            at += 1
            value |= (byte & 0x7f) << shift
            shift += 7
            if byte < 0x80:
                return value, at
    def sort(payload):
        data = bytes.fromhex(payload)
        _, at = varint(data, 0)
        count, at = varint(data, at)
        head, entries = data[:at], []
        for _ in range(count):
            start = at
            _, at = varint(data, at)
            at += 8
            modifiers, at = varint(data, at)
            for _ in range(modifiers):
                length, at = varint(data, at)
                at += length + 9
            entries.append(data[start:at])
        return (head + b''.join(sorted(entries)) + data[at:]).hex()
    return [{**item, 'hex': sort(item['hex'])} if item['packet'] == 'update_attributes' else item for item in items]


def compare(reference, php):
    differences = []
    for expected, actual in zip(reference, php):
        # Vanilla's kick report and leave message race the network thread closing the channel.
        unordered = expected['command'].startswith('kick ')
        # A summoned entity has a random UUID on each server.
        random_ids = expected['command'].startswith('summon ') or '@e' in expected['command'] or '@n' in expected['command']
        for role in ('operator', 'witness'):
            key = (lambda items: sorted(item['hex'] for item in items)) if unordered else (normalize_uuids if random_ids else (lambda items: items))
            if key(normalize_seeds(normalize_attribute_order(normalize_entity_ids(expected[role])))) != key(normalize_seeds(normalize_attribute_order(normalize_entity_ids(actual[role])))):
                differences.append({'command': expected['command'], 'role': role, 'reference': expected[role], 'php': actual[role]})
    return differences


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path, required=True, help='Empty directory for servers and recordings')
    parser.add_argument('--eula-file', type=Path, help='Existing user-accepted eula.txt; required to record the official server')
    parser.add_argument('--jar', type=Path, default=ROOT / 'tools/.cache/26.3/server.jar')
    parser.add_argument('--commands', type=Path, help='Run these commands (one per line) instead of the built-in script')
    parser.add_argument('--java', default=str(Path(os.environ['JAVA_HOME']) / 'bin/java') if 'JAVA_HOME' in os.environ else 'java')
    args = parser.parse_args()
    # The servers run in their own directories, so their paths must not be relative.
    args.output = args.output.resolve()
    args.output.mkdir(parents=True, exist_ok=False)
    script = [(line.strip(), False) for line in args.commands.read_text().splitlines() if line.strip()] if args.commands else SCRIPT
    php = record('php', args.output, args.java, args.jar, args.eula_file, script)
    if args.eula_file is None:
        print('Recorded PHP feedback only; pass --eula-file to compare with the official server')
        return
    reference = record('java', args.output, args.java, args.jar, args.eula_file, script)
    differences = compare(reference, php)
    (args.output / 'differences.json').write_text(json.dumps(differences, indent=1))
    observed = sum(command not in ('!respawn', '!reconnect') and not command.startswith('!quiet ') for command, _ in script) * 2
    print(f'{observed - len(differences)}/{observed} command observations identical; differences in {args.output / "differences.json"}')
    sys.exit(1 if differences else 0)


if __name__ == '__main__':
    main()
