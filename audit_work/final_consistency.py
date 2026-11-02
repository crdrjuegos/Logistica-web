from pathlib import Path
import json
p=Path('audit_work/review_data.json');d=json.loads(p.read_text(encoding='utf-8'))
for s in d['stories']:
 if s['id']=='HU-045':s['technical']='Parcial';s['notes']='La acción export es visible para lectura sin reports.export; revisar autorización de interfaz y servidor. '+s['notes']
 source=next(x for x in d['source']if x['sheet']=='Backlog del Producto');rec=next((r for r in source['data']if r['row']==s['row']),None)
 if rec and rec['values'][6]!=s['role']:
  original=rec['values'][6];note=f'Rol propuesto {s["role"]} según política actual; rol original {original}. Requiere ratificación de la empresa y no constituye aceptación del cambio de requisito.'
  if note not in s['notes']:s['notes']+=' '+note
for c in d['criteria']:
 if c['story']=='HU-045' and 'reports.export'not in c['notes']:c['notes']+=' La autorización de exportación presenta el hallazgo CA-045-4.'
p.write_text(json.dumps(d,ensure_ascii=False,indent=2),encoding='utf-8')
b=Path('audit_work/build_workbook.mjs');s=b.read_text(encoding='utf-8').replace("pm.getRange('B5:I16').format.rowHeight=82;", "pm.getRange('B5:I16').format.rowHeight=64;pm.getRange('B5:I16').format.verticalAlignment='center';")
s=s.replace("tasks.getRange('K4').values=[['Evidencia y alcance del estado']];", "tasks.getRange('K4').values=[['Evidencia y alcance del estado']];tasks.getRange('K4').format={fill:'#D9E2F3',font:{name:'Arial',size:10,bold:true},wrapText:true};tasks.getRange('K5:K184').format.font={name:'Arial',size:10};tasks.getRange('K5:K184').format.verticalAlignment='center';")
b.write_text(s,encoding='utf-8')
print('Estados y cambios de rol registrados')
