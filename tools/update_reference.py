#!/usr/bin/env python3
"""Rebuild protocol-777 reference data using Mojang's checksum-pinned 26.3 JAR.
Requires a Java 25+ JDK and Python 3.11+. Does not start a vanilla game server.
"""
import argparse
import gzip
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import urllib.request
import zipfile
from java_logging import plain_reference_logging
from nbt import Nbt
from worldgen_reference import export_worldgen

ROOT = Path(__file__).resolve().parents[1]
VERSION = '26.3'
PROTOCOL = 777
DATA_VERSION = 5023
SERVER_SHA1 = '33680f5f2ac32864d6d7cf5e56a705fdb3e05f4c'
VERSION_URL = 'https://piston-meta.mojang.com/v1/packages/61fff1316bd50312624a858bbb5eb18fa45208ed/26.3.json'
VERSION_SHA1 = '61fff1316bd50312624a858bbb5eb18fa45208ed'


def digest(path, algorithm):
    with path.open('rb') as stream:
        return hashlib.file_digest(stream, algorithm).hexdigest()


def download(url, path, sha1):
    if not path.exists() or digest(path, 'sha1') != sha1:
        temporary = path.with_suffix('.download')
        urllib.request.urlretrieve(url, temporary)
        if digest(temporary, 'sha1') != sha1:
            temporary.unlink()
            raise RuntimeError('Checksum mismatch for ' + url)
        temporary.replace(path)
    return path


def write_json(path, value):
    path.write_text(json.dumps(value, indent=2, ensure_ascii=False) + '\n')


# Fixed chunks for tests/vanilla-worldgen-unit.php: biomes and terrain stages of both seeds.
WORLDGEN_FIXTURE_CHUNKS = {
    '42': ['0:0', '-1:-1', '-160:64', '-152:-160', '144:-160', '-80:-104', '1874999:-1874999', '-20000:15000'],
    '-4172144997902289642': ['0:0', '7:-3', '-300:250'],
}
# The same stages of Nether chunks: crimson and warped forests, nether wastes and a soul sand valley.
WORLDGEN_NETHER_FIXTURE_CHUNKS = {'42': ['0:0', '-1:-1', '5:3', '-20:14']}
WORLDGEN_END_FIXTURE_CHUNKS = {'42': ['0:0', '-1:-1', '3:2', '80:10', '-70:40', '100:-100']}


def worldgen_resources(inner, out):
    """Copy the official Overworld worldgen registries the native generator reads at runtime."""
    folders = ('density_function', 'noise', 'noise_settings', 'material_rule', 'material_condition', 'carver', 'biome',
               'placed_feature', 'feature', 'block_state_provider', 'processor_list', 'structure', 'structure_set',
               'template_pool')
    target = out / 'worldgen'
    for folder in (*folders, 'tags/biome', 'tags/structure', 'tags/feature'):
        shutil.rmtree(target / folder, ignore_errors=True)
    with zipfile.ZipFile(inner) as archive:
        for name in sorted(archive.namelist()):
            prefix = 'data/minecraft/worldgen/'
            if name.startswith(prefix) and name.endswith('.json') and name[len(prefix):].split('/')[0] in folders:
                destination = target / name[len(prefix):]
                destination.parent.mkdir(parents=True, exist_ok=True)
                destination.write_bytes(archive.read(name))
            # Biome tags, which structures use to choose where they can start, structure tags,
            # which /locate structure accepts, and feature tags (bone meal's flowers).
            for kind in ('biome', 'structure', 'feature'):
                tags = f'data/minecraft/tags/worldgen/{kind}/'
                if name.startswith(tags) and name.endswith('.json'):
                    destination = target / 'tags' / kind / name[len(tags):]
                    destination.parent.mkdir(parents=True, exist_ok=True)
                    destination.write_bytes(archive.read(name))


# Structure templates the generator places, converted from NBT to gzipped JSON.
def structure_templates(inner, out):
    """Templates as gzipped JSON: size, block state palettes, blocks (x, y, z, palette index and any
    block entity NBT) and entities."""
    target = out / 'worldgen' / 'template'
    shutil.rmtree(target, ignore_errors=True)
    state = lambda entry: {'id': entry.get('id', entry.get('Name')), 'properties': entry.get('properties', entry.get('Properties', {}))}
    with zipfile.ZipFile(inner) as archive:
        for name in sorted(archive.namelist()):
            prefix = 'data/minecraft/structure/'
            if not name.startswith(prefix) or not name.endswith('.nbt'):
                continue
            nbt = Nbt(gzip.decompress(archive.read(name))).root()
            palettes = nbt['palettes'] if 'palettes' in nbt else [nbt.get('palette', [])]
            template = {
                'size': nbt['size'],
                'palettes': [[state(entry) for entry in palette] for palette in palettes],
                'blocks': [block['pos'] + [block['state']] + ([block['nbt']] if 'nbt' in block else []) for block in nbt.get('blocks', [])],
                'entities': nbt.get('entities', []),
            }
            destination = target / (name[len(prefix):-4] + '.json.gz')
            destination.parent.mkdir(parents=True, exist_ok=True)
            destination.write_bytes(gzip.compress(json.dumps(template, separators=(',', ':'), sort_keys=True).encode(), 9, mtime=0))


def damage_types(inner):
    """Vanilla damage types (message IDs, death message types, scaling) and their tags, for death messages."""
    types, tags = {}, {}
    with zipfile.ZipFile(inner) as archive:
        for name in sorted(archive.namelist()):
            if name.startswith('data/minecraft/damage_type/') and name.endswith('.json'):
                types['minecraft:' + Path(name).stem] = json.loads(archive.read(name))
            elif name.startswith('data/minecraft/tags/damage_type/') and name.endswith('.json'):
                tags['minecraft:' + name[len('data/minecraft/tags/damage_type/'):-5]] = json.loads(archive.read(name))['values']
    # Resolve nested tag references (#minecraft:...) into plain damage type lists.
    def members(tag, seen=()):
        result = []
        for value in tags[tag]:
            entry = value['id'] if isinstance(value, dict) else value
            result += members(entry[1:], seen + (tag,)) if entry.startswith('#') else [entry]
        return result
    return {'types': types, 'tags': {tag: sorted(set(members(tag))) for tag in sorted(tags)}}


def timelines(inner):
    """Vanilla timelines' clocks, periods and time markers, used by /time."""
    result = {}
    with zipfile.ZipFile(inner) as archive:
        for name in sorted(archive.namelist()):
            if name.startswith('data/minecraft/timeline/') and name.endswith('.json'):
                data = json.loads(archive.read(name))
                # A marker is either a tick count or an object with ticks and show_in_commands.
                markers = {key: value if isinstance(value, dict) else {'ticks': value}
                           for key, value in data.get('time_markers', {}).items()}
                result['minecraft:' + Path(name).stem] = {'clock': data.get('clock'), 'period_ticks': data.get('period_ticks'), 'time_markers': markers}
    return result


def loot(inner):
    """Vanilla block and entity loot tables and the named predicates they reference, evaluated by World\\Loot."""
    tables, predicates = {}, {}
    with zipfile.ZipFile(inner) as archive:
        for name in sorted(archive.namelist()):
            if name.startswith(('data/minecraft/loot_table/blocks/', 'data/minecraft/loot_table/entities/')) and name.endswith('.json'):
                tables['minecraft:' + name[len('data/minecraft/loot_table/'):-5]] = json.loads(archive.read(name))
            elif name.startswith('data/minecraft/predicate/') and name.endswith('.json'):
                predicates['minecraft:' + name[len('data/minecraft/predicate/'):-5]] = json.loads(archive.read(name))
    return {'tables': tables, 'predicates': predicates}


def enchantments(inner):
    """Vanilla enchantment definitions without their effects, used by /enchant."""
    result = {}
    with zipfile.ZipFile(inner) as archive:
        for name in sorted(archive.namelist()):
            if name.startswith('data/minecraft/enchantment/') and name.endswith('.json'):
                data = json.loads(archive.read(name))
                result['minecraft:' + Path(name).stem] = {key: data[key] for key in ('description', 'supported_items', 'exclusive_set', 'max_level') if key in data}
    return result


def english_names(inner):
    """The en_us names of items, blocks and entities, which some command errors print as plain text."""
    with zipfile.ZipFile(inner) as archive:
        lang = json.loads(archive.read('assets/minecraft/lang/en_us.json'))
    return {key: value for key, value in sorted(lang.items()) if key.startswith(('item.minecraft.', 'block.minecraft.', 'entity.minecraft.'))}


def generate_tags(inner, registries, dynamic):
    tables = {name: {key: value['protocol_id'] for key, value in registry['entries'].items()}
              for name, registry in registries.items()}
    tables.update({name: {key: i for i, key in enumerate(entries)} for name, entries in dynamic.items()})
    tags = {}
    with zipfile.ZipFile(inner) as archive:
        for name in archive.namelist():
            if not name.startswith('data/minecraft/tags/') or not name.endswith('.json'):
                continue
            relative = name[len('data/minecraft/tags/'):-5]
            registry = next((key for key in sorted(tables, key=len, reverse=True) if relative.startswith(key[10:] + '/')), None)
            if registry:
                tag_name = 'minecraft:' + relative[len(registry[10:]) + 1:]
                tags.setdefault(registry, {})[tag_name] = json.loads(archive.read(name))['values']
    resolved = {}
    def resolve(registry, name, visiting=frozenset()):
        key = (registry, name)
        if key in resolved:
            return resolved[key]
        if key in visiting:
            raise ValueError('Cyclic tag ' + str(key))
        ids = []
        for entry in tags[registry][name]:
            value = entry['id'] if isinstance(entry, dict) else entry
            required = entry.get('required', True) if isinstance(entry, dict) else True
            if value.startswith('#'):
                if value[1:] not in tags[registry]:
                    if required: raise ValueError('Missing tag ' + value)
                    continue
                ids.extend(resolve(registry, value[1:], visiting | {key}))
            else:
                if value not in tables[registry]:
                    if required: raise ValueError('Missing tag element ' + value + ' in ' + registry)
                    continue
                ids.append(tables[registry][value])
        resolved[key] = list(dict.fromkeys(ids))
        return resolved[key]
    def vi(n):
        out = bytearray()
        while n > 127:
            out.append((n & 127) | 128); n >>= 7
        return bytes(out + bytes([n]))
    def string(value):
        data = value.encode('utf-8'); return vi(len(data)) + data
    out = vi(len(tags))
    for registry, definitions in sorted(tags.items()):
        out += string(registry) + vi(len(definitions))
        for name in sorted(definitions):
            ids = resolve(registry, name)
            out += string(name) + vi(len(ids)) + b''.join(vi(i) for i in ids)
    return out


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--capture', type=Path, help='Additionally decode captured PHP packets with official codecs')
    args = parser.parse_args()
    capture = args.capture.resolve() if args.capture else None
    cache = ROOT / 'tools/.cache' / VERSION
    cache.mkdir(parents=True, exist_ok=True)
    out = ROOT / 'reference' / VERSION
    out.mkdir(parents=True, exist_ok=True)
    java_home = Path(os.environ['JAVA_HOME']) / 'bin' if os.environ.get('JAVA_HOME') else None
    java = str(java_home / 'java') if java_home else shutil.which('java')
    javac = str(java_home / 'javac') if java_home else shutil.which('javac')
    if not java or not javac:
        raise SystemExit('Java 25+ JDK required; set JAVA_HOME to its installation directory.')
    metadata = download(VERSION_URL, cache / 'version.json', VERSION_SHA1)
    meta = json.loads(metadata.read_text())
    server = meta['downloads']['server']
    if meta['id'] != VERSION or server['sha1'] != SERVER_SHA1:
        raise RuntimeError('Unexpected Mojang version metadata')
    jar = download(server['url'], cache / 'server.jar', SERVER_SHA1)
    logging_option=plain_reference_logging(jar,cache/'log4j2-plain.xml')
    cp_dir = cache / 'classpath'
    cp_dir.mkdir(exist_ok=True)
    classpath = []
    with zipfile.ZipFile(jar) as archive:
        for name in archive.namelist():
            if name.endswith('.jar') and name.startswith(('META-INF/libraries/', 'META-INF/versions/')):
                destination = cp_dir / Path(name).name
                destination.write_bytes(archive.read(name))
                classpath.append(destination)
    inner = cp_dir / ('server-' + VERSION + '.jar')
    with zipfile.ZipFile(inner) as archive:
        actual = json.loads(archive.read('version.json'))
    if (actual['id'], actual['protocol_version'], actual['world_version']) != (VERSION, PROTOCOL, DATA_VERSION):
        raise RuntimeError('JAR version does not match pinned protocol')
    cp = os.pathsep.join(str(path) for path in sorted(classpath))
    generated = cache / 'generated'
    subprocess.run([java, logging_option, '-cp', cp, 'net.minecraft.data.Main', '--reports', '--output', str(generated)], check=True, cwd=cache)
    classes = cache / 'exporter'
    classes.mkdir(exist_ok=True)
    reference_tools = [
        'ExportReference.java', 'ExportAuthentication.java', 'ExportWorldgenNoise.java',
        'ExportWorldgenDensity.java', 'ExportMobCombat.java', 'ExportMobSounds.java',
        'ExportCommands.java', 'ExportMobPhysics.java', 'ExportMobTracking.java',
        'ReferenceWorldData.java', 'ExportMobInitialization.java', 'ExportZombieSpawnGroups.java',
        'ExportMobEquipment.java', 'ExportEnchanting.java', 'ExportEnchantmentComponents.java',
        'ExportEquipmentStacks.java', 'VerifyEquipmentStacks.java',
        'ExportEntityPushing.java', 'ExportEntitySectionQueries.java', 'ExportPushEligibility.java',
        'ExportDisplayNames.java', 'ExportGameRules.java', 'ExportMobEffects.java', 'ExportConsumables.java',
        'ExportBiomeParameters.java', 'ExportFeatureOrder.java', 'ExportStructureOrder.java', 'ExportWorldgenChunks.java', 'ExportAttributes.java', 'ExportMining.java', 'ExportExperience.java', 'ExportItemEntities.java',
    ]
    subprocess.run([javac, '-cp', cp, '-d', str(classes),
                    *(str(ROOT / 'tools' / name) for name in reference_tools)], check=True)
    cmd = [java, logging_option, '-cp', str(classes) + os.pathsep + cp, 'ExportReference', str(out)]
    subprocess.run(cmd, check=True, cwd=cache)
    subprocess.run([java, logging_option, '-cp', str(classes) + os.pathsep + cp, 'ExportAuthentication', str(out / 'authentication.json')], check=True, cwd=cache)
    export_worldgen(java,cp,classes,out/'worldgen-noise.json',jar)
    subprocess.run([java, logging_option, '-cp', str(classes) + os.pathsep + cp, 'ExportWorldgenDensity', str(out / 'worldgen-density.json')], check=True, cwd=cache)
    subprocess.run([java, logging_option, '-cp', str(classes) + os.pathsep + cp, 'ExportMobCombat', str(out / 'combat.json')], check=True, cwd=cache)
    subprocess.run([java, logging_option, '-cp', str(classes) + os.pathsep + cp, 'ExportMobSounds', str(ROOT / 'tools/mob-classes.json'), str(out / 'mob-sounds.json')], check=True, cwd=cache)
    subprocess.run([java, logging_option, '-cp', str(classes) + os.pathsep + cp, 'ExportCommands', str(out / 'commands.json')], check=True, cwd=cache)
    subprocess.run([java, logging_option, '-cp', str(classes) + os.pathsep + cp, 'ExportCommands', str(out / 'commands-all-features.json'), '--all-features'], check=True, cwd=cache)
    subprocess.run([java, logging_option, '-cp', str(classes) + os.pathsep + cp, 'ExportMobPhysics', str(out / 'mob-physics.json'), str(ROOT / 'tools/mob-classes.json')], check=True, cwd=cache)
    subprocess.run([java, logging_option, '-cp', str(classes) + os.pathsep + cp, 'ExportMobTracking', str(out / 'mob-tracking.json')], check=True, cwd=cache)
    subprocess.run([java, logging_option, '-cp', str(classes) + os.pathsep + cp, 'ExportMobInitialization', str(out / 'mob-initialization.json')], check=True, cwd=cache)
    subprocess.run([java, logging_option, '-cp', str(classes) + os.pathsep + cp, 'ExportZombieSpawnGroups', str(out / 'zombie-spawn-groups.json')], check=True, cwd=cache)
    subprocess.run([java, logging_option, '-cp', str(classes) + os.pathsep + cp, 'ExportMobEquipment', str(out / 'mob-equipment.json')], check=True, cwd=cache)
    subprocess.run([java, logging_option, '-cp', str(classes) + os.pathsep + cp, 'ExportEnchanting', str(out / 'enchanting.json')], check=True, cwd=cache)
    subprocess.run([java, logging_option, '-cp', str(classes) + os.pathsep + cp, 'ExportEnchantmentComponents', str(out / 'enchantment-components.json')], check=True, cwd=cache)
    subprocess.run([java, logging_option, '-cp', str(classes) + os.pathsep + cp, 'ExportEquipmentStacks', str(out / 'equipment-stacks.json')], check=True, cwd=cache)
    subprocess.run([java, logging_option, '-cp', str(classes) + os.pathsep + cp, 'ExportEntityPushing', str(out / 'entity-pushing.json'), str(ROOT / 'tools/mob-classes.json')], check=True, cwd=cache)
    subprocess.run([java, logging_option, '-cp', str(classes) + os.pathsep + cp, 'ExportEntitySectionQueries', str(out / 'entity-section-queries.json')], check=True, cwd=cache)
    # Also writes the sibling push-eligibility-rules.json used at runtime.
    subprocess.run([java, logging_option, '-cp', str(classes) + os.pathsep + cp, 'ExportPushEligibility', str(out / 'push-eligibility.json')], check=True, cwd=cache)
    subprocess.run([java, logging_option, '-cp', str(classes) + os.pathsep + cp, 'ExportDisplayNames', str(out / 'display-names.json')], check=True, cwd=cache)
    subprocess.run([java, logging_option, '-cp', str(classes) + os.pathsep + cp, 'ExportGameRules', str(out / 'gamerules.json')], check=True, cwd=cache)
    subprocess.run([java, logging_option, '-cp', str(classes) + os.pathsep + cp, 'ExportMobEffects', str(out / 'mob-effects.json')], check=True, cwd=cache)
    subprocess.run([java, logging_option, '-cp', str(classes) + os.pathsep + cp, 'ExportConsumables', str(out / 'consumables.json')], check=True, cwd=cache)
    subprocess.run([java, logging_option, '-cp', str(classes) + os.pathsep + cp, 'ExportAttributes', str(out / 'attributes.json')], check=True, cwd=cache)
    subprocess.run([java, logging_option, '-cp', str(classes) + os.pathsep + cp, 'ExportMining', str(out / 'mining.json')], check=True, cwd=cache)
    subprocess.run([java, logging_option, '-cp', str(classes) + os.pathsep + cp, 'ExportExperience', str(out / 'experience.json')], check=True, cwd=cache)
    subprocess.run([java, logging_option, '-cp', str(classes) + os.pathsep + cp, 'ExportItemEntities', str(out / 'item-entities.json')], check=True, cwd=cache)
    worldgen_resources(inner, out)
    structure_templates(inner, out)
    subprocess.run([java, logging_option, '-cp', str(classes) + os.pathsep + cp, 'ExportBiomeParameters', str(out / 'worldgen/biome-parameters.json')], check=True, cwd=cache)
    subprocess.run([java, logging_option, '-cp', str(classes) + os.pathsep + cp, 'ExportFeatureOrder', str(out / 'worldgen/feature-order.json')], check=True, cwd=cache)
    subprocess.run([java, logging_option, '-cp', str(classes) + os.pathsep + cp, 'ExportStructureOrder', str(out / 'worldgen/structure-order.json')], check=True, cwd=cache)
    subprocess.run([java, logging_option, '-Ddimension=the_nether', '-cp', str(classes) + os.pathsep + cp, 'ExportFeatureOrder', str(out / 'worldgen/feature-order-nether.json')], check=True, cwd=cache)
    subprocess.run([java, logging_option, '-Ddimension=the_end', '-cp', str(classes) + os.pathsep + cp, 'ExportFeatureOrder', str(out / 'worldgen/feature-order-end.json')], check=True, cwd=cache)
    exports = []
    for seed, chunks in WORLDGEN_FIXTURE_CHUNKS.items():
        exports.append(cache / f'worldgen-chunks-{seed}.json')
        subprocess.run([java, logging_option, '-cp', str(classes) + os.pathsep + cp, 'ExportWorldgenChunks', str(exports[-1]), seed, *chunks], check=True, cwd=cache)
    for seed, chunks in WORLDGEN_NETHER_FIXTURE_CHUNKS.items():
        exports.append(cache / f'worldgen-nether-chunks-{seed}.json')
        subprocess.run([java, logging_option, '-Ddimension=the_nether', '-cp', str(classes) + os.pathsep + cp, 'ExportWorldgenChunks', str(exports[-1]), seed, *chunks], check=True, cwd=cache)
    for seed, chunks in WORLDGEN_END_FIXTURE_CHUNKS.items():
        exports.append(cache / f'worldgen-end-chunks-{seed}.json')
        subprocess.run([java, logging_option, '-Ddimension=the_end', '-cp', str(classes) + os.pathsep + cp, 'ExportWorldgenChunks', str(exports[-1]), seed, *chunks], check=True, cwd=cache)
    subprocess.run(['python3', str(ROOT / 'tools/worldgen_fixture.py'), str(out / 'worldgen-chunks.json'), *map(str, exports)], check=True)
    reports = generated / 'reports'
    shutil.copyfile(reports / 'packets.json', out / 'packets.json')
    registries = json.loads((reports / 'registries.json').read_text())
    blocks = json.loads((reports / 'blocks.json').read_text())
    states = {name[10:]: next(s['id'] for s in block['states'] if s.get('default', len(block['states']) == 1))
              for name, block in blocks.items()}
    item_ids = {name[10:]: entry['protocol_id'] for name, entry in registries['minecraft:item']['entries'].items()}
    names = json.loads((ROOT / 'tools/placement-blocks.json').read_text())
    placeable = {str(item_ids[name]): states[name] for name in names}
    data = {'state_count': max(s['id'] for b in blocks.values() for s in b['states']) + 1,
            'states': states, 'placeable_items': placeable, 'items': item_ids,
            'player_entity_type': registries['minecraft:entity_type']['entries']['minecraft:player']['protocol_id']}
    data['argument_string'] = registries['minecraft:command_argument_type']['entries']['brigadier:string']['protocol_id']
    data['menus'] = {name[10:]: entry['protocol_id'] for name, entry in registries['minecraft:menu']['entries'].items()}
    data['component_damage'] = registries['minecraft:data_component_type']['entries']['minecraft:damage']['protocol_id']
    data['block_entity_types'] = {name[10:]: entry['protocol_id'] for name, entry in registries['minecraft:block_entity_type']['entries'].items()}
    data['entity_types'] = {name[10:]: entry['protocol_id'] for name, entry in registries['minecraft:entity_type']['entries'].items()}
    # Sound events the server plays outside mob audio.
    data['sounds'] = {name: registries['minecraft:sound_event']['entries']['minecraft:' + name]['protocol_id'] for name in ['block.chest.open', 'block.chest.close', 'block.ender_chest.open', 'block.ender_chest.close', 'block.barrel.open', 'block.barrel.close', 'entity.player.levelup', 'entity.player.hurt', 'entity.player.hurt_drown', 'entity.player.hurt_freeze', 'entity.player.hurt_on_fire', 'entity.player.hurt_sweet_berry_bush', 'entity.player.death', 'entity.tnt.primed', 'block.respawn_anchor.charge', 'block.respawn_anchor.deplete', 'block.respawn_anchor.set_spawn', 'block.bell.use', 'entity.player.small_fall', 'entity.player.big_fall']}
    data['particle_types'] = {name[10:]: entry['protocol_id'] for name, entry in registries['minecraft:particle_type']['entries'].items()}
    write_json(out / 'damage-types.json', damage_types(inner))
    write_json(out / 'timelines.json', timelines(inner))
    write_json(out / 'loot.json', loot(inner))
    write_json(out / 'enchantments.json', enchantments(inner))
    write_json(out / 'en-us-names.json', english_names(inner))
    from recipes import compile_recipes
    write_json(out / 'recipes.json', compile_recipes(inner, item_ids))
    write_json(out / 'blocks.json', data)
    gameplay = json.loads((out / 'gameplay.json').read_text())
    for full_name, block in blocks.items():
        name = full_name[10:]
        entries = block['states']
        default = next(state for state in entries if state['id'] == states[name])
        properties = {}
        for prop in block.get('properties', {}):
            values = list(dict.fromkeys(state['properties'][prop] for state in entries))
            stride = next((i for i, state in enumerate(entries) if state['properties'][prop] != entries[0]['properties'][prop]), 0)
            properties[prop] = {'values': values, 'stride': stride, 'default': default['properties'][prop]}
        first = entries[0]['id']
        for state in entries:
            calculated = first + sum(info['values'].index(state['properties'][prop]) * info['stride'] for prop, info in properties.items())
            if calculated != state['id']: raise RuntimeError('Non-Cartesian state ordering: ' + name)
        gameplay['blocks'][name].update(first=first, count=len(entries), properties=properties, default=states[name])
    write_json(out / 'gameplay.json', gameplay)

    shutil.copyfile(metadata, out / 'mojang-version.json')
    pack = actual['pack_version']
    # /version reports these from the JAR's own version.json.
    write_json(out / 'version.json', {'name': VERSION, 'protocol': PROTOCOL, 'data_version': DATA_VERSION, 'server_sha1': SERVER_SHA1,
                                      'series': actual['series_id'], 'stable': actual['stable'], 'build_time': actual['build_time'],
                                      'resource_pack': f"{pack['resource_major']}.{pack['resource_minor']}", 'data_pack': f"{pack['data_major']}.{pack['data_minor']}"})
    (out / 'tags.bin').write_bytes(generate_tags(inner, registries, json.loads((out / 'dynamic-registries.json').read_text())))
    if capture:
        subprocess.run(cmd + [str(capture)], check=True, cwd=cache)
        write_json(out / 'gameplay.json', gameplay)
    checksums = {str(p.relative_to(out)): digest(p, 'sha256') for p in sorted(out.rglob('*'))
                 if p.is_file() and p.name != 'checksums.json'}
    write_json(out / 'checksums.json', checksums)
    print('Reference data rebuilt from verified official Minecraft ' + VERSION)


if __name__ == '__main__':
    main()
