from pathlib import Path
import json,re
import pypdfium2 as pdfium
from pypdf import PdfReader
from PIL import Image,ImageDraw
A=Path('audit_work')
for tag,name in [('nuevo_main','Informe_Academico_WMS_Formato_Docente'),('agregados','Tablas_Agregadas_Informe_WMS')]:
 pdf=pdfium.PdfDocument(A/(name+'.pdf'));reader=PdfReader(A/(name+'.pdf'));out=A/('qa_'+tag);out.mkdir(exist_ok=True);stats=[]
 for i,p in enumerate(pdf):
  p.render(scale=1.25).to_pil().save(out/f'page-{i+1}.png');txt=reader.pages[i].extract_text()or'';stats.append({'page':i+1,'chars':len(txt),'text':txt,'size':[p.get_width(),p.get_height()]})
 for start in range(0,len(pdf),8):
  canvas=Image.new('RGB',(1600,1160),'#B8BDC5');draw=ImageDraw.Draw(canvas)
  for j in range(start,min(start+8,len(pdf))):
   im=Image.open(out/f'page-{j+1}.png');im.thumbnail((385,540));x=(j-start)%4*400+(400-im.width)//2;y=(j-start)//4*580+30;canvas.paste(im,(x,y));draw.text(((j-start)%4*400+10,(j-start)//4*580+8),f'Pagina {j+1}',fill='black')
  canvas.save(out/f'contact-{start//8+1}.jpg')
 (out/'stats.json').write_text(json.dumps(stats,ensure_ascii=False,indent=2),encoding='utf-8')
 print(tag,len(pdf),'paginas; casi vacías',[(r['page'],r['chars'])for r in stats if r['chars']<130])
 print('HU',[(r['page'],re.findall(r'ID: (HU-\d+)',r['text']))for r in stats if 'Historia de Usuario'in r['text']][:5])
