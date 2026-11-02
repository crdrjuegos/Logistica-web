from pathlib import Path
import json,re
root=Path('.'); out=Path('audit_work')
groups={'domain':list(root.glob('app/Modules/*/Domain/**/*.php')),'application':list(root.glob('app/Modules/*/Application/**/*.php')),'infra':list(root.glob('app/Modules/*/Infrastructure/**/*.php')),'panel':list(root.glob('app/Filament/**/*.php'))+list(root.glob('app/Policies/*.php')),'feature':list(root.glob('tests/Feature/**/*.php')),'unit':list(root.glob('tests/Unit/**/*.php')),'schema':list(root.glob('database/migrations/*.php'))+list(root.glob('database/sql/*.sql'))}
for name,files in groups.items():
 text='\n'.join('\nFILE '+str(p)+'\n'+''.join(f'{i+1}: {x}\n' for i,x in enumerate(p.read_text(encoding='utf-8-sig').splitlines())) for p in files)
 (out/f'code_{name}.txt').write_text(text,encoding='utf-8')
 print(name,len(files),len(text))
idx=[]
for p in root.glob('tests/**/*.php'):
 t=p.read_text(encoding='utf-8-sig')
 for m in re.finditer(r"(?:it|test|describe)\(['\"](.+?)['\"]",t): idx.append({'file':str(p).replace('\\','/'),'line':t[:m.start()].count('\n')+1,'test':m.group(1)})
(out/'test_index.json').write_text(json.dumps(idx,ensure_ascii=False,indent=2),encoding='utf-8')
for x in idx: print(x['file'],x['line'],x['test'])
w=json.loads((out/'source_workbook.json').read_text(encoding='utf-8'))
for s in w:
 if s['sheet']=='Backlog del Producto':
  for r in s['data']:
   v=r['values']
   if len(v)>5 and v[5] and str(v[5]).startswith('HU-'): print('STORY',r['row'],v[5],v[7],v[10:16])
