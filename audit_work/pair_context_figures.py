from pathlib import Path
import json,re
p=Path('audit_work/report_main.md');s=p.read_text(encoding='utf-8');pre={}
line=next(x for x in s.splitlines()if x.startswith('Las vistas complementarias detallan'))
s=s.replace(line+'\n','');s=s.replace('@figure clases_inventario landscape',line+'\n@figure clases_inventario landscape')
pat=r'##### 5\.2\.3\.1\.4 Estados del conteo\n(.*?)\n@figure estados_conteo landscape'
m=re.search(pat,s,re.S);assert m;pre['estados_conteo']={'headings':[['5.2.3.1.4 Estados del conteo',5]],'paragraphs':[x for x in m[1].splitlines()if x.strip()]};s=s[:m.start()]+'@figure estados_conteo landscape'+s[m.end():]
pat=r'#### 5\.2\.3\.2 Arquitectura física\n##### 5\.2\.3\.2\.1 Diagrama de despliegue\n(.*?)\n@figure despliegue landscape'
m=re.search(pat,s,re.S);assert m;pre['despliegue']={'headings':[['5.2.3.2 Arquitectura física',4],['5.2.3.2.1 Diagrama de despliegue',5]],'paragraphs':[x for x in m[1].splitlines()if x.strip()]};s=s[:m.start()]+'@figure despliegue landscape'+s[m.end():]
p.write_text(s,encoding='utf-8');Path('audit_work/figure_preludes.json').write_text(json.dumps(pre,ensure_ascii=False),encoding='utf-8')
p=Path('audit_work/build_reports.py');s=p.read_text(encoding='utf-8');s=s.replace('fig_no=0',"preludes=json.loads((A/'figure_preludes.json').read_text(encoding='utf-8'))\nfig_no=0")
s=s.replace("large=key.startswith(('er_','clases_'));", "large=key.startswith(('er_','clases_'))or key=='despliegue';")
s=s.replace("wide=large and im.width/im.height>1.2;", "wide=large and(im.width/im.height>1.2 or key=='despliegue');")
s=s.replace(" p=d.add_paragraph(f'Figura {fig_no}"," if key in preludes:\n  for title,level in preludes[key]['headings']:d.add_heading(title,level)\n  for text in preludes[key]['paragraphs']:d.add_paragraph(text)\n p=d.add_paragraph(f'Figura {fig_no}")
s=s.replace("scale=min(mxw/im.width,mxh/im.height)","mxh=18.5 if key=='despliegue'else mxh;scale=min(mxw/im.width,mxh/im.height)")
p.write_text(s,encoding='utf-8');compile(s,'build_reports.py','exec')
