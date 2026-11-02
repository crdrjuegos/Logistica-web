import subprocess,json
from pathlib import Path
jobs=[['docker','version','--format','{{json .Server}}'],['wsl.exe','--list','--verbose']]
for cmd in jobs:
 try:
  r=subprocess.run(cmd,capture_output=True,text=True,encoding='utf-8',errors='replace',timeout=25)
  print(json.dumps({'command':cmd,'returncode':r.returncode,'stdout':r.stdout[-4000:],'stderr':r.stderr[-2000:]},ensure_ascii=False))
 except subprocess.TimeoutExpired: print(json.dumps({'command':cmd,'error':'timeout 25 s'}))
lock=json.loads(Path('composer.lock').read_text()); installed=json.loads(Path('vendor/composer/installed.json').read_text())['packages']
for label,items in [('lock',lock['packages']+lock['packages-dev']),('installed',installed)]:
 print(label,[(p['name'],p['version']) for p in items if p['name'] in ['filament/filament','livewire/livewire','laravel/framework','pestphp/pest']])
