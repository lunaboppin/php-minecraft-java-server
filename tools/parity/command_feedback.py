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
    ('give Witness diamond_sword[enchantments={nope:1}]', False), ('give Witness dirt[damage=1]', False),
    # Component presence, absence and negated equality, counted without clearing.
    ('clear Witness diamond_sword[!damage] 0', False), ('clear Witness diamond_sword[damage] 0', False), ('clear Witness diamond_sword[!enchantments] 0', False),
    ('clear Witness diamond_sword[!damage=5] 0', False), ('clear Witness *[max_damage] 0', False), ('clear Witness *[!max_damage] 0', False),
    ('clear Witness enchanted_book[stored_enchantments] 0', False), ('clear Witness *[!damage=5,enchantments={sharpness:3}] 0', False),
    ('clear Witness diamond_sword[damage=5]', False),
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
    # A dropped item has 5 health, truncated after each hit, and goes at 0; both players stand clear of
    # it, the witness on the box's roof instead of beside where it lands.
    ('!quiet tp @s 0 110 6', False), ('!quiet tp Witness 0 109 0', False), ('!quiet setblock 1 104 1 minecraft:dirt', False), ('!quiet setblock 1 104 1 minecraft:air destroy', False),
    ('damage @e[type=item,limit=1] 2', False), ('damage @e[type=item,limit=1] 2.9 in_fire', False), ('damage @e[type=item,limit=1] 1', False),
    ('damage @e[type=item,limit=1] 1', False), ('!quiet tp Witness 1 100 0', False),
    # Command chat reaches unsigned clients as disguised chat with the bound chat type.
    ('say hello there', False), ('say spaced   out  text', False), ('say @a', False), ('say @e[type=pig]', False), ('!witness say no permission', False),
    ('me waves', False), ('me @e[type=player,limit=1] waves', False), ('!witness me @a stays literal', False),
    ('msg Witness psst', False), ('tell Witness two', False), ('w Witness three', False), ('msg @a everyone', False),
    ('!witness msg ParityProbe back', False), ('!witness msg Witness myself', False),
    ('!witness teammsg nobody', False), ('!quiet team add chatters', False), ('!quiet team join chatters ParityProbe', False),
    ('teammsg hi team', False), ('!quiet team join chatters Witness', False), ('tm short', False), ('!witness teammsg from witness', False),
    ('!quiet team remove chatters', False),
    # Command boss bars, their boss_event packets (random UUIDs aside) and a rejoining listed player.
    ('bossbar list', False), ('bossbar add raid "Raid"', False), ('bossbar add raid "Again"', False), ('bossbar add minecraft:alpha {"text":"Alpha","color":"red"}', False),
    ('bossbar add zeta:omega "Omega"', False), ('bossbar list', False), ('bossbar get raid value', False), ('bossbar get raid max', False),
    ('bossbar get raid visible', False), ('bossbar get raid players', False), ('bossbar set raid players @s', False), ('bossbar set raid players @s', False),
    ('bossbar get raid players', False), ('bossbar set raid value 40', False), ('bossbar set raid value 40', False), ('bossbar set raid max 80', False),
    ('bossbar set raid max 80', False), ('bossbar set raid value 200', False), ('bossbar set raid color red', False), ('bossbar set raid color red', False),
    ('bossbar set raid style notched_10', False), ('bossbar set raid style notched_10', False), ('bossbar set raid name {"text":"Big raid","bold":true}', False),
    ('bossbar set raid name {"text":"Big raid","bold":true}', False), ('bossbar set raid visible false', False), ('bossbar set raid visible false', False),
    ('bossbar get raid visible', False), ('bossbar set raid value 10', False), ('bossbar set raid visible true', False), ('bossbar set raid players Witness', False),
    ('bossbar set raid players @a', False), ('bossbar set raid players', False), ('bossbar set raid players', False), ('bossbar set alpha players @s', False),
    ('!reconnect', False), ('bossbar get nothing value', False), ('bossbar remove nothing', False), ('bossbar remove raid', False), ('bossbar remove minecraft:alpha', False),
    ('bossbar remove zeta:omega', False), ('bossbar list', False), ('!witness bossbar list', False),
    # Entity tags: the one entity changed is named, several are counted, and lists are sorted.
    ('!quiet kill @e[type=!player]', False), ('tag @s list', False), ('tag @s add red', False), ('tag @s add red', False), ('tag @a add blue', False),
    ('tag @s list', False), ('tag Witness list', False), ('tag @a list', False), ('tag @a add green', False), ('tag @s add k5', False),
    ('tag @a list', False), ('tag @e[tag=red] list', False), ('tag @e[tag=!red] list', False), ('tag @e[tag=] list', False),
    ('tag @s remove red', False), ('tag @s remove red', False), ('tag @a remove blue', False), ('!witness tag @s add nope', False),
    ('!quiet tag @a remove green', False), ('!quiet tag @s remove k5', False),
    ('setidletimeout 5', False), ('setidletimeout 0', False), ('!witness setidletimeout 3', False),
    # IP bans on addresses no test client uses; banning a connected player would disconnect them all.
    ('banlist ips', False), ('ban-ip 10.0.0.5', False), ('ban-ip 10.0.0.5', False), ('ban-ip 10.0.0.6 Griefing  the spawn', False),
    ('ban-ip 192.168.1.20 @a', False), ('ban-ip nobody', False), ('ban-ip 999.1.1.1', False), ('banlist ips', False), ('banlist', False),
    ('pardon-ip 10.0.0.5', False), ('pardon-ip 10.0.0.5', False), ('pardon-ip notanip', False), ('!quiet pardon-ip 10.0.0.6', False),
    ('!quiet pardon-ip 192.168.1.20', False), ('!witness ban-ip 10.0.0.9', False),
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
    # Forced chunks, far from both scattered spawns; each keeps the chunks up to 2 away loaded.
    ('forceload query', False), ('forceload add 1000 1000', False), ('forceload add 1000 1000', False), ('forceload query 1000 1000', False),
    ('forceload query 1016 1000', False), ('setblock 1000 100 1000 stone', False), ('setblock 1020 100 1000 stone', False),
    ('forceload add -40 -40 -1 20', False), ('forceload add -40 -40 -1 20', False), ('forceload query', False),
    ('forceload add 0 0 300 0', False), ('forceload add 0 0 255 0', False), ('forceload add 0 0 256 0', False),
    ('forceload remove 1000 1000', False), ('forceload remove 1000 1000', False), ('forceload query', False),
    ('forceload remove -40 -40 40 40', False), ('forceload add 30000000 0', False), ('forceload add 5000 5000', False),
    ('forceload remove all', False), ('forceload query', False), ('forceload remove all', False), ('forceload add 2000 2000', False),
    ('setblock 2032 100 2032 stone', False), ('setblock 2048 100 2032 stone', False), ('setblock 1968 100 1968 stone', False),
    ('setblock 1952 100 1968 stone', False), ('forceload query 1 2 3', False), ('forceload query 1.5 2', False), ('forceload add 1', False),
    ('forceload remove all', False),
    # Particles on a platform both players stand on, with their level_particles packets.
    ('!quiet fill -2 199 -2 2 199 2 stone', False), ('!quiet tp @a 0 200 0 0 0', False), ('particle flame 0 201 0', False),
    ('particle minecraft:heart 0.25 201 -1 1 2 3 0.5 10', False), ('particle crit 0 201 0 0 0 0 0 5 force', False),
    ('particle crit 0 201 0 0 0 0 0 5 normal Witness', False), ('particle crit 0 201 0 0 0 0 0 5 force @a[name=nobody]', False),
    ('particle crit 0 250 0 0 0 0 0 5 normal', False), ('particle crit 0 250 0 0 0 0 0 5 force', False),
    ('particle nothing_here 0 201 0', False), ('particle dust{color:[1.0,0.5,0.0],scale:2} 0 201 0', False),
    ('particle dust{color:16711680,scale:1.5f} 0 201 0', False), ('particle dust{color:[1.0, 0.0, 0.0], scale:1} 0 201 0', False),
    ('particle dust{color:[1,0,0],scale:5} 0 201 0', False), ('particle dust 0 201 0', False), ('particle dust{scale:1} 0 201 0', False),
    ('particle dust_color_transition{from_color:[1,0,0],to_color:[0,0,1],scale:1} 0 201 0', False),
    ('particle block{block_state:"stone"} 0 201 0', False),
    ('particle block{block_state:{Name:"minecraft:oak_log",Properties:{axis:"x"}}} 0 201 0', False),
    ('particle falling_dust{block_state:"minecraft:sand"} 0 201 0', False), ('particle entity_effect{color:[1,0,0,0.5]} 0 201 0', False),
    ('particle entity_effect{color:-1} 0 201 0', False), ('particle effect 0 201 0', False),
    ('particle effect{color:[0,1,0],power:2} 0 201 0', False), ('particle dragon_breath 0 201 0', False),
    ('particle dragon_breath{power:3} 0 201 0', False), ('particle sculk_charge{roll:1.5} 0 201 0', False),
    ('particle shriek{delay:7} 0 201 0', False), ('particle geyser{water_blocks:3} 0 201 0', False),
    ('particle geyser_base{water_blocks:3,burst_impulse_base:0.5} 0 201 0', False), ('particle flash{color:[1,1,1,1]} 0 201 0', False),
    ('particle tinted_leaves{color:255} 0 201 0', False), ('particle flame{ignored:1} 0 201 0 ^ ^ ^1 0 1', False),
    ('particle flame ~ ~ ~', False), ('particle block{block_state:{id:"minecraft:oak_log",properties:{axis:"x"}}} 0 201 0', False),
    ('particle block{block_state:{id:"oak_log",properties:{axis:"q"}}} 0 201 0', False), ('particle block{} 0 201 0', False),
    ('particle dust_color_transition{} 0 201 0', False), ('particle geyser_base{water_blocks:1} 0 201 0', False),
    ('particle dust{color:[1,0,0],scale:0.001} 0 201 0', False), ('particle dust{color:[1,0,0],scale:4.5} 0 201 0', False),
    # Spectator cameras on a platform both players stand on, with their set_camera packets.
    ('!quiet fill -3 199 -3 3 199 3 stone', False), ('!quiet tp @a 0 200 0', False), ('!quiet gamemode creative @s', False), ('spectate', False),
    ('spectate Witness', False), ('!witness spectate ParityProbe', False), ('!quiet gamemode spectator @s', False), ('spectate @s', False),
    ('spectate Witness', False), ('spectate Witness ParityProbe', False), ('spectate', False), ('spectate', False),
    ('spectate @e[type=pig]', False), ('!quiet summon pig 1 200 1 {NoAI:1b}', False), ('spectate @e[type=pig,limit=1]', False),
    ('spectate @e[type=pig]', False), ('spectate ParityProbe Witness', False), ('gamemode creative @s', False), ('spectate Witness', False),
    ('!quiet gamemode spectator @s', False), ('spectate Witness', False), ('tp @s 3 200 3', False), ('spectate Witness', False),
    ('!quiet kill @e[type=pig]', False), ('!quiet gamemode creative @s', False),
    # Swings on a platform both players stand on, with their swing_animation packets.
    # The item lands out of the players' pickup reach, so it is still there for the next line.
    ('!quiet fill -3 199 -3 3 199 3 stone', False), ('!quiet tp @a 0 200 0', False), ('swing', False), ('swing @s offhand', False),
    ('swing Witness mainhand stab', False), ('swing @a offhand whack 20', False), ('swing @a mainhand none 1s', False),
    ('swing @a mainhand wobble', False), ('swing @a mainhand whack 0', False), ('!quiet summon pig 1 200 1 {NoAI:1b}', False),
    ('swing @e[type=pig]', False), ('!quiet kill @e[type=pig]', False), ('!quiet summon item 2.5 201 2.5 {Item:{id:"stone"}}', False),
    ('swing @e[type=item]', False), ('!quiet kill @e[type=item]', False), ('!witness swing', False),
    # Stopwatches, without querying a running one, whose elapsed time depends on the wall clock.
    ('stopwatch query lap', False), ('stopwatch create lap', False), ('stopwatch create minecraft:lap', False),
    ('stopwatch create custom:timer', False), ('stopwatch restart lap', False), ('stopwatch restart nothing', False),
    ('stopwatch remove lap', False), ('stopwatch remove lap', False), ('stopwatch remove custom:timer', False), ('stopwatch create Bad', False),
    ('!witness stopwatch create lap', False),
    # Tick rate, freezing and stepping with their ticking packets; sprints report wall-clock timings.
    ('tick rate 40', False), ('tick rate 20.55', False), ('tick rate 0.5', False), ('tick rate 10001', False), ('tick step', False),
    ('tick step stop', False), ('tick freeze', False), ('tick freeze', False), ('tick step', False), ('tick step 5', False),
    ('tick step 2s', False), ('tick step stop', False), ('tick step stop', False), ('tick step 0', False), ('tick unfreeze', False),
    ('tick sprint stop', False), ('tick rate 20', False), ('!witness tick freeze', False),
    # Transfers, with their transfer packets, which the test clients ignore.
    ('transfer example.com', False), ('transfer "play.example.net" 25570', False), ('transfer other.host 1 Witness', False),
    ('transfer other.host 2 @a', False), ('transfer other.host 3 @a[name=nobody]', False), ('transfer other.host 0', False),
    ('transfer other.host 65536', False), ('transfer "spaced host" 4 Witness', False), ('!witness transfer example.com', False),
    # Random sequences, which both servers seed from world seed 42.
    ('!quiet random reset *', False),
    ('random value 1..6 dice', False), ('random value 1..6 dice', False), ('random value 1..6 dice', False), ('random roll 1..100 dice', False),
    ('random value 1..6 minecraft:other', False), ('random value -5..5 dice', False), ('random value 5 dice', False),
    ('random value .. dice', False), ('random value ..10 dice', False), ('random value 1.. dice', False), ('random value 3..1 dice', False),
    ('random value 0..2147483646 dice', False), ('random value -2147483648..-2 dice', False), ('random reset dice', False),
    ('random value 1..6 dice', False), ('random reset dice 7', False), ('random value 1..6 dice', False), ('random reset dice 7 false', False),
    ('random value 1..6 dice', False), ('random reset dice 7 false false', False), ('random value 1..6 dice', False),
    ('random value 1..6 other', False), ('random reset *', False), ('random value 1..6 dice', False), ('random reset * 3 true false', False),
    ('random value 1..6 dice', False), ('random value 1..6 other', False), ('random reset * 0', False),
    ('!witness random value 1..6 dice', False), ('!witness random reset *', False),
    # Post effects, with the post_effects packets each change sends on the player's next tick.
    ('posteffect list', False), ('posteffect list Witness', False), ('posteffect add @a minecraft:creeper', False),
    ('posteffect add @a creeper', False), ('posteffect add Witness spider', False), ('posteffect list', False),
    ('posteffect list Witness', False), ('posteffect remove @a creeper', False), ('posteffect remove @a creeper', False),
    ('posteffect remove Witness spider', False), ('posteffect clear @a', False), ('posteffect add @s invert', False),
    ('posteffect clear @a', False), ('posteffect add @a[name=nobody] creeper', False), ('!witness posteffect list', False),
    ('!quiet posteffect clear @a', False),
    # /execute modifiers, conditions and forks on a platform both players stand on; forked failures stay silent.
    ('!quiet fill -3 199 -3 3 199 3 stone', False), ('!quiet tp @a 0 200 0 0 0', False), ('execute run say hi', False),
    ('execute as Witness run say hi', False), ('execute as @a run say multi', False), ('execute at Witness run setblock ~ ~-1 ~ dirt', False),
    ('execute positioned 2 200 2 run setblock ~ ~-1 ~ glass', False), ('execute positioned 2 200 2 run setblock ~ ~-1 ~ glass', False),
    ('execute as @a run setblock 1 199 1 glass', False), ('execute if entity @a', False), ('execute if entity @e[type=pig]', False),
    ('execute unless entity @e[type=pig]', False), ('execute unless entity @a', False), ('execute if block 0 199 0 dirt', False),
    ('execute if block 0 199 0 stone', False), ('!quiet scoreboard objectives add sc dummy', False),
    ('!quiet scoreboard players set Witness sc 5', False), ('execute if score Witness sc matches 1..10', False),
    ('execute if score Witness sc matches 6..', False), ('execute if score Witness sc > ParityProbe sc', False),
    ('!quiet scoreboard players set ParityProbe sc 3', False), ('execute if score Witness sc > ParityProbe sc', False),
    ('execute unless score Witness sc <= ParityProbe sc', False), ('execute if dimension overworld', False),
    ('execute if dimension the_nether', False), ('execute if loaded 0 199 0', False), ('execute if loaded 100000 0 100000', False),
    ('execute rotated 90 0 run tp @s ^ ^ ^1', False), ('!quiet tp @a 0 200 0 0 0', False), ('execute facing 5 200 0 run tp @s ^ ^ ^1', False),
    ('!quiet tp @a 0 200 0 0 0', False), ('execute anchored eyes run tp @s ^ ^ ^', False), ('!quiet tp @a 0 200 0 0 0', False),
    ('execute as @a at @s run setblock ~ ~-1 ~ gold_block', False), ('execute if entity @s run say ok', False),
    ('execute unless entity @s run say no', False), ('execute as @e[type=pig] run say none', False),
    ('execute as @a if entity @s[name=Witness]', False), ('execute as @a unless entity @s[name=Witness]', False),
    ('execute align xz positioned ~0.5 ~ ~0.5 run setblock ~2 ~-1 ~ emerald_block', False), ('execute as Witness at @s run tp ~ ~ ~1', False),
    ('!quiet tp @a 0 200 0 0 0', False), ('execute positioned as Witness run setblock ~ ~-1 ~-1 diamond_block', False),
    ('execute rotated as Witness run tp @s ~ ~ ~', False), ('execute in the_nether if dimension the_nether', False),
    ('execute as Witness run gamemode survival', False), ('execute as Witness run gamemode survival @s', False),
    ('!quiet gamemode creative @a', False), ('!quiet scoreboard objectives remove sc', False), ('!witness execute run say x', False),
    # /execute as and at tagged mobs that do not move.
    ('!quiet fill -3 199 -3 3 199 3 stone', False), ('!quiet tp @a 0 200 0 0 0', False),
    ('!quiet summon pig 1 200 1 {NoAI:1b,Tags:["xp1"]}', False), ('!quiet summon cow 2 200 1 {NoAI:1b,Tags:["xc1"]}', False),
    ('execute as @e[tag=xp1] run say oink', False), ('execute as @e[tag=xc1] run me moos', False),
    ('execute as @e[tag=xp1] run tp @s ~ ~ ~2', False), ('execute at @e[tag=xc1] run setblock ~ ~-1 ~ dirt', False),
    ('execute as @e[tag=xc1] at @s run setblock ~1 ~-1 ~ glass', False), ('execute as @e[tag=xc1] run kill @s', False),
    ('execute as @e[tag=xp1] if entity @s[type=pig] run say still', False), ('!quiet kill @e[tag=xp1]', False),
    # Registered dialogs, with their show_dialog and clear_dialog packets.
    ('dialog show @s minecraft:server_links', False), ('dialog show @a quick_actions', False), ('dialog show Witness custom_options', False),
    ('dialog show @a nothing_here', False), ('dialog clear @s', False), ('dialog clear @a', False),
    ('dialog show @a[name=nobody] server_links', False), ('!witness dialog clear @s', False),
    # /execute if stopwatch, with ranges that hold whatever the wall clock says.
    ('execute if stopwatch nowhere 0..', False), ('!quiet stopwatch create lap', False), ('execute if stopwatch lap 0..', False),
    ('execute if stopwatch lap ..-1', False), ('execute unless stopwatch lap 100000..', False),
    ('execute if stopwatch lap 0.. run say timed', False), ('!quiet stopwatch remove lap', False),
    # /execute positioned over heightmaps, and summon, which makes the new mob the executor.
    ('!quiet fill -3 199 -3 3 199 3 stone', False), ('!quiet tp @a 0 200 0 0 0', False), ('!quiet setblock 1 200 1 oak_leaves', False),
    ('!quiet setblock 2 200 2 water', False), ('execute positioned over world_surface run setblock ~ ~ ~ glass', False),
    ('execute positioned 1 0 1 positioned over motion_blocking run setblock ~ ~ ~ glass', False),
    ('execute positioned 1 0 1 positioned over motion_blocking_no_leaves run setblock ~ ~ ~ dirt', False),
    ('execute positioned 2 0 2 positioned over ocean_floor run setblock ~ ~-1 ~ gold_block', False),
    ('execute positioned 2 0 2 positioned over motion_blocking run setblock ~ ~ ~ glass', False),
    ('execute positioned 100000 0 100000 positioned over world_surface run say far', False),
    ('execute positioned over nonsense run say x', False), ('execute summon pig run tag @s add xs1', False),
    ('execute summon pig run say oink', False), ('execute summon minecraft:cow at @s run setblock ~ ~-1 ~ diamond_block', False),
    ('execute summon player run say no', False), ('!quiet kill @e[type=!player]', False),
    # CombatTracker: a living attacker's hit starts combat; death ends it with its duration.
    ('!quiet gamemode survival Witness', False), ('damage Witness 2 minecraft:mob_attack by ParityProbe', False), ('damage Witness 2 minecraft:mob_attack by ParityProbe', False), ('damage Witness 40 minecraft:mob_attack by ParityProbe', False), ('!respawn', False), ('!quiet gamemode creative Witness', False),
    # Player knockback: hurt_animation's direction and the motion the player and its trackers get.
    # The air above the platform is cleared: water left from the /execute lines would flow under
    # the witness, and vanilla's current would push it before the hit.
    ('!quiet kill @e[type=!player]', False), ('!quiet fill -3 199 -3 3 199 5 stone', False), ('!quiet fill -3 200 -3 3 202 5 air', False),
    ('!quiet tp Witness 2 200 3 90 0', False), ('!quiet tp ParityProbe 0 200 0', False), ('!quiet gamemode survival Witness', False),
    ('damage Witness 3 minecraft:mob_attack by ParityProbe', False), ('!quiet effect give Witness instant_health 1 10', False),
    ('!quiet gamemode creative Witness', False),
    # Riding: set_passengers, the rider's teleport into the seat and the dismount beside the vehicle.
    ('!quiet kill @e[type=!player]', False), ('!quiet fill -3 199 -3 3 199 3 stone', False), ('!quiet tp @a 0 200 0 0 0', False),
    ('!quiet summon pig 1 200 1 {NoAI:1b,Tags:["ridea"],Rotation:[0f,0f]}', False), ('!quiet summon cow -1 200 1 {NoAI:1b,Tags:["rideb"],Rotation:[0f,0f]}', False),
    ('ride @e[tag=ridea,limit=1] dismount', False), ('ride @e[tag=ridea,limit=1] mount @e[tag=rideb,limit=1]', False),
    ('ride @e[tag=ridea,limit=1] mount @e[tag=rideb,limit=1]', False), ('ride @e[tag=rideb,limit=1] mount @e[tag=ridea,limit=1]', False),
    ('ride @e[tag=rideb,limit=1] mount @s', False), ('ride @e[tag=rideb,limit=1] mount @e[tag=rideb,limit=1]', False),
    ('ride @e[tag=ridea,limit=1] dismount', False), ('ride @s mount @e[tag=ridea,limit=1]', False), ('ride @s mount @e[tag=rideb,limit=1]', False),
    ('ride @s dismount', False), ('ride @s dismount', False), ('!witness ride @s mount @e[tag=rideb,limit=1]', False),
    ('ride @e[type=player] mount @e[tag=ridea,limit=1]', False), ('ride Witness mount @e[tag=rideb,limit=1]', False),
    ('ride ParityProbe mount @e[tag=rideb,limit=1]', False), ('!quiet kill @e[tag=rideb]', False), ('ride Witness dismount', False),
    ('!quiet kill @e[type=!player]', False),
    # /item replace, fill and override with slot names, between players, a mob and a chest.
    ('!quiet fill -3 199 -3 3 199 3 stone', False), ('!quiet tp @a 0 200 0 0 0', False), ('!quiet clear @a', False),
    ('!quiet summon pig 1 200 1 {NoAI:1b,Tags:["itempig"]}', False),
    ('item replace entity @s weapon.mainhand with diamond_sword', False), ('item replace entity @s hotbar.1 with stone 32', False),
    ('item replace entity @s hotbar.1 with stone 65', False), ('item replace entity @s armor.chest with stone', False),
    ('item replace entity @s armor.chest with golden_chestplate', False), ('item replace entity @s armor.head with dirt', False),
    ('item replace entity @s armor.body with stone', False), ('item override entity @s armor.body from entity @s hotbar.8', False),
    ('item replace entity @s container.40 with stone', False), ('item fill entity @s hotbar.* with apple 3', False),
    ('item replace entity @s inventory.* from entity @s hotbar.*', False), ('item override entity @s inventory.* from entity @s hotbar.0', False),
    ('item replace entity @a weapon.offhand with shield', False), ('item replace entity @e[tag=itempig] saddle with saddle', False),
    ('item replace entity @e[tag=itempig] armor.head with carved_pumpkin', False), ('item replace entity @e[tag=itempig] armor.chest with diamond_chestplate', False),
    ('item replace entity @e[tag=itempig] mob.inventory.0 with stone', False), ('item replace entity @e[tag=itempig] weapon from entity @s weapon', False),
    ('item replace entity @e[tag=itempig] weapon from entity @e[tag=itempig] mob.inventory.0', False),
    ('item replace block 0 199 0 container.0 with stone', False), ('!quiet setblock 1 200 -1 chest', False),
    ('item replace block 1 200 -1 container.0 with stone 5', False), ('item replace block 1 200 -1 container.27 with stone', False),
    ('item replace entity @s weapon from block 1 200 -1 container.0', False), ('item replace block 1 200 -1 container.1 from block 0 199 0 container.0', False),
    ('item replace entity @s foo with stone', False), ('item modify entity @s weapon foo:bar', False), ('!witness item replace entity @s weapon with stone', False),
    ('item replace entity @s enderchest.3 with stone 7', False), ('item replace entity @s weapon from entity @s enderchest.3', False),
    ('item replace entity @s player.cursor with stone', False), ('item replace entity @s player.crafting.0 with stone', False),
    # /item modify: inline loot functions, alone or in a list, on the filled slots of the range.
    ('item replace entity @s weapon with diamond_sword', False), ('item modify entity @s weapon {type:"set_count",count:5}', False),
    ('item replace entity @s hotbar.1 with stone 10', False), ('item modify entity @s hotbar.1 {type:"minecraft:set_count",count:3,add:true}', False),
    ('clear @s stone 0', False), ('item modify entity @s hotbar.1 {type:"set_count",count:100}', False), ('clear @s stone 0', False),
    ('item modify entity @s hotbar.1 [{type:"set_count",count:2},{type:"set_count",count:2,add:true}]', False), ('clear @s stone 0', False),
    ('item modify entity @s hotbar.1 {type:"set_count",count:{type:"constant",value:6}}', False), ('clear @s stone 0', False),
    ('item modify entity @s hotbar.1 {type:"set_count",count:2.7}', False), ('clear @s stone 0', False),
    ('item modify entity @s hotbar.1 {type:"limit_count",limit:{min:9}}', False), ('clear @s stone 0', False),
    ('item modify entity @s hotbar.1 {type:"limit_count",limit:{max:4}}', False), ('clear @s stone 0', False),
    ('item modify entity @s hotbar.1 {type:"limit_count",limit:1}', False), ('item modify entity @s weapon {type:"set_damage",damage:0.5}', False),
    ('clear @s diamond_sword[damage=780] 0', False), ('item modify entity @s weapon {type:"set_damage",damage:-0.25,add:true}', False),
    ('clear @s diamond_sword[damage=1171] 0', False), ('item modify entity @s hotbar.1 {type:"set_damage",damage:0.5}', False),
    ('item modify entity @s weapon {type:"set_enchantments",enchantments:{sharpness:3}}', False),
    # Two enchantments would reach trackers in the order of identity-hashed holders, which differs between runs.
    ('item modify entity @s weapon {type:"set_enchantments",enchantments:{sharpness:1},add:true}', False),
    ('clear @s diamond_sword[enchantments={sharpness:4}] 0', False),
    ('item modify entity @s weapon {type:"set_enchantments",enchantments:{sharpness:0}}', False), ('clear @s diamond_sword[enchantments={}] 0', False),
    ('item modify entity @s weapon {type:"set_count",count:0,condition:{type:"random_chance",chance:0}}', False), ('clear @s diamond_sword 0', False),
    ('item modify entity @s weapon {type:"set_count",count:0,condition:{condition:"random_chance",chance:0}}', False),
    ('item modify entity @s weapon {type:"sequence",functions:[{type:"set_item",item:"iron_sword"}]}', False), ('clear @s iron_sword 0', False),
    ('item modify entity @s hotbar.* {type:"set_count",count:2}', False), ('item modify entity @s hotbar.1 {type:"set_item",item:"dirt"}', False),
    ('clear @s dirt 0', False), ('item replace entity @s weapon from entity @s hotbar.1 {type:"set_count",count:7}', False), ('clear @s dirt 0', False),
    ('item replace entity @s hotbar.2 from entity @s hotbar.5 {type:"set_count",count:7}', False),
    ('item modify entity @e[tag=itempig] armor.head {type:"set_count",count:2}', False), ('item modify entity @e[tag=itempig] armor.feet {type:"set_count",count:2}', False),
    ('item modify entity @s hotbar.1 {type:"nope"}', False), ('item modify entity @s hotbar.1 {count:1}', False),
    ('item modify entity @s hotbar.1 {type:"set_count"}', False), ('item modify entity @s hotbar.1 {type:"set_count",count:{min:4,max:4}}', False),
    ('item modify entity @s hotbar.1 {type:"set_item",item:"nope"}', False), ('item modify entity @s hotbar.1 {type:"set_count",count:"x"}', False),
    ('item modify entity @s hotbar.1 [{count:2},{type:"set_count",count:2,add:true},{add:1b}]', False), ('item modify entity @s hotbar.1 minecraft:nope', False),
    ('item modify entity @s hotbar.1 []', False), ('item modify block 1 200 -1 container.* {type:"set_count",count:1}', False),
    ('item modify block 0 199 0 container.0 {type:"set_count",count:4}', False), ('item modify entity @s hotbar.1 {type:"set_count",count:0}', False),
    ('item modify entity @s hotbar.1 {type:"set_count",count:1}', False),
    # An item entity's stack is its one slot; emptied, it is gone the next tick.
    ('!quiet summon item 2.5 201 2.5 {Item:{id:"stone"}}', False), ('item replace entity @e[type=item] contents with diamond 3', False),
    ('item replace entity @e[type=item] container.0 with emerald 2', False), ('item replace entity @e[type=item] container.1 with stone', False),
    ('item replace entity @s weapon from entity @e[type=item,limit=1] contents', False), ('item replace entity @e[type=item] contents with air', False),
    ('item replace entity @e[type=item] contents with stone', False), ('item replace entity @s weapon with air', False),
    ('item replace entity @s weapon with air 5', False),
    ('!quiet clear @a', False), ('!quiet kill @e[type=!player]', False), ('!quiet setblock 1 200 -1 air', False),
    # /data on command storage: paths, every modification and source, HashMap key order, the pretty
    # printer's styling and number formats, and the errors along the way.
    ('data get storage foo:bar', False),
    ('data get storage foo:bar x', False),
    ('data modify storage foo:bar x set value 1', False),
    ('data get storage foo:bar', False),
    ('data get storage foo:bar x', False),
    ('data get storage foo:bar x 2.5', False),
    ('data modify storage foo:bar x set value 1', False),
    ('data modify storage foo:bar list set value [1,2,3]', False),
    ('data modify storage foo:bar list append value 4', False),
    ('data modify storage foo:bar list prepend value 0', False),
    ('data modify storage foo:bar list insert 2 value 9', False),
    ('data get storage foo:bar list', False),
    ('data get storage foo:bar list[0]', False),
    ('data get storage foo:bar list[-1]', False),
    ('data get storage foo:bar list[10]', False),
    ('data modify storage foo:bar list append value "s"', False),
    ('data modify storage foo:bar obj set value {a:1b,b:"x",c:[1.5d],d:{e:2L}}', False),
    ('data get storage foo:bar obj', False),
    ('data get storage foo:bar obj.d.e', False),
    ('data get storage foo:bar obj.c[0]', False),
    ('data get storage foo:bar obj.b', False),
    ('data merge storage foo:bar {obj:{a:5b},z:3}', False),
    ('data get storage foo:bar', False),
    ('data remove storage foo:bar z', False),
    ('data remove storage foo:bar z', False),
    ('data remove storage foo:bar list[0]', False),
    ('data modify storage foo:bar copy set from storage foo:bar obj', False),
    ('data modify storage foo:bar obj.a set from storage foo:bar list[1]', False),
    ('data modify storage foo:bar str set string storage foo:bar obj.b', False),
    ('data modify storage foo:bar str set value "hello world"', False),
    ('data modify storage foo:bar sub set string storage foo:bar str 0 5', False),
    ('data get storage foo:bar sub', False),
    ('data modify storage foo:bar list[] set value 7', False),
    ('data get storage foo:bar list', False),
    ('data get storage foo:bar obj{b:"x"}', False),
    ('data get storage foo:bar list[{a:1}]', False),
    ('data modify storage foo:bar nope.x set from storage foo:bar missing', False),
    ('data get storage foo:bar missing', False),
    ('data get storage foo:bar', False),
    ('data get storage other:x', False),
    ('data merge storage foo:bar {}', False),
    ('data modify storage foo:bar x merge value {a:1}', False),
    ('data modify storage foo:bar obj merge value {q:1}', False),
    ('data get storage foo:bar obj', False),
    ('data remove storage foo:bar list[]', False),
    ('data get storage foo:bar list', False),
    ('data get storage foo:bar "quoted key"', False),
    ('data modify storage foo:bar "quoted key" set value 1.0f', False),
    ('data get storage foo:bar "quoted key"', False),
    ('data get storage foo:bar "quoted key" 10', False),
    ('data modify storage foo:bar big set value 3000000000L', False),
    ('data get storage foo:bar big', False),
    ('data get storage foo:bar big 0.5', False),
    ('data modify storage foo:bar arr set value [I;1,2,3]', False),
    ('data get storage foo:bar arr[1]', False),
    ('data modify storage foo:bar arr append value 4', False),
    ('data modify storage foo:bar arr append value 4b', False),
    ('data get storage foo:bar arr', False),
    ('data merge storage t:a {k1:1,k2:2,k3:3,k4:4,k5:5,k6:6,k7:7,k8:8,k9:9,k10:10,k11:11,k12:12,k13:13,zz:0,Aa:1,BB:2,"a b":3,"x\'y":4,\'q"r\':5}', False),
    ('data get storage t:a', False),
    ('data remove storage t:a k13', False),
    ('data remove storage t:a zz', False),
    ('data get storage t:a', False),
    ('data modify storage t:a copy set from storage t:a', False),
    ('data get storage t:a copy', False),
    ('data modify storage t:f n set value [1.0E10,1.0E-5,0.1f,-0.0d,123456789.0,1.0E7d,0.001,1234567.0f,3.4028235E38f,1.0E-45f,5b,-3s,7L]', False),
    ('data get storage t:f n', False),
    ('data modify storage t:f s set value ["a","b","c"]', False),
    ('data get storage t:f s', False),
    ('data modify storage t:f m set value [{a:1},{b:2},"x",[1,2],[I;1],{}]', False),
    ('data get storage t:f m', False),
    ('data modify storage t:f long set value ["a","b","c","d","e","f","g","h","i"]', False),
    ('data get storage t:f long', False),
    ('data get storage t:f long[]', False),
    ('data get storage t:f long[] 1', False),
    ('data get storage t:f s 1', False),
    ('data get storage t:f m[0] 1', False),
    ('data get storage t:f m[{a:1}]', False),
    ('data get storage t:f m[{a:1}].a', False),
    ('data get storage t:f m[{c:1}]', False),
    ('data get storage t:f {m:[]}', False),
    ('data get storage t:f {m:[{a:1}]}.s', False),
    ('data get storage t:f s[0]x', False),
    ('data get storage t:f s..a', False),
    ('data get storage t:f .s', False),
    ('data get storage t:f s[a]', False),
    ('data get storage t:f s[0', False),
    ('data get storage t:f s{', False),
    ('data modify storage t:f s insert 9 value "z"', False),
    ('data modify storage t:f s insert -9 value "z"', False),
    ('data modify storage t:f s insert -1 value "z"', False),
    ('data get storage t:f s', False),
    ('data modify storage t:f s append from storage t:f long', False),
    ('data modify storage t:f s append from storage t:f long[]', False),
    ('data get storage t:f s', False),
    ('data modify storage t:f n[0] append value 1', False),
    ('data modify storage t:f n merge value {a:1}', False),
    ('data modify storage t:f x merge from storage t:f s', False),
    ('data modify storage t:f str set string storage t:f m', False),
    ('data modify storage t:f str set string storage t:f m[0]', False),
    ('data modify storage t:f str set string storage t:f n[2]', False),
    ('data get storage t:f str', False),
    ('data modify storage t:f str set string storage t:f n[0]', False),
    ('data get storage t:f str', False),
    ('data modify storage t:f str set string storage t:f long[0] 5', False),
    ('data modify storage t:f str set value "abcdef"', False),
    ('data modify storage t:f sub set string storage t:f str -3', False),
    ('data get storage t:f sub', False),
    ('data modify storage t:f sub set string storage t:f str 2 -1', False),
    ('data get storage t:f sub', False),
    ('data modify storage t:f sub set string storage t:f str 4 2', False),
    ('data modify storage t:f sub set string storage t:f str 0 9', False),
    ('data modify storage t:f long[] set value "q"', False),
    ('data get storage t:f long', False),
    ('data modify storage t:f long[] set value "q"', False),
    ('data modify storage t:f long[1] set value "q"', False),
    ('data modify storage t:f long[1] set value "r"', False),
    ('data modify storage t:f m[{a:1}].a set value 5', False),
    ('data get storage t:f m', False),
    ('data modify storage t:f m[{zz:1}].k set value 1', False),
    ('data get storage t:f m', False),
    ('data modify storage t:f new.deep[0].x set value 1', False),
    ('data modify storage t:f new.deep[].x set value 1', False),
    ('data get storage t:f new', False),
    ('data modify storage t:f arr set value [B;1b,2b]', False),
    ('data modify storage t:f arr append value 300', False),
    ('data modify storage t:f arr append value "x"', False),
    ('data get storage t:f arr', False),
    ('data modify storage t:f larr set value [L;1L,2L]', False),
    ('data modify storage t:f larr insert 1 value 5.7', False),
    ('data get storage t:f larr', False),
    ('data remove storage t:f m[{b:2}]', False),
    ('data remove storage t:f m[]', False),
    ('data remove storage t:f m', False),
    ('data remove storage t:f nope.a', False),
    ('data get storage t:f', False),
    ('data merge storage t:f {s:["only"]}', False),
    ('data get storage t:f s', False),
    ("data modify storage t:g a set value 'it''s'", False),
    ('data modify storage t:g b set value "say \\"hi\\""', False),
    ('data modify storage t:g c set value \'both "and\'\' quote\'', False),
    ('data modify storage t:g d set value "tab\\tnew\\nline"', False),
    ('data get storage t:g', False),
    ('data get storage t:g b', False),
    ('data modify storage t:h x set value {}', False),
    ('data get storage t:h', False),
    ('data modify storage t:h x set value []', False),
    ('data get storage t:h', False),
    ('data remove storage t:h x', False),
    ('data get storage t:h', False),
    ('data get storage t:h x 2', False),
    ('data get storage t:f str 1.5', False),
    ('data get storage t:f larr 2', False),
    # /data on players, mobs and block entities: saved fields by path, a player's read-only data,
    # mob names, tags, health and position written back, and container items and names.
    ('!quiet fill -3 199 -3 3 199 3 stone', False),
    ('!quiet tp @a 0 200 0 0 0', False),
    ('!quiet clear @a', False),
    ('data get entity @s Pos', False),
    ('data get entity @s Pos[1]', False),
    ('data get entity @s Rotation', False),
    ('data get entity @s Health', False),
    ('data get entity @s Motion', False),
    ('data get entity @s OnGround', False),
    ('data get entity @s SelectedItemSlot', False),
    ('data get entity @s foodLevel', False),
    ('data get entity @s XpLevel', False),
    ('data get entity @s playerGameType', False),
    ('data get entity @s Dimension', False),
    ('data get entity @s Inventory', False),
    ('data get entity @s abilities', False),
    ('data get entity @s UUID', False),
    ('data get entity @s Health 10', False),
    ('data merge entity @s {Health:5f}', False),
    ('data modify entity @s Health set value 5f', False),
    ('data remove entity @s Health', False),
    ('!quiet summon pig 1 200 1 {NoAI:1b,Tags:["dp"]}', False),
    ('data get entity @e[tag=dp,limit=1] Health', False),
    ('data merge entity @e[tag=dp,limit=1] {CustomName:"Bob"}', False),
    ('data get entity @e[tag=dp,limit=1] CustomName', False),
    ('data modify entity @e[tag=dp,limit=1] Health set value 3f', False),
    ('data get entity @e[tag=dp,limit=1] Health', False),
    ('data get entity @e[type=pig]', False),
    ('data get block 0 199 0', False),
    ('!quiet setblock 1 200 -1 chest', False),
    ('data get block 1 200 -1', False),
    ('data merge block 1 200 -1 {CustomName:"Box"}', False),
    ('data get block 1 200 -1 CustomName', False),
    ('!quiet fill -3 199 -3 3 199 3 stone', False),
    ('!quiet tp @a 0 200 0 0 0', False),
    ('!quiet clear @a', False),
    ('give @s stone 5', False),
    ('give @s diamond_sword[damage=3,enchantments={sharpness:2}]', False),
    ('item replace entity @s weapon.offhand with apple 2', False),
    ('item replace entity @s armor.head with leather_helmet', False),
    ('data get entity @s Inventory', False),
    ('data get entity @s Inventory[0]', False),
    ('data get entity @s SelectedItem', False),
    ('data get entity @s equipment', False),
    ('data get entity @s EnderItems', False),
    ('data get entity @s abilities', False),
    ('data get entity @s Fire', False),
    ('data get entity @s Air', False),
    ('data get entity @s post_effects', False),
    ('data get entity @s HurtTime', False),
    ('data get entity @s foodTickTimer', False),
    ('data get entity @s Score', False),
    ('data get entity @s XpTotal', False),
    ('gamemode survival @s', False),
    ('data get entity @s abilities', False),
    ('data get entity @s playerGameType', False),
    ('gamemode creative @s', False),
    ('!quiet summon cow 2 200 2 {Tags:["dc"],CustomName:{text:"Moo",color:"red"},Silent:1b,NoAI:1b}', False),
    ('data get entity @e[tag=dc,limit=1] CustomName', False),
    ('data get entity @e[tag=dc,limit=1] Silent', False),
    ('data get entity @e[tag=dc,limit=1] Tags', False),
    ('data get entity @e[tag=dc,limit=1] Age', False),
    ('data get entity @e[tag=dc,limit=1] Fire', False),
    ('data merge entity @e[tag=dc,limit=1] {NoAI:1b,Tags:["dc","x"]}', False),
    ('data get entity @e[tag=dc,limit=1] Tags', False),
    ('data get entity @e[tag=dc,limit=1] NoAI', False),
    ('data merge entity @e[tag=dc,limit=1] {Health:2f}', False),
    ('data get entity @e[tag=dc,limit=1] Health', False),
    ('data merge entity @e[tag=dc,limit=1] {Health:2f}', False),
    ('data modify entity @e[tag=dc,limit=1] CustomName set value "Plain"', False),
    ('data get entity @e[tag=dc,limit=1] CustomName', False),
    ('data remove entity @e[tag=dc,limit=1] CustomName', False),
    ('data get entity @e[tag=dc,limit=1] CustomName', False),
    ('data modify entity @e[tag=dc,limit=1] Pos[1] set value 205.0d', False),
    ('data get entity @e[tag=dc,limit=1] Pos', False),
    ('data merge entity @e[tag=dc,limit=1] {UUID:[I;1,2,3,4]}', False),
    ('!quiet setblock 1 200 -1 chest', False),
    ('item replace block 1 200 -1 container.0 with stone 5', False),
    ('item replace block 1 200 -1 container.3 with diamond_sword[damage=2]', False),
    ('data get block 1 200 -1', False),
    ('data get block 1 200 -1 Items', False),
    ('data get block 1 200 -1 Items[0].count', False),
    ('data merge block 1 200 -1 {Items:[]}', False),
    ('data get block 1 200 -1 Items', False),
    ('!quiet setblock 2 200 -1 oak_sign', False),
    ('data get block 2 200 -1', False),
    ('!quiet setblock 3 200 -1 furnace', False),
    ('data get block 3 200 -1', False),
    ('!quiet setblock 1 201 -1 barrel', False),
    ('data get block 1 201 -1', False),
    ('!quiet kill @e[type=!player]', False), ('!quiet clear @a', False),
    # Functions without data packs: unknown functions and tags, the vanilla data pack's empty tags, and schedules of them.
    ('function foo:bar', False), ('function foo', False), ('function #minecraft:tick', False), ('function #foo:bar', False),
    ('function foo:bar {a:1}', False), ('function foo:bar with entity @s', False), ('schedule function foo:bar 1t', False),
    # Schedules report their due game time, which differs between servers, so only clearing them is compared.
    ('schedule function foo:bar 0t', False), ('schedule clear foo:bar', False), ('schedule function #minecraft:load 0t', False),
    ('!quiet schedule function #minecraft:load 2s append', False), ('!quiet schedule function #load 1.5s append', False), ('schedule clear #minecraft:load', False),
    ('schedule clear #minecraft:load', False), ('!quiet schedule function #minecraft:tick 3', False), ('!quiet schedule function #minecraft:tick 1d', False),
    ('schedule clear #minecraft:tick', False), ('schedule clear #tick', False), ('return 5', False), ('return fail', False), ('return run list', False),
    ('reload', False), ('debug stop', False), ('debug start', False), ('debug start', False), ('!quiet debug stop', False),
    ('debug function foo:bar', False), ('!quiet debug function #minecraft:tick', False),
    # /loot: block and entity tables to players, containers, slots and the ground; mining and kill
    # drops name their table. Drops are dirt and gravel, since cobblestone grants an advancement.
    ('!quiet fill -3 199 -3 3 199 3 dirt', False),
    ('!quiet tp @a 0 200 0 0 0', False),
    ('!quiet clear @a', False),
    ('loot give @s loot minecraft:blocks/dirt', False),
    ('loot give @a loot minecraft:blocks/dirt', False),
    ('loot give @s loot minecraft:blocks/nope', False),
    ('loot give @s loot minecraft:blocks/air', False),
    ('loot spawn 3 200 -3 loot minecraft:blocks/dirt', False),
    ('loot spawn 3 200 -3 loot minecraft:blocks/air', False),
    ('loot give @s mine 0 199 0', False),
    ('loot give @s mine 0 199 0 mainhand', False),
    ('loot give @s mine 0 205 0', False),
    ('!quiet setblock 1 200 -1 chest', False),
    ('loot insert 1 200 -1 loot minecraft:blocks/dirt', False),
    ('loot insert 1 200 -1 loot minecraft:blocks/glass', False),
    ('loot insert 0 199 0 loot minecraft:blocks/dirt', False),
    ('loot replace block 1 200 -1 container.5 loot minecraft:blocks/dirt', False),
    ('loot replace block 1 200 -1 container.5 2 loot minecraft:blocks/dirt', False),
    ('loot replace entity @s hotbar.3 loot minecraft:blocks/dirt', False),
    ('loot replace entity @s weapon.offhand loot minecraft:blocks/gravel', False),
    ('loot replace entity @s armor.head loot minecraft:blocks/dirt', False),
    ('loot give @s kill @s', False),
    ('loot give @s fish minecraft:blocks/dirt 0 199 0', False),
    ('loot give @s loot minecraft:entities/sheep/white', False),
    ('loot give @s loot blocks/dirt', False),
    ('loot insert 1 200 -1 loot minecraft:blocks/dirt', False),
    ('loot give @s loot minecraft:blocks/glass', False),
    ('clear @s dirt 0', False),
    ('clear @s white_wool 0', False),
    ('!quiet kill @e[type=item]', False),
    ('!quiet clear @a', False),
    # /spreadplayers: centers, ranges, heights, teams and failures. The spots are random, so only
    # replies that do not depend on them (one spot, or none found) are compared.
    ('!quiet tp @a 0 200 0 0 0', False),
    ('spreadplayers 0 0 1 10 false @s', False),
    ('spreadplayers 0 0 0 1 false @s', False),
    ('spreadplayers 0.25 0.75 1 10 false @s', False),
    ('spreadplayers 0 0 1 10 true @s', False),
    ('spreadplayers 0 0 1 10 true @a', False),
    ('spreadplayers 0 0 1 10 under -100 false @s', False),
    ('spreadplayers 0 0 1 10 under -64 false @s', False),
    ('spreadplayers 0 0 1 10 under 50 false @s', False),
    ('spreadplayers 0 0 1 10 under 200 false @s', False),
    ('spreadplayers 0 0 1000 1 false @a', False),
    ('spreadplayers 0 0 -1 10 false @s', False),
    ('spreadplayers 0 0 1 0.5 false @s', False),
    ('spreadplayers 0 0 1 10 false @e[type=cow]', False),
    ('!quiet tp @s 3 200 3', False),
    ('spreadplayers ~ ~ 1 10 false @s', False),
    ('spreadplayers 0 0 1 10 maybe @s', False),
    ('!quiet tp @a 0 200 0 0 0', False),
    # /fillbiome: quart rounding, filters by biome and tag, a section's palette growing from one
    # biome past three bits, fills across chunks, and failures, with each player's chunks_biomes.
    ('!quiet tp @a 0 200 0 0 0', False),
    ('fillbiome 0 200 0 3 203 3 desert', False),
    ('fillbiome 0 200 0 3 203 3 minecraft:desert', False),
    ('fillbiome 1 201 1 6 206 6 badlands', False),
    ('fillbiome 0 200 0 15 215 15 snowy_plains replace desert', False),
    ('fillbiome 0 200 0 15 215 15 forest replace #minecraft:is_badlands', False),
    ('fillbiome 0 200 0 15 215 15 forest replace #is_forest', False),
    ('fillbiome 8 208 8 8 208 8 ocean', False),
    ('fillbiome 12 212 12 12 212 12 river', False),
    ('fillbiome 4 216 0 4 216 0 taiga', False),
    ('fillbiome 0 220 4 0 220 4 swamp', False),
    ('fillbiome 12 208 0 12 208 0 jungle', False),
    ('fillbiome 0 208 12 0 208 12 savanna', False),
    ('fillbiome 4 212 4 4 212 4 beach', False),
    ('fillbiome 8 220 12 8 220 12 desert', False),
    ('fillbiome 0 208 0 15 223 15 plains replace ocean', False),
    ('fillbiome ~-20 ~ ~-20 ~20 ~7 ~20 jungle', False),
    ('fillbiome -20 200 -20 20 207 20 cherry_grove replace jungle', False),
    ('fillbiome 0 200 0 3 203 3 nope', False),
    ('fillbiome 0 200 0 3 203 3 desert replace nope', False),
    ('fillbiome 0 200 0 3 203 3 desert replace #nope', False),
    ('fillbiome 0 200 0 3 203 3 desert keep', False),
    ('fillbiome 0 100 0 100 200 100 desert', False),
    ('fillbiome 0 -100 0 3 203 3 desert', False),
    ('fillbiome 0 200 0 3 400 3 desert', False),
    ('fillbiome 1000 200 1000 1003 203 1003 desert', False),
    ('fillbiome 0 200 0 3 203 3 plains', False),
    ('!witness fillbiome 0 200 0 3 203 3 plains', False),
    # /place: templates turned, mirrored, partly placed by integrity and seed, with and without
    # shape updates, and failures; features placed inline and by ID where the outcome is certain.
    ('place template minecraft:fossil/skull_1 0 200 0', False),
    ('place template fossil/skull_1 10 200 0 clockwise_90', False),
    ('place template fossil/skull_1 20 200 0 180', False),
    ('place template fossil/skull_1 30 200 0 counterclockwise_90', False),
    ('place template fossil/skull_1 0 210 10 none front_back', False),
    ('place template fossil/skull_1 10 210 10 none left_right', False),
    ('place template fossil/skull_1 20 210 10 clockwise_90 left_right', False),
    ('place template fossil/skull_1 0 220 20 none none 0.5 7', False),
    ('place template fossil/skull_1 10 220 20 none none 0.5 8', False),
    ('place template fossil/skull_1 20 220 20 none none 0 8', False),
    ('place template fossil/skull_1 0 200 0', False),
    ('place template minecraft:nope 0 200 0', False),
    ('place template igloo/top 20 200 30', False),
    ('place template ruined_portal/portal_1 0 200 40 none none 1 0 strict', False),
    ('place template empty 0 230 0', False),
    ('place template fossil/skull_1 5000 200 0', False),
    ('!witness place template fossil/skull_1 0 200 0', False),
    ('place template fossil/skull_1 30 220 30 clockwise_90 front_back 1 5 strict', False),
    ('place feature minecraft:nope 0 240 0', False),
    ('place feature {type:"minecraft:simple_block",to_place:{id:"minecraft:stone"}} 0 240 0', False),
    ('place feature {type:"minecraft:simple_block",to_place:{id:"minecraft:stone"}} 0 240 0', False),
    ('place feature minecraft:oak 5000 240 10', False),
    # /waypoint: listing transmitters and changing icons. A HashSet orders several transmitters, so
    # each list holds one: the other player stops transmitting first.
    ('!quiet fill -3 199 -3 3 199 3 stone', False), ('!quiet tp @a 0 200 0 0 0', False),
    ('!quiet attribute Witness minecraft:waypoint_transmit_range base set 0', False), ('waypoint list', False),
    ('waypoint modify @s color red', False), ('waypoint list', False), ('waypoint modify @s color hex 12AB34', False), ('waypoint list', False),
    ('waypoint modify @s color black', False), ('waypoint modify @s style set minecraft:bowtie', False), ('waypoint modify @s style set nope', False),
    ('waypoint modify @s style reset', False), ('waypoint modify @s color reset', False), ('waypoint list', False),
    ('waypoint modify @e[type=item,limit=1] color red', False), ('waypoint modify @a color red', False),
    ('!quiet summon pig 3 200 3 {NoAI:1b,Tags:["wp"]}', False), ('waypoint modify @e[tag=wp,limit=1] color blue', False), ('!quiet kill @e[tag=wp]', False),
    ('!quiet attribute Witness minecraft:waypoint_transmit_range base reset', False),
    ('!quiet attribute @s minecraft:waypoint_transmit_range base set 0', False), ('waypoint list', False),
    ('!quiet attribute @s minecraft:waypoint_transmit_range base reset', False),
    ('execute in minecraft:the_nether run waypoint list', False), ('!witness waypoint list', False),
    # /datapack: listing, enabling at each position, disabling, feature packs and creating packs.
    # Vanilla is disabled only briefly and comes back first, so the rest of the script keeps its data.
    ('datapack list', False), ('datapack list available', False), ('datapack list enabled', False),
    ('datapack enable vanilla', False), ('datapack disable vanilla', False), ('datapack list enabled', False),
    ('datapack enable vanilla', False), ('datapack list', False),
    ('datapack enable minecart_improvements', False), ('datapack disable minecart_improvements', False),
    ('datapack enable trade_rebalance', False), ('datapack enable nope', False), ('datapack disable nope', False),
    ('datapack create foo "My pack"', False), ('datapack create foo "My pack"', False), ('datapack create "a/b" x', False),
    ('datapack create "con" x', False), ('datapack create "Con.txt" x', False), ('datapack create "x." x', False),
    ('datapack create "" x', False), ('datapack create Foo x', False), ('datapack create bar {text:"Red",color:"red"}', False),
    ('datapack create "my pack" x', False), ('datapack list available', False),
    ('datapack enable "file/foo"', False), ('datapack enable "file/foo"', False), ('datapack list enabled', False),
    ('datapack disable "file/foo"', False), ('datapack enable "file/foo" first', False), ('datapack list', False),
    ('datapack enable "file/bar" before "file/foo"', False), ('datapack list enabled', False),
    ('datapack disable "file/bar"', False), ('datapack enable "file/bar" after vanilla', False), ('datapack list enabled', False),
    ('datapack disable "file/bar"', False), ('datapack enable "file/bar" before nope', False), ('datapack enable "file/bar" after "file/bar"', False),
    ('datapack enable "file/bar" last', False), ('datapack list enabled', False),
    ('datapack disable vanilla', False), ('datapack enable vanilla', False), ('datapack list enabled', False),
    ('datapack disable "file/bar"', False), ('datapack disable "file/foo"', False), ('datapack enable file/foo', False),
    ('!witness datapack list', False), ('datapack list', False),
    # /fetchprofile: players' own profiles with their copy, head, mannequin and sprite links, and
    # failures. Name and UUID lookups reply off the tick once the services answer, so only their
    # syntax is compared.
    ('fetchprofile entity @s', False), ('fetchprofile entity Witness', False),
    ('!quiet summon pig 3 200 3 {NoAI:1b,Tags:["fp"],CustomName:"Bob"}', False), ('fetchprofile entity @e[tag=fp,limit=1]', False),
    ('!quiet kill @e[tag=fp]', False), ('fetchprofile entity @e[type=pig]', False), ('fetchprofile id nope', False),
    ('fetchprofile id 1-2-3', False), ('fetchprofile name', False), ('fetchprofile entity', False), ('!witness fetchprofile entity @s', False),
    # /perf and /jfr: starting and stopping, and failures. A recording's timings and report name
    # vary, so a stopped recording is not compared; Flight Recorder needs a JVM, so /jfr fails as
    # vanilla does when no recording can start.
    ('perf stop', False), ('!quiet perf start', False), ('perf start', False), ('!quiet perf stop', False), ('perf stop', False),
    ('jfr stop', False), ('perf', False), ('!witness perf start', False), ('!witness jfr start', False),
    ('list', False),
]


def collect(client, since, command=''):
    extra = next((packets for prefix, packets in EXTRA.items() if command.removeprefix('!witness ').startswith(prefix)), ())
    # Rain starting, stopping or fading follows each server's random weather, not the command.
    weather = lambda name, payload: name == 'game_event' and payload[0] in (1, 2, 7, 8) and not command.startswith('weather')
    return [{'packet': name, 'hex': payload.hex()} for name, payload in client.seen[since:]
            if (name in COMPARED or name in extra) and not weather(name, payload) and not surface(name, payload)]


def surface(name, payload):
    """Block changes below y 100 come from random ticks on the flat surface; the commands edit higher."""
    if name == 'section_blocks_update':
        section = ((struct.unpack_from('>q', payload)[0] & 0xfffff) ^ 0x80000) - 0x80000
        return section * 16 < 100
    if name in ('block_update', 'block_entity_data'):
        return ((struct.unpack_from('>q', payload)[0] & 0xfff) ^ 0x800) - 0x800 < 100
    return False


# Packets compared for particular commands only; elsewhere they carry random hurt-sound pitches and the like.
# Block changes are compared for the commands that edit blocks; fluids and random ticks change others.
BLOCKS = ('block_update', 'section_blocks_update', 'block_entity_data')
EXTRA = {'setblock ': BLOCKS, 'fill ': BLOCKS, 'clone ': BLOCKS, 'tp ': ('player_position',), 'teleport ': ('player_position',), 'item ': ('set_equipment',), 'ride': ('set_passengers', 'entity_position_sync', 'player_position'), 'damage Witness 3 minecraft:mob_attack by ParityProbe': ('damage_event', 'hurt_animation', 'set_entity_motion'), 'damage Witness 2 minecraft:mob_attack by ParityProbe': ('damage_event', 'player_combat_enter', 'player_combat_end'), 'damage Witness 40 minecraft:mob_attack by ParityProbe': ('damage_event', 'player_combat_enter', 'player_combat_end'), 'summon pig 0.5 106': ('damage_event',), 'summon chicken 0.5 106': ('damage_event',), 'tp @a[name=Witness]': ('update_attributes',), 'attribute': ('update_attributes',), 'rotate': ('player_rotation', 'player_look_at'), 'dialog': ('show_dialog', 'clear_dialog'), 'execute': ('disguised_chat',), 'playsound': ('sound',), 'stopsound': ('stop_sound',), 'gamerule': ('game_event', 'entity_event'), 'damage ': ('damage_event',), 'bossbar': ('boss_event',), 'say ': ('disguised_chat',), 'me ': ('disguised_chat',), 'msg ': ('disguised_chat',), 'tell ': ('disguised_chat',), 'w ': ('disguised_chat',), 'teammsg ': ('disguised_chat',), 'tm ': ('disguised_chat',), 'particle': ('level_particles',), 'spectate': ('set_camera',), 'gamemode creative @s': ('set_camera',), 'tp @s 3 200 3': ('set_camera',), 'swing': ('swing_animation',), 'tick ': ('ticking_state', 'ticking_step'), 'transfer': ('transfer',), 'posteffect': ('post_effects',), 'fillbiome': ('chunks_biomes',), 'place ': BLOCKS}

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
ENTITY_ADDRESSED = ('update_mob_effect', 'remove_mob_effect', 'update_attributes', 'damage_event', 'set_camera', 'swing_animation',
                    'hurt_animation', 'set_entity_motion', 'set_equipment')


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


def normalize_boss_bars(items):
    # Each server gives a boss bar a random UUID; the operation and its data must match.
    return [{**item, 'hex': '00' * 16 + item['hex'][32:]} if item['packet'] == 'boss_event' else item for item in items]


def normalize_riding(items):
    """Zero the entity IDs in set_passengers and entity_position_sync, and drop syncs of mobs off the test platform.

    Vanilla's naturally spawned animals wander far below the platform at y=200.
    """
    def varint(data, at):
        value = shift = 0
        while True:
            byte = data[at]
            at += 1
            value |= (byte & 0x7f) << shift
            shift += 7
            if byte < 0x80:
                return value, at
    def passengers(payload):
        data = bytes.fromhex(payload)
        _, at = varint(data, 0)
        count, at = varint(data, at)
        return '00' + bytes([count]).hex() + '00' * count
    def sync(payload):
        data = bytes.fromhex(payload)
        _, at = varint(data, 0)
        return '00' + data[at:].hex()
    def far(payload):
        # The ID, then a PositionPath: a linear end point, or a step count and steps.
        data = bytes.fromhex(payload)
        _, at = varint(data, 0)
        if data[at] == 1:
            _, at = varint(data, at + 1)
        else:
            at += 1
        return struct.unpack('>d', data[at + 8:at + 16])[0] < 150
    return [{**item, 'hex': passengers(item['hex'])} if item['packet'] == 'set_passengers'
            else {**item, 'hex': sync(item['hex'])} if item['packet'] == 'entity_position_sync'
            else item for item in items if not (item['packet'] == 'entity_position_sync' and far(item['hex']))]


def compare(reference, php):
    differences = []
    for expected, actual in zip(reference, php):
        # Vanilla's kick report and leave message race the network thread closing the channel.
        # ServerChunkCache broadcasts changed chunks from an identity hash set, so a placement
        # spanning several chunks sends their updates in an order that varies between runs.
        unordered = expected['command'].startswith(('kick ', 'place '))
        # A summoned entity has a random UUID on each server.
        random_ids = expected['command'].startswith(('summon ', 'ride ')) or ' summon ' in expected['command'] or '@e' in expected['command'] or '@n' in expected['command']
        for role in ('operator', 'witness'):
            key = (lambda items: sorted(item['hex'] for item in items)) if unordered else (normalize_uuids if random_ids else (lambda items: items))
            if key(normalize_riding(normalize_boss_bars(normalize_seeds(normalize_attribute_order(normalize_entity_ids(expected[role])))))) != key(normalize_riding(normalize_boss_bars(normalize_seeds(normalize_attribute_order(normalize_entity_ids(actual[role])))))):
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
