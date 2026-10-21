#!/usr/bin/env python3
"""Package only explicit project files; exclude work, VCS state, nested builds and caches."""
import hashlib
from pathlib import Path
import zipfile

root=Path(__file__).resolve().parent.parent
build=root/'build';build.mkdir(exist_ok=True)
phar=build/'AmyxAIMobs.phar'
if not phar.is_file(): raise SystemExit('Build the PHAR first: php -d phar.readonly=0 tools/build.php')

def archive(path,files):
    with zipfile.ZipFile(path,'w',compression=zipfile.ZIP_DEFLATED,compresslevel=9) as out:
        for file,name in sorted(files,key=lambda item:item[1]):
            entry=zipfile.ZipInfo(name,date_time=(2026,10,21,0,0,0));entry.compress_type=zipfile.ZIP_DEFLATED;entry.external_attr=0o100644<<16
            out.writestr(entry,file.read_bytes())

archive(build/'AmyxAIMobs-install.zip',[(phar,'plugins/AmyxAIMobs.phar'),(root/'README.md','README.md')])
files=[]
for name in ('LICENSE','plugin.yml','README.md','.gitignore','src','resources','tests','tools','docs','.github'):
    file=root/name
    if file.is_file(): files.append((file,'AmyxAIMobs/'+name))
    elif file.is_dir():
        for child in file.rglob('*'):
            if child.is_file() and '__pycache__' not in child.parts: files.append((child,'AmyxAIMobs/'+child.relative_to(root).as_posix()))
archive(build/'AmyxAIMobs-source.zip',files)
assets=[phar,build/'AmyxAIMobs-install.zip',build/'AmyxAIMobs-source.zip']
(build/'SHA256SUMS.txt').write_text(''.join(hashlib.sha256(asset.read_bytes()).hexdigest()+'  '+asset.name+'\n' for asset in assets))
for asset in assets: print(f'{asset.name}: {asset.stat().st_size} bytes')
