from pathlib import Path
import subprocess,json
for cmd in [['wsl.exe','-d','Debian','--exec','sh','-lc','id; command -v php; command -v docker; command -v psql'],['docker','desktop','restart','--timeout','45']]:
 try:
  r=subprocess.run(cmd,capture_output=True,timeout=55)
  print(json.dumps({'cmd':cmd,'code':r.returncode,'out':r.stdout.decode('utf-8','replace')[-2000:],'err':r.stderr.decode('utf-8','replace')[-1500:]},ensure_ascii=False))
 except subprocess.TimeoutExpired: print('TIMEOUT',cmd)
