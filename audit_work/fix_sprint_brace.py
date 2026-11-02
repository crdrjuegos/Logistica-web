from pathlib import Path
p=Path('audit_work/build_workbook.mjs');s=p.read_text(encoding='utf-8').replace("right:{style:'thin',color:'#D9D9D9'}};sprint.getRange('AK13", "right:{style:'thin',color:'#D9D9D9'}};}sprint.getRange('AK13")
s=s.replace("sprint.getRange('F19').values=[['Sin registros diarios de esfuerzo restante']];",'')
p.write_text(s,encoding='utf-8')
