from docx import Document
from docx.shared import Pt
from docx.oxml.ns import qn
from docx.oxml import OxmlElement
from pathlib import Path
doc_path=Path('outputs/revision_formato_2026_10_28/Informe_Academico_WMS_Formato_Docente.docx');d=Document(doc_path)
for para in d.paragraphs:
 if para.text=='4.7 Cronograma del proyecto':
  for run in para.runs:run.bold=True
for t in d.tables:
 if t.cell(0,0).text=='Criterio'and len(t.rows)==6:
  for row in t.rows:
   for c in row.cells:
    for p in c.paragraphs:
     p.paragraph_format.space_before=Pt(0);p.paragraph_format.space_after=Pt(0);p.paragraph_format.line_spacing=Pt(10.5)
     for r in p.runs:r.font.size=Pt(9.5)
    pr=c._tc.get_or_add_tcPr();m=OxmlElement('w:tcMar')
    for edge in ['top','bottom']:
     e=OxmlElement('w:'+edge);e.set(qn('w:w'),'35');e.set(qn('w:type'),'dxa');m.append(e)
    pr.append(m)
d.save(doc_path)
print('Tabla de evidencias ajustada y título del cronograma reforzado.')
