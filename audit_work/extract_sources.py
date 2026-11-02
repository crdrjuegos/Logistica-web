from pathlib import Path
import json
from docx import Document
from openpyxl import load_workbook
ROOT=Path('audit_work').resolve()
files=[Path('C:/Users/USUARIO/Downloads/Plan_Practicas_Preprofesionales_PMI_SCRUM_Mejorado (1).docx'),Path('C:/Users/USUARIO/Downloads/Guia_Docente_Plan_Practicas_PMI_Metodologias.docx'),Path('C:/Users/USUARIO/Downloads/Informe Académico del Proyecto 2026-I.docx')]
for i,p in enumerate(files):
 d=Document(p); lines=[]
 for n,para in enumerate(d.paragraphs):
  if para.text.strip(): lines.append(f'P{n} [{para.style.name}] {para.text}')
 for n,t in enumerate(d.tables):
  lines.append(f'\nTABLE {n}')
  lines.extend(' | '.join(c.text.replace('\n',' / ') for c in r.cells) for r in t.rows)
 (ROOT/f'source_{i}.txt').write_text('\n'.join(lines),encoding='utf-8')
 print(json.dumps({'file':p.name,'paragraphs':len(d.paragraphs),'tables':len(d.tables),'images':len(d.inline_shapes),'sections':len(d.sections),'text_chars':sum(map(len,lines))},ensure_ascii=False))
w=load_workbook('C:/Users/USUARIO/Downloads/Product_Backlog_WMS_Fase_Inicial_Cronograma.xlsx',data_only=False); data=[]
for s in w:
 rows=[{'row':r[0].row,'values':[c.value for c in r]} for r in s if any(c.value is not None for c in r)]
 data.append({'sheet':s.title,'rows':s.max_row,'cols':s.max_column,'merged':list(map(str,s.merged_cells.ranges)),'data':rows})
 print(json.dumps({'sheet':s.title,'rows':s.max_row,'cols':s.max_column,'first_rows':rows[:4]},ensure_ascii=False,default=str))
(ROOT/'source_workbook.json').write_text(json.dumps(data,ensure_ascii=False,default=str,indent=2),encoding='utf-8')
