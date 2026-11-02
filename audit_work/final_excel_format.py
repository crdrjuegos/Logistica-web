from pathlib import Path
p=Path('audit_work/build_workbook.mjs');s=p.read_text(encoding='utf-8');anchor="const tasks=w.worksheets.getItem('Tareas');"
fix="const sprPlan=w.worksheets.getItem('Sprints');\nfor(const [i,dt]of ['2026-10-30','2026-11-13','2026-11-27','2026-12-10'].entries())sprPlan.getRange(`H${i+3}`).values=[[new Date(dt+'T12:00:00Z')]];\nfor(const c of ['C','E','H'])sprPlan.getRange(`${c}3:${c}6`).setNumberFormat('dd/mm/yyyy');\nsprPlan.getRange('I4').values=[['Operación de almacén: ubicaciones y proveedores, recepciones, transferencias, ajustes con aprobación, kardex y carga inicial de datos con pruebas definidas.']];\n"
s=s.replace(anchor,fix+anchor);s=s.replace("cron.getRange('F23').values=", "cron.getRange('F6').values=[['En Progreso']];cron.getRange('F23').values=")
p.write_text(s,encoding='utf-8')
