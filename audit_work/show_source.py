import json
from pathlib import Path
w=json.loads(Path('audit_work/source_workbook.json').read_text(encoding='utf-8'))
for s in w:
 if s['sheet'] in ['Backlog del Producto','Criterios de Aceptación','Sprints','Cronograma']:
  print('\nSHEET',s['sheet'])
  for r in s['data']:
   print(r['row'], ' | '.join(str(v) if v is not None else '' for v in r['values']))
