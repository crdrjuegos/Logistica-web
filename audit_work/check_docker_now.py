from pathlib import Path
import subprocess,json
for cmd in [['docker','version','--format','{{json .Server.Version}}'],['docker','compose','ps']]:
 try:
  r=subprocess.run(cmd,capture_output=True,text=True,encoding='utf-8',errors='replace',timeout=15);print(json.dumps({'cmd':cmd,'code':r.returncode,'out':r.stdout[-1500:],'err':r.stderr[-1000:]},ensure_ascii=False))
 except subprocess.TimeoutExpired: print('TIMEOUT',cmd)
