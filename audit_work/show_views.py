import json
from pathlib import Path
w=json.loads(Path('audit_work/source_workbook.json').read_text(encoding='utf-8'))
for s in w:
 if s['sheet']=='Sprint 1':
  for r in s['data']: print(r['row'],' | '.join(str(v) if v is not None else '' for v in r['values']))
 if s['sheet']=='Tareas':
  print('TAREAS muestra',s['data'][2:10])
 if s['sheet']=='Cronograma':
  for r in s['data']:
   if r['row']>=6: print('CRONO',r['row'],r['values'][:7])
