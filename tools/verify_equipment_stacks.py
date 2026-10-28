#!/usr/bin/env python3
"""Verify PHP stack/equipment output using compiled, checksum-pinned Java 26.3 codecs."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import subprocess
import zipfile

from java_logging import plain_reference_logging

ROOT = Path(__file__).resolve().parents[1]
CACHE = ROOT / 'tools/.cache/26.3'
SERVER_SHA1 = '33680f5f2ac32864d6d7cf5e56a705fdb3e05f4c'


def verify_classpath():
    jar = CACHE / 'server.jar'
    if hashlib.sha1(jar.read_bytes()).hexdigest() != SERVER_SHA1:
        raise RuntimeError('Official server JAR does not match the pinned SHA-1')
    names = set()
    with zipfile.ZipFile(jar) as archive:
        for name in archive.namelist():
            if not name.endswith('.jar') or not name.startswith(('META-INF/libraries/', 'META-INF/versions/')):
                continue
            filename = Path(name).name
            if filename in names:
                raise RuntimeError('Ambiguous official classpath filename: ' + filename)
            names.add(filename)
            if hashlib.sha256((CACHE / 'classpath' / filename).read_bytes()).digest() != hashlib.sha256(archive.read(name)).digest():
                raise RuntimeError('Official classpath entry differs: ' + filename)
    if names != {path.name for path in (CACHE / 'classpath').glob('*.jar')}:
        raise RuntimeError('Official classpath contains missing or extra JAR files')
    return jar


def normalize(value):
    # JSON represents empty enchantment maps as {} in Java and [] in PHP.
    if isinstance(value, dict):
        result = {name: normalize(entry) for name, entry in value.items()}
        for field in ('enchantments', 'storedEnchantments'):
            if result.get(field) == []:
                result[field] = {}
        return result
    if isinstance(value, list):
        return [normalize(entry) for entry in value]
    return value


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--classes', type=Path, default=CACHE / 'exporter')
    parser.add_argument('--output', type=Path, default=ROOT / 'tools/.cache/equipment-stack-verification')
    parser.add_argument('--java-home', type=Path, default=Path(os.environ['JAVA_HOME']) if 'JAVA_HOME' in os.environ else None)
    args = parser.parse_args()
    jar = verify_classpath()
    output = args.output.resolve()
    output.mkdir(parents=True, exist_ok=True)
    logging = output / 'log4j2-plain.xml'
    plain_reference_logging(jar, logging)
    requests = output / 'php-requests.json'
    results = output / 'java-results.json'
    environment = dict(os.environ, PHP_MC_EQUIPMENT_STACK_OUTPUT=str(requests))
    with (output / 'php.log').open('w') as log:
        subprocess.run(['php', '-d', 'memory_limit=256M', str(ROOT / 'tests/equipment-stack-unit.php')], cwd=ROOT, env=environment, stdout=log, stderr=subprocess.STDOUT, check=True)
    java = str(args.java_home / 'bin/java') if args.java_home else 'java'
    classpath = str(args.classes.resolve()) + os.pathsep + str(CACHE / 'classpath/*')
    with (output / 'java.log').open('w') as log:
        subprocess.run([java, '-Xmx768m', '-Dlog4j.configurationFile=' + str(logging), '-cp', classpath, 'VerifyEquipmentStacks', str(requests), str(results)], cwd=output, stdout=log, stderr=subprocess.STDOUT, check=True)
    expected = json.loads(requests.read_text())
    actual = json.loads(results.read_text())
    checks = 0
    for request, result in zip(expected, actual, strict=True):
        if (result['kind'], result['name']) != (request['kind'], request['name']):
            raise RuntimeError('Native result identity differs')
        if not result['accepted'] or result['remaining'] != 0:
            raise RuntimeError('Native codec rejected PHP payload or left trailing bytes: ' + request['name'])
        fields = ('stack',) if request['kind'] == 'stack' else ('entity', 'slots')
        for field in fields:
            wanted = request[field]
            observed = result[field]
            if field == 'slots':
                wanted = {str(index): value for index, value in enumerate(wanted)} if isinstance(wanted, list) else wanted
            if normalize(observed) != normalize(wanted):
                raise RuntimeError('Native decoded state differs: ' + request['kind'] + '/' + request['name'] + '/' + field)
            checks += 1
    print(f'PASS: actual vanilla codecs accepted {len(actual)} PHP payloads with {checks} exact state comparisons and no trailing bytes')


if __name__ == '__main__':
    main()
