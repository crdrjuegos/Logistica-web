from pathlib import Path
from pypdf import PdfReader
r=PdfReader('C:/Program Files (x86)/CA/ERwin Data Modeler r7.3/Doc/ERwin Metamodel Reference.pdf')
parts=[]
for i,p in enumerate(r.pages):
 t=p.extract_text() or ''
 if any(x in t for x in ['Parent_Entity_Ref','Child_Entity_Ref','Key_Group_Type','Logical_Data_Type','Attribute_Order','Relationship_Type']):parts.append((i+1,t))
Path('audit_work/erwin_meta_relevant.txt').write_text('\n'.join('PAGE '+str(n)+'\n'+t for n,t in parts),encoding='utf-8')
for n,t in parts:
 for ln in t.splitlines():
  if any(x in ln for x in ['Parent_Entity_Ref','Child_Entity_Ref','Key_Group_Type','Logical_Data_Type','Attribute_Order','Relationship_Type']):print(n,ln)
