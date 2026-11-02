from pathlib import Path
import json,re
from datetime import datetime,timezone
from docx import Document
from docx.shared import Cm,Pt,RGBColor
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.enum.style import WD_STYLE_TYPE
from docx.enum.section import WD_SECTION_START,WD_ORIENT
from docx.oxml import OxmlElement
from docx.oxml.ns import qn
from PIL import Image
A=Path('audit_work');O=Path('outputs/avance2_2026_10_28');O.mkdir(parents=True,exist_ok=True)
exports={v['key']:v for v in json.loads((A/'model_exports.json').read_text(encoding='utf-8'))}
preludes=json.loads((A/'figure_preludes.json').read_text(encoding='utf-8'))
fig_no=0
figure_open=False

def setup(d):
 sec=d.sections[0];sec.different_first_page_header_footer=True;sec.page_width=Cm(21);sec.page_height=Cm(29.7);sec.top_margin=sec.bottom_margin=Cm(2.2);sec.left_margin=sec.right_margin=Cm(2.5)
 for n in ['Normal','Title','Subtitle','Heading 1','Heading 2','Heading 3','Heading 4','Heading 5','Caption']:
  s=d.styles[n];s.font.name='Arial';s.font.color.rgb=RGBColor(0,0,0)
 d.styles['Normal'].font.bold=False;d.styles['Normal'].font.italic=False;d.styles['Normal'].font.size=Pt(11);d.styles['Normal'].paragraph_format.space_after=Pt(7);d.styles['Normal'].paragraph_format.line_spacing=1.12
 d.styles['Title'].font.size=Pt(19);d.styles['Title'].font.bold=True
 for lev,size in [(1,14),(2,12),(3,11),(4,11),(5,11)]:
  s=d.styles[f'Heading {lev}'];s.font.size=Pt(size);s.font.bold=True;s.paragraph_format.space_before=Pt(14);s.paragraph_format.space_after=Pt(7);s.paragraph_format.keep_with_next=True
  if lev==1:s.paragraph_format.page_break_before=True
 for style in d.styles:
  for b in list(style.element.iter(qn('w:pBdr'))):b.getparent().remove(b)
 for name in ['TOC 1','TOC 2']:
  st=d.styles[name]if name in d.styles else d.styles.add_style(name,WD_STYLE_TYPE.PARAGRAPH)
  st.font.name='Arial';st.font.size=Pt(9.5);st.font.bold=False;st.paragraph_format.space_after=Pt(0);st.paragraph_format.space_before=Pt(0);st.paragraph_format.line_spacing=1
 d.styles['Caption'].font.bold=False;d.styles['Caption'].font.size=Pt(9);d.styles['Caption'].font.italic=False
 sec.header.paragraphs[0].text='CITY OFERTAS     Sistema de inventario y almacén     Segundo avance'
 sec.header.paragraphs[0].style=d.styles['Caption']
 p=sec.footer.paragraphs[0];p.alignment=WD_ALIGN_PARAGRAPH.RIGHT
 p.add_run('Corte 28 de octubre de 2026   |   ')
 field=OxmlElement('w:fldSimple');field.set(qn('w:instr'),'PAGE');p._p.append(field)
 for p in sec.footer.paragraphs:
  for r in p.runs:r.font.size=Pt(9)
 d.core_properties.author='Equipo del proyecto CITY OFERTAS';d.core_properties.subject='Segundo avance con diseño integral y validación técnica al 28 de octubre de 2026'

def table(d,rows,widths=None):
 t=d.add_table(rows=0,cols=len(rows[0]));t.autofit=False
 widths=widths or {2:[4.4,11.6],3:[3.6,5.0,7.4],4:[2.2,4.4,4.2,5.2]}.get(len(rows[0]),[16/len(rows[0])]*len(rows[0]))
 for col,w in zip(t.columns,widths):col.width=Cm(w)
 pr=t._tbl.tblPr;b=OxmlElement('w:tblBorders')
 for edge in ['top','left','bottom','right','insideH','insideV']:
  el=OxmlElement('w:'+edge);el.set(qn('w:val'),'single');el.set(qn('w:sz'),'4');el.set(qn('w:color'),'D9D9D9');b.append(el)
 pr.append(b)
 for i,vals in enumerate(rows):
  r=t.add_row()
  cant=OxmlElement('w:cantSplit');r._tr.get_or_add_trPr().append(cant)
  if i==0:
   rep=OxmlElement('w:tblHeader');r._tr.get_or_add_trPr().append(rep)
  for j,(c,v)in enumerate(zip(r.cells,vals)):
   c.width=Cm(widths[j]);c.text=v;c.vertical_alignment=1
   cp=c._tc.get_or_add_tcPr();pad=OxmlElement('w:tcMar')
   for edge in ['top','left','bottom','right']:
    el=OxmlElement('w:'+edge);el.set(qn('w:w'),'85');el.set(qn('w:type'),'dxa');pad.append(el)
   cp.append(pad)
   sh=OxmlElement('w:shd');sh.set(qn('w:fill'),'214C7C'if i==0 else('F2F5F8'if i%2==0 else'FFFFFF'));cp.append(sh)
   for p in c.paragraphs:
    p.paragraph_format.keep_with_next=i==0;p.paragraph_format.space_after=Pt(2);p.paragraph_format.line_spacing=1.04
    for run in p.runs:run.font.size=Pt(9.5);run.font.bold=i==0;run.font.color.rgb=RGBColor(255,255,255)if i==0 else RGBColor(0,0,0)
 d.add_paragraph().paragraph_format.space_after=Pt(1)

def section(d,land=False):
 sec=d.add_section(WD_SECTION_START.NEW_PAGE);sec.different_first_page_header_footer=False;sec.orientation=WD_ORIENT.LANDSCAPE if land else WD_ORIENT.PORTRAIT
 sec.page_width=Cm(29.7 if land else 21);sec.page_height=Cm(21 if land else 29.7);sec.top_margin=sec.bottom_margin=Cm(1.8 if land else 2.2);sec.left_margin=sec.right_margin=Cm(2.0 if land else 2.5)
 return sec

def figure(d,key,orient):
 global fig_no,figure_open
 fig_no+=1;v=exports[key];large=key.startswith(('er_','clases_'))or key=='despliegue';im=Image.open(v['path']);wide=large and(im.width/im.height>1.2 or key=='despliegue');land=orient=='landscape'and not large;sec=section(d,land)
 if large:sec.orientation=WD_ORIENT.LANDSCAPE if wide else WD_ORIENT.PORTRAIT;sec.page_width=Cm(42 if wide else 29.7);sec.page_height=Cm(29.7 if wide else 42);sec.left_margin=sec.right_margin=Cm(2);sec.top_margin=sec.bottom_margin=Cm(2)
 if key in preludes:
  for title,level in preludes[key]['headings']:d.add_heading(title,level)
  for text in preludes[key]['paragraphs']:d.add_paragraph(text)
 p=d.add_paragraph(f'Figura {fig_no}  {v["title"]}',style='Caption');p.paragraph_format.keep_with_next=True
 path=Path(v['path']);im=Image.open(path);mxw,mxh=((37.5,22.7)if wide else(25.5,34.5))if large else((25.5,14.5)if land else(16,21.1));mxh=18.5 if key=='despliegue'else mxh;scale=min(mxw/im.width,mxh/im.height)
 p=d.add_paragraph();p.alignment=WD_ALIGN_PARAGRAPH.CENTER;p.paragraph_format.space_after=Pt(5);p.add_run().add_picture(str(path),width=Cm(im.width*scale),height=Cm(im.height*scale))
 p=d.add_paragraph('Fuente: elaboración a partir del backlog completo y del código del proyecto, mediante Enterprise Architect. «propuesto» identifica diseño pendiente de implementación.',style='Caption');p.paragraph_format.line_spacing=1
 figure_open=True

def content(d,path):
 global figure_open,fig_no
 lines=path.read_text(encoding='utf-8-sig').splitlines();i=0
 while i<len(lines):
  s=lines[i].strip();i+=1
  if not s:continue
  if figure_open and not s.startswith('@figure '):
   section(d,False);figure_open=False
  if s.startswith('|'):
   rows=[[c.strip()for c in s.strip('|').split('|')]]
   while i<len(lines)and lines[i].strip().startswith('|'):
    rows.append([c.strip()for c in lines[i].strip().strip('|').split('|')]);i+=1
   table(d,rows);continue
  if s=='@timeline':
   section(d,True)
   d.add_paragraph('Programación de actividades por grupos de procesos PMI',style='Heading 2')
   d.add_paragraph('Cada X indica una semana programada. Las actividades y fechas proceden de la hoja Gestión PMI; no acreditan reuniones ni entregas ya realizadas.')
   from datetime import datetime,timedelta
   from openpyxl import load_workbook
   wb=load_workbook(O/'Product_Backlog_WMS_Segundo_Avance.xlsx',data_only=True);ws=wb['Gestión PMI'];weeks=[ws.cell(3,10+j).value for j in range(8)]
   rows=[['Grupo PMI','Actividad']+[str(j+1)+'\n'+w.strftime('%d/%m')for j,w in enumerate(weeks)]]
   for idx,rec in enumerate(json.loads((A/'pmi_plan.json').read_text(encoding='utf-8'))):
    rows.append([rec[0],rec[1]]+[ws.cell(idx+5,10+j).value or''for j in range(8)])
   table(d,rows,[2.7,8.4]+[1.8]*8);figure_open=True;continue
  if s.startswith('@figure '):figure(d,*s.split()[1:]);continue
  if s=='@frontis':
   p=d.add_paragraph();p.alignment=1;p.paragraph_format.keep_with_next=True;p.add_run().add_picture(str(A/'image2.png'),width=Cm(11))
   fig_no=1
   d.add_paragraph('Figura 1  Frontis proporcionado en el informe de origen. Ubicación de referencia pendiente de verificación documental.',style='Caption');continue
  if s.startswith('@code '):
   p=d.add_paragraph(s[6:]);p.paragraph_format.space_after=Pt(6)
   for r in p.runs:r.font.name='Consolas';r.font.size=Pt(9)
   continue
  if s.startswith('#'):
   n=len(s)-len(s.lstrip('#'));d.add_heading(s[n:].strip(),min(n,5));continue
  p=d.add_paragraph(s)
  if s.startswith('http'):p.style='Caption'

def cover(d,mini=False):
 if not mini:
  p=d.add_paragraph();p.alignment=1;p.add_run().add_picture(str(A/'image1.png'),width=Cm(12))
  for text in ['FACULTAD DE INGENIERÍA','ESCUELA PROFESIONAL DE INGENIERÍA DE SISTEMAS']:
   p=d.add_paragraph(text);p.alignment=1
  p=d.add_paragraph('Sistema Web de Control de Inventario y Gestión de Almacén aplicando métricas de calidad ISO 25010 para CITY OFERTAS IMPORTADORA E.I.R.L.',style='Title');p.alignment=1
  p=d.add_paragraph('Segundo avance académico 2026');p.alignment=1
  p=d.add_paragraph('Integrantes');p.runs[0].bold=True
  for name in ['Aquino Salcedo Maria Fernanda','Carrasco Chavez Yerry Ericson','Cortez Quito Abraham David','Zelada Chinga Eliana Jazmin','Torres Cavero Luis Augusto','Juarez Gabino Sandro David']:
   p=d.add_paragraph(name);p.paragraph_format.space_after=Pt(3)
  d.add_paragraph('Curso  Calidad de Software\nDocente  Dr. Iván Michell Castillo Jiménez\nPiura Perú\nCorte técnico 28 de octubre de 2026')
 else:
  d.add_paragraph('Mini Informe de Validación del Sistema WMS',style='Title');d.add_paragraph('CITY OFERTAS IMPORTADORA E.I.R.L.\nCorte técnico 28 de octubre de 2026')

def build(name,md,mini=False):
 d=Document();setup(d);d.core_properties.title='Mini Informe de Validación del Sistema WMS'if mini else'Sistema WMS Segundo avance académico';d.core_properties.keywords='WMS PMI Scrum ISO 25010 aceptación inventario';d.core_properties.created=datetime.now(timezone.utc);d.core_properties.modified=datetime.now(timezone.utc);cover(d,mini)
 if mini:d.styles['Heading 1'].paragraph_format.page_break_before=False
 if not mini:
  p=d.add_paragraph('ÍNDICE',style='Title');p.paragraph_format.page_break_before=True;p=d.add_paragraph();f=OxmlElement('w:fldSimple');f.set(qn('w:instr'),'TOC \\o "1-2" \\h \\z \\u');p._p.append(f)
 content(d,A/md)
 for el in list(d.element.iter(qn('w:pBdr'))):el.getparent().remove(el)
 d.save(O/name);print(O/name)
build('Informe_Academico_WMS_Segundo_Avance.docx','report_main.md')
build('Mini_Informe_Validacion_WMS.docx','report_mini.md',True)
