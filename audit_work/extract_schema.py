import json,subprocess
from pathlib import Path
sql="SELECT json_agg(x) FROM (SELECT table_name, column_name, data_type, is_nullable, ordinal_position FROM information_schema.columns WHERE table_schema='public' ORDER BY table_name,ordinal_position) x"
r=subprocess.run(['docker','compose','exec','-T','db','psql','-U','wms_owner','-d','wms','-Atc',sql],capture_output=True,text=True,encoding='utf-8',timeout=30)
if r.returncode: raise RuntimeError(r.stderr)
cols=json.loads(r.stdout);Path('audit_work/db_columns.json').write_text(json.dumps(cols,ensure_ascii=False,indent=2),encoding='utf-8')
sql="SELECT json_agg(x) FROM (SELECT tc.table_name,kcu.column_name,ccu.table_name AS referenced_table,ccu.column_name AS referenced_column FROM information_schema.table_constraints tc JOIN information_schema.key_column_usage kcu ON tc.constraint_name=kcu.constraint_name AND tc.constraint_schema=kcu.constraint_schema JOIN information_schema.constraint_column_usage ccu ON ccu.constraint_name=tc.constraint_name AND ccu.constraint_schema=tc.constraint_schema WHERE tc.constraint_type='FOREIGN KEY' AND tc.table_schema='public') x"
r=subprocess.run(['docker','compose','exec','-T','db','psql','-U','wms_owner','-d','wms','-Atc',sql],capture_output=True,text=True,encoding='utf-8',timeout=30)
Path('audit_work/db_relations.json').write_text(r.stdout,encoding='utf-8');print('Columnas',len(cols),'relaciones',len(json.loads(r.stdout)))
