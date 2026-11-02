from pathlib import Path
from copy import deepcopy
import json,re,hashlib
from docx import Document
from docx.shared import Cm,Pt,RGBColor
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.enum.table import WD_TABLE_ALIGNMENT,WD_CELL_VERTICAL_ALIGNMENT
from docx.oxml import OxmlElement
from docx.oxml.ns import qn
from docx.enum.section import WD_SECTION_START,WD_ORIENT
import openpyxl
BASE=Path('audit_work');OUT=Path('outputs/revision_formato_2026_10_28');OUT.mkdir(exist_ok=True)
SRC=Path(r'C:/Users/USUARIO/Downloads/Informe_Academico_WMS_Segundo_Avance_Corregido.docx')
source=Document(SRC);plan=json.loads((BASE/'formal_pmi.json').read_text(encoding='utf-8'));names=json.loads((BASE/'usecase_names.json').read_text(encoding='utf-8'))
wb=openpyxl.load_workbook(OUT/'Product_Backlog_WMS_Formato_Docente.xlsx',data_only=True)
back=wb['Backlog del Producto'];cases=wb['Criterios de Aceptación'];stories=[r for r in back.iter_rows(values_only=True)if len(r)>5 and isinstance(r[5],str)and re.fullmatch(r'HU-\d{3}',r[5])]
criteria=[r for r in cases.iter_rows(min_row=6,values_only=True)if isinstance(r[1],str)and r[1].startswith('HU-')]

def shade(cell,color):
 pr=cell._tc.get_or_add_tcPr();old=pr.find(qn('w:shd'))
 if old is not None:pr.remove(old)
 el=OxmlElement('w:shd');el.set(qn('w:fill'),color);pr.append(el)
def borders(t,color='000000',size='4',pad='70'):
 pr=t._tbl.tblPr;old=pr.find(qn('w:tblBorders'))
 if old is not None:pr.remove(old)
 b=OxmlElement('w:tblBorders')
 for edge in ['top','left','bottom','right','insideH','insideV']:
  a=OxmlElement('w:'+edge);a.set(qn('w:val'),'single');a.set(qn('w:sz'),size);a.set(qn('w:color'),color);b.append(a)
 pr.append(b)
 margins=OxmlElement('w:tblCellMar')
 for edge,val in [('top',pad),('bottom',pad),('left','100'),('right','100')]:
  a=OxmlElement('w:'+edge);a.set(qn('w:w'),val);a.set(qn('w:type'),'dxa');margins.append(a)
 pr.append(margins)
 seen=set()
 for row in t.rows:
  for cell in row.cells:
   if cell._tc in seen:continue
   seen.add(cell._tc);tcpr=cell._tc.get_or_add_tcPr()
   old=tcpr.find(qn('w:tcBorders'))
   if old is not None:tcpr.remove(old)
   bd=OxmlElement('w:tcBorders')
   for edge in ['top','left','bottom','right']:
    a=OxmlElement('w:'+edge);a.set(qn('w:val'),'single');a.set(qn('w:sz'),size);a.set(qn('w:color'),color);bd.append(a)
   tcpr.append(bd)
def set_width(t,width):
 e=t._tbl.tblPr.find(qn('w:tblW'));e.set(qn('w:type'),'dxa');e.set(qn('w:w'),str(int(Cm(width)/635)))
def setrun(r,size=11,bold=False,color='000000'):
 r.font.name='Arial';r.font.size=Pt(size);r.bold=bold;r.font.color.rgb=RGBColor.from_string(color)
def textcell(c,text,size=11,bold=False,align=None,color='000000'):
 c.text='';p=c.paragraphs[0];p.paragraph_format.space_after=Pt(0);p.paragraph_format.space_before=Pt(0);p.paragraph_format.line_spacing=1.05
 if align is not None:p.alignment=align
 setrun(p.add_run(str(text)),size,bold,color);c.vertical_alignment=WD_CELL_VERTICAL_ALIGNMENT.CENTER
 return p
def pair(c,label,value,size=11.5):
 p=textcell(c,'',size);setrun(p.add_run(label+' '),size,True);setrun(p.add_run(str(value)),size);return p
def sourcep(doc):
 p=doc.add_paragraph('Fuente: Elaboración propia (2026).');p.alignment=WD_ALIGN_PARAGRAPH.CENTER
 p.paragraph_format.space_before=Pt(6);p.paragraph_format.space_after=Pt(8)
 for r in p.runs:setrun(r,10);r.italic=True
 return p

def formal_table(doc):
 t=doc.add_table(rows=4+len(plan),cols=9);t.autofit=False;t.alignment=WD_TABLE_ALIGNMENT.CENTER
 widths=[16.6]+[2.8]*8
 for col,wi in zip(t.columns,widths):col.width=Cm(wi)
 for row in t.rows:
  for c,wi in zip(row.cells,widths):c.width=Cm(wi)
 t.cell(0,1).merge(t.cell(0,8));t.cell(1,1).merge(t.cell(1,4));t.cell(1,5).merge(t.cell(1,8))
 for i in range(4):t.cell(2,1+2*i).merge(t.cell(2,2+2*i))
 textcell(t.cell(0,0),'DETALLAR LAS ACTIVIDADES',10,True);textcell(t.cell(0,1),'TIEMPO',10,True,WD_ALIGN_PARAGRAPH.CENTER)
 textcell(t.cell(1,0),'FASE / ACTIVIDAD',10,True);textcell(t.cell(1,1),'Mes 1',10,True,WD_ALIGN_PARAGRAPH.CENTER);textcell(t.cell(1,5),'Mes 2',10,True,WD_ALIGN_PARAGRAPH.CENTER)
 textcell(t.cell(2,0),'SPRINT',10,True)
 for i in range(4):textcell(t.cell(2,1+2*i),'Sprint '+str(i+1),10,True,WD_ALIGN_PARAGRAPH.CENTER)
 textcell(t.cell(3,0),'SEMANA',10,True)
 for i in range(8):textcell(t.cell(3,i+1),i+1,10,True,WD_ALIGN_PARAGRAPH.CENTER)
 for i,row in enumerate(t.rows):
  if i<4:
   pr=row._tr.get_or_add_trPr();pr.append(OxmlElement('w:tblHeader'))
   seen=set()
   for c in row.cells:
    if id(c._tc)in seen:continue
    seen.add(id(c._tc));shade(c,'A90020'if i<2 else'E7E6E6')
    if i<2:
     for p in c.paragraphs:
      for run in p.runs:run.font.color.rgb=RGBColor(255,255,255)
 for i,r in enumerate(plan,4):
  if 'phase'in r:
   c=t.cell(i,0).merge(t.cell(i,8));textcell(c,r['phase'],10,True,color='FFFFFF');shade(c,'C00024')
  else:
   textcell(t.cell(i,0),str(r['number'])+'. '+r['activity'],9.5)
   for j in range(8):
    yes=j+1 in r['weeks'];textcell(t.cell(i,j+1),'X'if yes else'',9.5,True,WD_ALIGN_PARAGRAPH.CENTER);shade(t.cell(i,j+1),'FFF200'if yes else'FFFFFF')
 for row in t.rows:
  pr=row._tr.get_or_add_trPr();pr.append(OxmlElement('w:cantSplit'))
 for row in t.rows:
  for cell in row.cells:
   for p in cell.paragraphs:p.paragraph_format.line_spacing=Pt(11)
 borders(t,pad='25');set_width(t,39);return t

def section_break(pr):
 p=OxmlElement('w:p');pp=OxmlElement('w:pPr');cp=deepcopy(pr)
 for old in cp.findall(qn('w:type')):cp.remove(old)
 ty=OxmlElement('w:type');ty.set(qn('w:val'),'nextPage');cp.insert(0,ty);pp.append(cp);p.append(pp);return p

def story_table(doc,r):
 id,role,want,why=r[5],r[6],r[7],r[8]
 title=doc.add_paragraph('Tabla '+str(9+stories.index(r))+': Historia de '+names[id]);title.alignment=WD_ALIGN_PARAGRAPH.CENTER
 title.paragraph_format.page_break_before=True;title.paragraph_format.keep_with_next=True;title.paragraph_format.space_after=Pt(12)
 for run in title.runs:setrun(run,12,True)
 t=doc.add_table(rows=7,cols=2);t.autofit=False;t.alignment=WD_TABLE_ALIGNMENT.CENTER
 for c in t.columns:c.width=Cm(8)
 for row in t.rows:
  for cell in row.cells:cell.width=Cm(8)
 for i in [0,2,4,5,6]:t.cell(i,0).merge(t.cell(i,1))
 for i in [0,1,3,5]:
  for c in t.rows[i].cells:shade(c,'B6D7A8')
 textcell(t.cell(0,0),'Historia de Usuario',12.5,True,WD_ALIGN_PARAGRAPH.CENTER)
 pair(t.cell(1,0),'ID:',id);pair(t.cell(1,1),'Usuario:',role)
 pair(t.cell(2,0),'Nombre de historia:',names[id])
 priority={1:'Alta (1)',2:'Media (2)',3:'Baja (3)'}.get(r[10],str(r[10]))
 pair(t.cell(3,0),'Prioridad en negocio:',priority);pair(t.cell(3,1),'Riesgo en desarrollo:','Por evaluar')
 pair(t.cell(4,0),'Responsable:','Equipo de desarrollo; asignación individual pendiente')
 def lower(x):return x[0].lower()+x[1:] if x else''
 pair(t.cell(5,0),'Descripción:',f'Como {role}, quiero {lower(want).rstrip(".")}, para {lower(why).rstrip(".")}.')
 cell=t.cell(6,0);textcell(cell,'Validaciones:',11.5,True)
 for c in [c for c in criteria if c[1]==id]:
  p=cell.add_paragraph();p.paragraph_format.space_before=Pt(8);p.paragraph_format.space_after=Pt(3);p.paragraph_format.left_indent=Cm(.45);setrun(p.add_run('•  Validación '+c[6]),11.5,True)
  p=cell.add_paragraph();p.paragraph_format.left_indent=Cm(.65);p.paragraph_format.space_after=Pt(3);p.paragraph_format.line_spacing=1.05
  setrun(p.add_run(str(c[7]).rstrip('.')+'. '),11.5,True)
  for label,index,regex in [('Dado',8,r'^Dado\s*'),('Cuando',9,r'^Cuando\s*'),('Entonces',10,r'^Entonces\s*:?\s*')]:
   val=re.sub(regex,'',str(c[index]or''),flags=re.I).strip();setrun(p.add_run(label+': '),11.5,True);setrun(p.add_run(val.rstrip('.')+'. '),11.5)
 for row in t.rows:
  row._tr.get_or_add_trPr().append(OxmlElement('w:cantSplit'))
 borders(t);sourcep(doc);return t

# Short document: only the three content additions, with no inventory of image or format adjustments.
short=Document();sec=short.sections[0];sec.orientation=WD_ORIENT.LANDSCAPE;sec.page_width=Cm(42);sec.page_height=Cm(29.7);sec.top_margin=sec.bottom_margin=Cm(1.5);sec.left_margin=sec.right_margin=Cm(1.5)
normal=short.styles['Normal'];normal.font.name='Arial';normal.font.size=Pt(11)
for st in ['Title','Heading 1','Heading 2']:
 short.styles[st].font.color.rgb=RGBColor(0,0,0);short.styles[st].font.name='Arial'
p=short.add_paragraph('Contenido añadido al informe del WMS','Title');p.runs[0].font.size=Pt(19)
p=short.add_paragraph('Se separan únicamente las tres tablas incorporadas en la versión corregida respecto de la versión anterior del segundo avance. Se conserva su contenido para identificar con precisión lo añadido.');p.paragraph_format.space_after=Pt(6)
p=short.add_paragraph('1  Programación de sprints épicas entregables e hitos');p.runs[0].bold=True
copy=deepcopy(source.tables[8]._tbl);short._element.body.insert(len(short._element.body)-1,copy)
t=short.tables[-1];t.autofit=False
widths=[8.5,2.3,2.8,2.6,2.6,2.6]+[2.2]*8
for col,wi in zip(t.columns,widths):col.width=Cm(wi)
for ir,row in enumerate(t.rows):
 row.height=None
 for j,c in enumerate(row.cells):
  c.width=Cm(widths[j]);c.vertical_alignment=WD_CELL_VERTICAL_ALIGNMENT.CENTER
  for p in c.paragraphs:
   p.paragraph_format.space_before=p.paragraph_format.space_after=Pt(0);p.paragraph_format.line_spacing=1
   for rr in p.runs:setrun(rr,9,ir==0,'FFFFFF'if ir==0 else'000000')
borders(t,'D9D9D9');set_width(t,sum(widths))
short.add_paragraph('Verificación representa la proporción de criterios conformes por historia promediada por grupo. No equivale al avance físico ni a la aceptación empresarial. Las fechas y marcas corresponden a la programación prevista.');sourcep(short)
s=short.add_section(WD_SECTION_START.NEW_PAGE);s.orientation=WD_ORIENT.PORTRAIT;s.page_width=Cm(21);s.page_height=Cm(29.7);s.left_margin=s.right_margin=Cm(2);s.top_margin=s.bottom_margin=Cm(2)
for order,idx,title,intro in [(2,10,'Correspondencia entre actores y roles de la aplicación','Añadido en el apartado 5.1.2. Relaciona los actores del backlog con los cinco roles existentes en la aplicación.'),(3,12,'Diferencias entre actores del backlog y los diagramas','Añadido en el apartado 5.1.4. Identifica diferencias de autorización o representación que requieren ratificación de la empresa o corrección del modelo.')]:
 p=short.add_paragraph(str(order)+'  '+title,'Heading 1');p.paragraph_format.page_break_before=order==3
 short.add_paragraph(intro)
 tab=deepcopy(source.tables[idx]._tbl);short._element.body.insert(len(short._element.body)-1,tab);t=short.tables[-1];t.autofit=False
 widths=([5.1,2.5,9.4]if idx==10 else[2.8,3.8,3.3,1.6,5.5])
 for col,wi in zip(t.columns,widths):col.width=Cm(wi)
 for ri,row in enumerate(t.rows):
  row.height=None;row._tr.get_or_add_trPr().append(OxmlElement('w:cantSplit'))
  for j,c in enumerate(row.cells):
   c.width=Cm(widths[j]);c.vertical_alignment=WD_CELL_VERTICAL_ALIGNMENT.CENTER
   for p in c.paragraphs:
    p.paragraph_format.space_before=Pt(2);p.paragraph_format.space_after=Pt(2);p.paragraph_format.line_spacing=1.05
    for rr in p.runs:setrun(rr,10,ri==0,'FFFFFF'if ri==0 else'000000')
 borders(t,'D9D9D9');set_width(t,sum(widths));sourcep(short)
for p in short.paragraphs:
 for pr in [p._p.pPr,p.style.element.find(qn('w:pPr'))]:
  if pr is not None:
   bd=pr.find(qn('w:pBdr'))
   if bd is not None:pr.remove(bd)
short.save(OUT/'Tablas_Agregadas_Informe_WMS.docx')

# Main document: keep the user's corrected report and replace only the requested presentations.
doc=Document(SRC);old_tables=list(doc.tables);body=doc._element.body
# Record dimensions from the existing portrait and landscape sections.
portrait=deepcopy(doc.sections[0]._sectPr);landscape=deepcopy(doc.sections[1]._sectPr)
size=landscape.find(qn('w:pgSz'));size.set(qn('w:w'),str(int(Cm(42)/635)));size.set(qn('w:h'),str(int(Cm(29.7)/635)));size.set(qn('w:orient'),'landscape')
mar=landscape.find(qn('w:pgMar'))
for e in ['top','bottom','left','right']:mar.set(qn('w:'+e),str(int(Cm(1.5)/635)))
start=next(p for p in doc.paragraphs if p.text=='4.7 Cronograma del proyecto')._p
end=next(p for p in doc.paragraphs if p.text=='CAPÍTULO 5 REQUISITOS Y ARQUITECTURA DEL SISTEMA')._p
siblings=list(body);a=siblings.index(start);b=siblings.index(end)
for e in siblings[a:b]:body.remove(e)
body.insert(body.index(end),section_break(portrait))
container=Document();p=container.add_paragraph('4.7 Cronograma del proyecto','Heading 2');p.paragraph_format.space_after=Pt(5)
p=container.add_paragraph('El cronograma conserva la planificación del 19 de octubre al 10 de diciembre de 2026 y se organiza en cinco grupos de procesos PMI. Scrum se aplica durante la ejecución y el monitoreo y control. Las marcas X indican periodos programados; no constituyen evidencia de reuniones, aceptación o entregas realizadas.');p.paragraph_format.space_after=Pt(5)
for pp in container.paragraphs:
 for run in pp.runs:setrun(run,11,pp==container.paragraphs[0])
for e in list(container._element.body)[:-1]:body.insert(body.index(end),deepcopy(e))
container=Document();p=container.add_paragraph('Tabla 7: Actividades principales a realizarse en la empresa');p.alignment=WD_ALIGN_PARAGRAPH.CENTER;p.paragraph_format.keep_with_next=True
for r in p.runs:setrun(r,12,True)
container.add_paragraph('Mes 1 y Mes 2 representan bloques de cuatro semanas. Sprint 1: 19/10–01/11; Sprint 2: 02/11–15/11; Sprint 3: 16/11–29/11; Sprint 4: 30/11–10/12. El último periodo termina el jueves 10/12. Las HU-047, HU-055 y HU-056 permanecen como evolución futura del producto.')
formal_table(container);sourcep(container)
for el in list(container._element.body)[:-1]:body.insert(body.index(end),deepcopy(el))
body.insert(body.index(end),section_break(landscape))
# Remove the old grouped functional overview; provide context before the actors table and detailed fichas.
t=old_tables[9]._tbl;idx=body.index(t);p=Document().add_paragraph('Las 63 historias se presentan en fichas individuales para describir su alcance y sus validaciones. Sus validaciones conservan los 191 identificadores y escenarios de la matriz del Excel. La prioridad procede del backlog aprobado; el riesgo y la asignación individual del responsable quedan por evaluar y confirmar. Los resultados ejecutados, entradas, clases y pruebas se consultan en la matriz de aceptación.');body.insert(idx,deepcopy(p._p));body.remove(t)
# Insert all fichas after the application-role mapping and before nonfunctional requirements.
anchor=old_tables[10]._tbl
container=Document()
for r in stories:story_table(container,r)
for e in list(container._element.body)[:-1]:anchor.addnext(deepcopy(e));anchor=anchor.getnext()
for p in doc.paragraphs:
 if p.text=='5.1.3 Requerimientos no funcionales':p.paragraph_format.page_break_before=True
 if 'Product_Backlog_WMS_Segundo_Avance_Mejorado.xlsx'in p.text:
  for r in p.runs:r.text=r.text.replace('Product_Backlog_WMS_Segundo_Avance_Mejorado.xlsx','Product_Backlog_WMS_Formato_Docente.xlsx')
# Preserve role caution and all diagrams; keep unresolved matters explicit.
for style in doc.styles:
 if style.name.lower().startswith('toc '):
  style.font.name='Arial';style.font.size=Pt(9.5);style.paragraph_format.space_before=Pt(0);style.paragraph_format.space_after=Pt(1);style.paragraph_format.line_spacing=Pt(11)
settings=doc.settings._element;update=OxmlElement('w:updateFields');update.set(qn('w:val'),'true');settings.append(update)
doc.save(OUT/'Informe_Academico_WMS_Formato_Docente.docx')
# Package preservation checks and specification coverage.
import zipfile
with zipfile.ZipFile(SRC)as z:before={n:hashlib.sha256(z.read(n)).hexdigest()for n in z.namelist()if n.startswith('word/media/')}
with zipfile.ZipFile(OUT/'Informe_Academico_WMS_Formato_Docente.docx')as z:after={n:hashlib.sha256(z.read(n)).hexdigest()for n in z.namelist()if n.startswith('word/media/')}
assert before==after,'Se alteró una imagen fuera del alcance'
print('Generados 2 documentos; fichas',len(stories),'criterios',len(criteria),'imágenes conservadas',len(after),'tablas principales',len(doc.tables))
