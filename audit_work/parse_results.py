import json,xml.etree.ElementTree as E
from pathlib import Path
r=E.parse('audit_work/coverage.xml').getroot();p=r.find('project');m=p.find('metrics');print('COVERAGE',m.attrib)
r=E.parse('audit_work/pest-junit.xml').getroot();stats=[]
for s in r.iter('testsuite'):
 if list(s.findall('testcase')):stats.append({'name':s.attrib['name'],'tests':int(s.attrib.get('tests',0)),'assertions':int(s.attrib.get('assertions',0)),'time':float(s.attrib.get('time',0))})
Path('audit_work/test_stats.json').write_text(json.dumps({'coverage':m.attrib,'suites':stats},ensure_ascii=False,indent=2),encoding='utf-8')
print('SUITES',len(stats),'TOTAL',sum(x['tests'] for x in stats))
