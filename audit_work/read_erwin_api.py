import sys
from pathlib import Path
from pypdf import PdfReader
p=Path('C:/Program Files (x86)/CA/ERwin Data Modeler r7.3/Doc/ERwin_API_Ref.pdf')
r=PdfReader(p)
t='\n'.join(f'PAGE {i+1}\n'+(x.extract_text() or '') for i,x in enumerate(r.pages))
Path('audit_work/erwin_api.txt').write_text(t,encoding='utf-8')
print('ERwin API pages',len(r.pages))
for token in ['CreateObject','PersistenceUnits.Add','Sessions.Add','ModelObjects.Add','Save(','SCAPI']:
 print('\nTOKEN',token)
 pos=t.find(token); print(t[max(0,pos-120):pos+1500])
