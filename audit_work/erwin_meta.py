from pathlib import Path
from pypdf import PdfReader
r=PdfReader('C:/Program Files (x86)/CA/ERwin Data Modeler r7.3/Doc/ERwin Metamodel Reference.pdf')
print('PAGES',len(r.pages))
for i in range(min(12,len(r.pages))):
 t=r.pages[i].extract_text() or ''
 if 'Relationship' in t or 'Entity' in t: print(i+1,t[-5000:])
