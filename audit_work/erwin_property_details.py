from pathlib import Path
s=Path('audit_work/erwin_meta_relevant.txt').read_text(encoding='utf-8')
for page in [288,297,476,477,478,479]:
 start=s.find('PAGE '+str(page)+'\n');end=s.find('PAGE ',start+5)
 if start>=0: print(s[start:end if end>=0 else None][:4000])
