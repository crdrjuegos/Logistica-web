from pathlib import Path
import json,collections
from openpyxl import load_workbook
A=Path('audit_work');p=Path('outputs/avance2_2026_10_28/Product_Backlog_WMS_Segundo_Avance.xlsx');f=load_workbook(p,data_only=False);w=load_workbook(p,data_only=True)
s=w['Criterios de Aceptación'];crit=[r for r in s.iter_rows(min_row=6,values_only=True)if isinstance(r[6],str)and r[6].startswith('CA-')]
print('HOJAS',w.sheetnames);print('CRITERIOS',len(crit),'IDs únicos',len({r[6]for r in crit}));print('ESTADOS',dict(collections.Counter(r[16]for r in crit)))
errors=[]
for sh in w:
 for row in sh:
  for c in row:
   if c.data_type=='e':errors.append((sh.title,c.coordinate,c.value))
print('ERRORES',errors)
print('HU',sum(1 for row in w['Backlog del Producto'] if isinstance(row[5].value,str)and row[5].value.startswith('HU-')))
print('FORMULAS',sum(1 for sh in f for row in sh for c in row if c.data_type=='f'))
print('GRÁFICOS',[(sh.title,len(sh._charts))for sh in f if sh._charts]);print('FECHAS',[(w['Sprints'].cell(r,8).value,w['Sprints'].cell(r,8).number_format)for r in range(3,7)])
print('DEFINIDOS',list(f.defined_names));print('TABLAS',[(sh.title,list(sh.tables))for sh in f if sh.tables])
d=json.loads((A/'review_data.json').read_text(encoding='utf-8'));score={'Implementado':1,'Parcial':.5,'Pendiente':0}
for label,st in [('backend',[s for s in d['stories']if s['number']<=56 and s['number']not in [54,55,56]]),('frontend',[s for s in d['stories']if s['number']<=56 and s['number']not in [17,18,19,20,21,23]])]:
 points=sum(s['points']for s in st);value=sum(s['points']*score[s['technical']]for s in st);print(label,len(st),'puntos',points,'implementación técnica',round(value/points*100,2));print('Sin fuente de panel',[(s['id'],s['technical'])for s in st if not any('Filament' in p or 'resources/'in p for p in s['sources'])])
