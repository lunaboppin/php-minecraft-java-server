"""Export actual JVM observations, retaining the Math.log intrinsic variation."""
import json
import os
from pathlib import Path
import subprocess
import tempfile
from java_logging import plain_reference_logging


def export_worldgen(java, classpath, classes, output, jar):
    with tempfile.TemporaryDirectory(prefix='php-minecraft-worldgen-reference-') as tmp:
        tmp = Path(tmp)
        normal = tmp / 'default.json'
        strict = tmp / 'no-log-intrinsic.json'
        cp = str(classes) + os.pathsep + classpath
        logging_option = plain_reference_logging(jar, tmp / 'plain.xml')
        subprocess.run([java, logging_option, '-cp', cp, 'ExportWorldgenNoise', str(normal)], check=True, cwd=tmp)
        subprocess.run([java, logging_option, '-XX:+UnlockDiagnosticVMOptions', '-XX:DisableIntrinsic=_dlog',
                        '-cp', cp, 'ExportWorldgenNoise', str(strict)], check=True, cwd=tmp)
        observed = json.loads(normal.read_text())
        deterministic = json.loads(strict.read_text())
        # Preserve both Gaussian results. Never normalize a difference in integer
        # consumption, uniform samples, terrain noise or any other fixture field.
        comparison = json.loads(normal.read_text())
        for exported, compared, expected in zip(observed['random'], comparison['random'], deterministic['random'], strict=True):
            for saved, actual, reference in zip(exported['operations'], compared['operations'], expected['operations'], strict=True):
                if actual['operation'] == 'gaussian':
                    saved['value_no_log_intrinsic'] = reference['value']
                    actual['value'] = reference['value']
        if comparison != deterministic:
            raise ValueError('Unexpected JVM random-stream or terrain-noise divergence')
        if list((tmp / 'logs').glob('*.gz')):
            raise ValueError('Java reference logs must remain readable')
        output.write_text(json.dumps(observed, indent=2, ensure_ascii=False) + '\n')
