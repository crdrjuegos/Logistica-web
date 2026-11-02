from pathlib import Path
import json
import pypdfium2 as pdfium
from pypdf import PdfReader
from PIL import Image,ImageOps,ImageDraw
A=Path('audit_work')
for tag,name in [('main','Informe_Academico_WMS_Segundo_Avance'),('mini','Mini_Informe_Validacion_WMS')]:
 pdf=pdfium.PdfDocument(A/(name+'.pdf'));reader=PdfReader(A/(name+'.pdf'));out=A/('qa_'+tag);out.mkdir(exist_ok=True);info=[]
 for i,p in enumerate(pdf):
  p.render(scale=1.3).to_pil().save(out/f'page-{i+1}.png');txt=reader.pages[i].extract_text()or'';info.append({'page':i+1,'chars':len(txt),'text':txt[:160],'images':len(reader.pages[i].images),'size':[p.get_width(),p.get_height()]})
 for start in range(0,len(pdf),8):
  canvas=Image.new('RGB',(1600,1160),'#B8BDC5');draw=ImageDraw.Draw(canvas)
  for j in range(start,min(start+8,len(pdf))):
   im=Image.open(out/f'page-{j+1}.png');im.thumbnail((385,540));x=(j-start)%4*400+(400-im.width)//2;y=(j-start)//4*580+30;canvas.paste(im,(x,y));draw.text(((j-start)%4*400+10,(j-start)//4*580+8),f'Pagina {j+1}',fill='black')
  canvas.save(out/f'contact-{start//8+1}.jpg')
 (out/'page_stats.json').write_text(json.dumps(info,ensure_ascii=False,indent=2),encoding='utf-8')
 print(tag,len(pdf),'paginas',[(x['page'],x['chars'],x['images'])for x in info if x['chars']<140])
