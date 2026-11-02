import re
from pathlib import Path
text=Path('audit_work/erwin_api.txt').read_text(encoding='utf-8')
for a,b in [(47,49),(81,85),(59,62)]:
 print(text[text.find('PAGE '+str(a)+'\n'):text.find('PAGE '+str(b)+'\n')])
