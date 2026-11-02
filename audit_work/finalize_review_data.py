from pathlib import Path
import json,re
p=Path('audit_work/review_data.json');d=json.loads(p.read_text(encoding='utf-8'));by={s['id']:s for s in d['stories']}
s=by['HU-045'];d['criteria'].append({'story':s['id'],'n':45,'seq':4,'id':'CA-045-4','title':'Exportación de pedido solo con permiso','context':'Dado un usuario con rol lectura, alerts.view y sin reports.export.','event':'Cuando accede a reposición e intenta descargar el pedido sugerido.','expected':'La acción queda oculta y una invocación no autorizada es denegada.','input':'rol=lectura; reports.export=false; recurso ListReplenishment','sources':s['sources'],'classes':s['classes'],'tests':s['tests'],'test_names':s['test_names'][:4],'status':'Caso específico no ejecutado','level':'Integración / panel','observed':'Pendiente de contrastar','notes':s['notes'],'steps':'1. Crear usuario lectura en BD aislada. 2. Consultar permiso. 3. Evaluar acción export. 4. Comprobar rechazo de invocación.','test_ref':'audit_work/sql_acceptance.php.txt; pruebas relacionadas: '+ '; '.join(s['tests'])})
extra=json.loads(Path('audit_work/sql_acceptance_results.json').read_text(encoding='utf-8'));mapping={x['id']:x for x in extra['results']}
for c in d['criteria']:
 c['expected']=c['expected'].replace('contraseña cifrada','contraseña como hash').replace('guarda la contraseña cifrada','guarda un hash de la contraseña')
 if c['id'] in mapping:
  r=mapping[c['id']];c['status']=r['status'];c['observed']=r['observed'];c['level']='Integración PostgreSQL' if c['id']!='CA-045-4' else 'Configuración de acción del panel';c['test_ref']='audit_work/sql_acceptance.php.txt; '+c['test_ref']
 if c['id']=='CA-045-4':c['observed']+='; no se ejercitó una descarga HTTP completa.'
d['criteria'].sort(key=lambda x:(x['n'],x['seq']));d['sql_probe']=extra
stats={}
for c in d['criteria']:stats[c['status']]=stats.get(c['status'],0)+1
d['criteria_stats']=stats
p.write_text(json.dumps(d,ensure_ascii=False,indent=2),encoding='utf-8');print(len(d['criteria']),stats)
