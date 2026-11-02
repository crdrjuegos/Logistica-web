from pathlib import Path
import json,csv,html
A=Path('audit_work');O=Path('outputs/avance2_2026_10_28');M=O/'modelos';S=json.loads((A/'er_model_data.json').read_text(encoding='utf-8'));T=json.loads((A/'db_types.json').read_text(encoding='utf-8'));types={(c['table_name'],c['column_name']):c['full_type']for c in T}
rows=[]
for e in S['entities']:
 for c in e['columns']:
  refs=[r for r in S['relations']if r['table_name']==e['name']and r['column_name']==c['column_name']]
  rows.append([e['name'],c['column_name'],types[(e['name'],c['column_name'])],c['is_nullable'],str(c['primary']),'; '.join(r['referenced_table']+'.'+r['referenced_column']for r in refs)])
with (M/'Diccionario_Datos_WMS.csv').open('w',encoding='utf-8-sig',newline='')as f:
 w=csv.writer(f);w.writerow(['Tabla','Columna','Tipo SQL','Admite NULL','Clave primaria','Referencias FK']);w.writerows(rows)
with (M/'Referencias_FK_WMS.csv').open('w',encoding='utf-8-sig',newline='')as f:
 w=csv.DictWriter(f,fieldnames=list(S['relations'][0]));w.writeheader();w.writerows(S['relations'])
classes=json.loads((A/'class_model.json').read_text(encoding='utf-8'))
with (M/'Indice_Clases_WMS.csv').open('w',encoding='utf-8-sig',newline='')as f:
 w=csv.writer(f);w.writerow(['Módulo','Nombre','Tipo','Estado','Fuente']);w.writerows([[c.get('module',''),c['name'],c['kind'],c['state'],c.get('path','Diseño propuesto')]for c in classes])
htmltext=['<!doctype html><html lang="es"><meta charset="utf-8"><title>Diccionario de datos WMS</title><style>body{font:15px Arial;max-width:1300px;margin:40px auto;padding:0 20px;color:#172a3a}table{border-collapse:collapse;width:100%;margin-bottom:35px}td,th{border:1px solid #ccd5df;padding:8px;text-align:left}th{background:#214c7c;color:white}tr:nth-child(even){background:#f2f5f8}h2{margin-top:45px}</style><h1>Diccionario de datos WMS</h1><p>Corte 28 de octubre de 2026. 46 tablas físicas, 343 columnas y 66 referencias de clave foránea. Incluye infraestructura y una partición. La estructura procede de PostgreSQL 17; no contiene datos operativos.</p>']
for e in S['entities']:
 htmltext.append('<h2 id="'+e['name']+'">'+e['name']+'</h2><table><tr><th>Columna</th><th>Tipo SQL</th><th>Nulo</th><th>Clave</th><th>Referencia</th></tr>')
 for r in rows:
  if r[0]==e['name']:htmltext.append('<tr>'+''.join('<td>'+html.escape(v)+'</td>'for v in [r[1],r[2],r[3],'PK'if r[4]=='True'else'',r[5]])+'</tr>')
 htmltext.append('</table>')
(M/'Diccionario_Datos_WMS.html').write_text(''.join(htmltext)+'</html>',encoding='utf-8')
p=A/'build_reports.py';s=p.read_text(encoding='utf-8').replace("if large:sec.page_width=Cm", "if large:sec.orientation=WD_ORIENT.LANDSCAPE if wide else WD_ORIENT.PORTRAIT;sec.page_width=Cm")
s=s.replace("content(d,A/md);d.save(O/name);", "content(d,A/md)\n for el in list(d.element.iter(qn('w:pBdr'))):el.getparent().remove(el)\n d.save(O/name);")
p.write_text(s,encoding='utf-8');compile(s,'build_reports.py','exec')
print('Diccionario completo',len(rows),'columnas',len(S['relations']),'referencias')
