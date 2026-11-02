from pathlib import Path
p=Path('audit_work/build_workbook.mjs');s=p.read_text(encoding='utf-8')
anchor='w.recalculate();'
extra="""await fs.writeFile('audit_work/pmi_plan.json',JSON.stringify(pmi));
pm.getRange('J2').values=[['Programación prevista por semana. X no acredita ejecución.']];
pm.getRange('J3:Q3').values=[Array.from({length:8},(_,i)=>new Date(Date.UTC(2026,9,19+7*i,12)))];pm.getRange('J3:Q3').setNumberFormat('dd/mm');
pm.getRange('J4:Q4').values=[Array.from({length:8},(_,i)=>'Semana '+(i+1))];
pm.getRange('J4:Q4').format={fill:'#214C7C',font:{name:'Arial',size:10,bold:true,color:'#FFFFFF'},horizontalAlignment:'center',verticalAlignment:'center',wrapText:true};
pm.getRange('J3:Q16').format.columnWidth=12;pm.getRange('J5:Q16').format={font:{name:'Arial',size:10,bold:true},horizontalAlignment:'center',verticalAlignment:'center'};
for(let r=5;r<=16;r++)for(let j=0;j<8;j++){const col=String.fromCharCode(74+j);pm.getRange(`${col}${r}`).formulas=[[`=IF(AND($F${r}<=${col}$3+6,$G${r}>=${col}$3),\"X\",\"\")`]];}
pm.getRange('J5:Q16').conditionalFormats.add('containsText',{text:'X',format:{fill:'#FFF2CC'}});
"""
s=s.replace(anchor,extra+anchor);p.write_text(s,encoding='utf-8')
p=Path('audit_work/report_main.md');s=p.read_text(encoding='utf-8').replace('# CAPÍTULO 5 REQUISITOS Y ARQUITECTURA DEL SISTEMA','@timeline\n# CAPÍTULO 5 REQUISITOS Y ARQUITECTURA DEL SISTEMA').replace('El archivo ERwin complementa el inventario de entidades y atributos;', 'El archivo ERwin complementa el inventario de entidades, atributos y claves primarias;')
p.write_text(s,encoding='utf-8')
p=Path('audit_work/report_mini.md');s=p.read_text(encoding='utf-8').replace('El inventario ERwin incluye entidades y atributos,','El inventario ERwin incluye entidades, atributos y claves primarias,');p.write_text(s,encoding='utf-8')
p=Path('audit_work/build_reports.py');s=p.read_text(encoding='utf-8').replace('def table(d,rows):','def table(d,rows,widths=None):').replace(' widths={2:', ' widths=widths or {2:')
s=s.replace("  if s.startswith('@figure '):figure(d,*s.split()[1:]);continue", "  if s=='@timeline':\n   section(d,True)\n   d.add_paragraph('Programación de actividades por grupos de procesos PMI',style='Heading 2')\n   d.add_paragraph('Cada X indica una semana programada. Las actividades y fechas proceden de la hoja Gestión PMI; no acreditan reuniones ni entregas ya realizadas.')\n   from datetime import datetime,timedelta\n   start=datetime(2026,10,19);weeks=[start+timedelta(days=7*j)for j in range(8)]\n   rows=[['Grupo PMI','Actividad']+[str(j+1)+'\\n'+w.strftime('%d/%m')for j,w in enumerate(weeks)]]\n   for rec in json.loads((A/'pmi_plan.json').read_text(encoding='utf-8')):\n    lo=datetime.fromisoformat(rec[4]);hi=datetime.fromisoformat(rec[5]);rows.append([rec[0],rec[1]]+['X'if lo<=w+timedelta(days=6)and hi>=w else''for w in weeks])\n   table(d,rows,[2.7,8.4]+[1.8]*8);figure_open=True;continue\n  if s.startswith('@figure '):figure(d,*s.split()[1:]);continue")
p.write_text(s,encoding='utf-8');compile(s,'build_reports.py','exec')
