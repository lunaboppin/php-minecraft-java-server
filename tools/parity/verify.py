#!/usr/bin/env python3
"""Decode bidirectional captures using the pinned official JAR, without modifying reference files."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import zipfile

ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'tools'))
from java_logging import plain_reference_logging


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('captures',type=Path,nargs='+')
    parser.add_argument('--java-home',type=Path,default=Path(os.environ['JAVA_HOME']) if 'JAVA_HOME' in os.environ else None)
    args=parser.parse_args()
    jar=ROOT/'tools/.cache/26.3/server.jar'
    expected=json.loads((ROOT/'reference/26.3/version.json').read_text())['server_sha1']
    if not jar.is_file() or hashlib.sha1(jar.read_bytes()).hexdigest()!=expected:
        parser.error('Missing checksum-pinned JAR; run tools/update_reference.py first')
    java=str(args.java_home/'bin/java') if args.java_home else shutil.which('java')
    javac=str(args.java_home/'bin/javac') if args.java_home else shutil.which('javac')
    if not java or not javac:parser.error('Java 25+ JDK required')
    # Work exclusively in scratch space: exporter output must never replace production registries.
    with tempfile.TemporaryDirectory(prefix='php-minecraft-codecs-') as tmp:
        tmp=Path(tmp);libraries=tmp/'libraries';libraries.mkdir();classes=tmp/'classes';classes.mkdir()
        with zipfile.ZipFile(jar) as archive:
            for name in archive.namelist():
                if name.endswith('.jar') and name.startswith(('META-INF/libraries/','META-INF/versions/')):
                    (libraries/Path(name).name).write_bytes(archive.read(name))
        logging_option=plain_reference_logging(jar,tmp/'log4j2-plain.xml')
        cp=str(libraries/'*')
        subprocess.run([javac,'-cp',cp,'-d',str(classes),str(ROOT/'tools/ExportReference.java')],check=True)
        for capture in args.captures:
            subprocess.run([java,logging_option,'-cp',str(classes)+os.pathsep+cp,'ExportReference',str(tmp/'generated'),str(capture.resolve())],check=True,cwd=tmp)


if __name__=='__main__':main()
