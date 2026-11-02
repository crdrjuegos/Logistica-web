from pathlib import Path
p=Path('audit_work/build_workbook.mjs');s=p.read_text(encoding='utf-8').replace("const sprPlan=w.worksheets.getItem('Sprints');", "const sprPlan=w.worksheets.getItem('Sprints');sprPlan.getRange('F2').values=[['Estimación SP']];")
s=s.replace("sprint.getRange('B7').values=", "sprint.getRange('V21:AF90').clear({applyTo:'all'});sprint.getRange('B7').values=")
p.write_text(s,encoding='utf-8')
