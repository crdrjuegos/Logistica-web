import subprocess,json
from pathlib import Path
sql="SELECT json_build_object('tables',(SELECT json_agg(table_name ORDER BY table_name) FROM information_schema.tables WHERE table_schema='public' AND table_type='BASE TABLE'),'keys',(SELECT json_agg(x) FROM (SELECT tc.table_name,kcu.column_name FROM information_schema.table_constraints tc JOIN information_schema.key_column_usage kcu ON tc.constraint_name=kcu.constraint_name AND tc.constraint_schema=kcu.constraint_schema WHERE tc.constraint_type='PRIMARY KEY' AND tc.table_schema='public')x))"
r=subprocess.run(['docker','compose','exec','-T','db','psql','-U','wms_owner','-d','wms','-Atc',sql],capture_output=True,text=True,encoding='utf-8',timeout=30);meta=json.loads(r.stdout)
cols=json.loads(Path('audit_work/db_columns.json').read_text(encoding='utf-8'));rels=json.loads(Path('audit_work/db_relations.json').read_text(encoding='utf-8'))
entities=[]
for t in meta['tables']:
 entities.append({'name':t,'columns':[dict(c,primary=any(k['table_name']==t and k['column_name']==c['column_name'] for k in meta['keys'])) for c in cols if c['table_name']==t]})
Path('audit_work/er_model_data.json').write_text(json.dumps({'entities':entities,'relations':rels},ensure_ascii=False,indent=2),encoding='utf-8');print('Tablas',len(entities),'columnas',sum(len(e['columns'])for e in entities),'FK',len(rels))
