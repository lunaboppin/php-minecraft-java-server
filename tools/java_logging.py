"""Keep pinned Java reference utilities and servers' rotated logs readable."""
import io
from pathlib import Path
import zipfile


def plain_reference_logging(jar,output):
    # Preserve Mojang's logging setup, changing only its gzip archive suffix.
    with zipfile.ZipFile(jar) as outer:
        library=next(name for name in outer.namelist() if name.startswith('META-INF/libraries/') and Path(name).name.startswith('logging-') and name.endswith('.jar'))
        with zipfile.ZipFile(io.BytesIO(outer.read(library))) as logging:
            config=logging.read('log4j2.xml').decode('utf-8')
    if '.log.gz' not in config:raise ValueError('Pinned reference logging format changed')
    output.write_text(config.replace('.log.gz','.log'))
    return '-Dlog4j.configurationFile='+str(output.resolve())
