from pathlib import Path
p=Path('audit_work/build_workbook.mjs');s=p.read_text(encoding='utf-8').replace('63 historias y 190 escenarios','63 historias y 191 escenarios').replace('190 criterios con valores','191 criterios con valores').replace('190 escenarios; D01','191 escenarios; D01')
s=s.replace("const tasks=w.worksheets.getItem('Tareas');", "w.worksheets.getItem('Sprints').getRange('H2').values=[['Fecha de revisión prevista']];\nconst tasks=w.worksheets.getItem('Tareas');")
old="cron.getRange(`G${r}`).values=[[sum/ss.length]];"
new="cron.getRange(`G${r}`).formulas=[[`=IFERROR((${ss.map(s=>'\\\'Backlog del Producto\\\'!R'+s.row).join('+')})/${ss.length},0)`]];"
s=s.replace(old,new)
p.write_text(s,encoding='utf-8')
