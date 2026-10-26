#!/usr/bin/env python3
"""Compare encrypted rejection of an unjoined session. No account credentials are used."""
import argparse,hashlib,json,os,re,subprocess
from pathlib import Path
from run import Server,ROOT

def main():
    parser=argparse.ArgumentParser(description=__doc__);parser.add_argument('--eula-file',type=Path,required=True);parser.add_argument('--output',type=Path,required=True);parser.add_argument('--jar',type=Path,default=ROOT/'tools/.cache/26.3/server.jar');args=parser.parse_args()
    expected=json.loads((ROOT/'reference/26.3/version.json').read_text())['server_sha1']
    if not args.jar.is_file() or hashlib.sha1(args.jar.read_bytes()).hexdigest()!=expected:parser.error('Reference JAR checksum mismatch or missing')
    if not args.eula_file.is_file() or not re.search(r'^\s*eula\s*=\s*true\s*$',args.eula_file.read_text(),re.M|re.I):parser.error('Supply an existing accepted Minecraft EULA file')
    output=args.output.resolve();output.mkdir(parents=True,exist_ok=False)
    java=str(Path(os.environ['JAVA_HOME'])/'bin/java') if os.environ.get('JAVA_HOME') else 'java'
    for kind in ['php','java']:
        server=Server(output/kind,kind,java,args.jar.resolve(),args.eula_file.resolve())
        if kind=='php':
            config=server.path/'server.json';data=json.loads(config.read_text());data['onlineMode']=True;config.write_text(json.dumps(data))
        else:
            props=server.path/'server.properties';props.write_text(props.read_text().replace('online-mode=false','online-mode=true'))
        try:
            server.start();subprocess.run(['php',str(ROOT/'tests/support/auth-rejection-peer.php'),str(server.port),str(output/(kind+'-packets.json'))],check=True)
        finally:server.stop()
    (output/'report.json').write_text(json.dumps({'passed':True,'observations':['both servers reject an unjoined session after RSA exchange over AES/CFB8'],'limits':['no verified real-account login','does not assert identical error wording or packet ordering']},indent=2)+'\n')
if __name__=='__main__':main()
