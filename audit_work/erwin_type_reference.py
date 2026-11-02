from pypdf import PdfReader
from pathlib import Path
r=PdfReader('C:/Program Files (x86)/CA/ERwin Data Modeler r7.3/Doc/ERwin Metamodel Reference.pdf');out=[]
for i in range(550,783):
 t=r.pages[i].extract_text()or''
 if 'identifying' in t.lower() or 'Type (Integer property)' in t:out.append((i+1,t))
Path('audit_work/erwin_relation_types.txt').write_text('\n'.join('PAGE '+str(i)+'\n'+t for i,t in out),encoding='utf-8')
for i,t in out:
 if 'identifying' in t.lower():print('PAGE',i,t[:6000])
