from pathlib import Path
import re,json
p=Path('audit_work/class_model.json');m=json.loads(p.read_text(encoding='utf-8'));values=[]
for c in m:
 if c['kind']=='Enumeration'and c['state']=='Existente':
  t=Path(c['path']).read_text(encoding='utf-8-sig');c['literals']=[{'name':x[0],'value':x[1]}for x in re.findall(r"case\s+(\w+)\s*=\s*'([^']*)'",t)];values.append({'name':c['name'],'path':c['path'],'literals':c['literals']})
p.write_text(json.dumps(m,ensure_ascii=False,indent=2),encoding='utf-8');Path('audit_work/enum_values.json').write_text(json.dumps(values,ensure_ascii=False),encoding='utf-8');print('Enumeraciones',len(values),'valores',sum(len(x['literals'])for x in values))
