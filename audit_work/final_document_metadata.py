from pathlib import Path
p=Path('audit_work/build_reports.py');s=p.read_text(encoding='utf-8').replace('import json,re','import json,re\nfrom datetime import datetime,timezone')
s=s.replace('d=Document();setup(d);cover(d,mini)', "d=Document();setup(d);d.core_properties.title='Mini Informe de Validación del Sistema WMS'if mini else'Sistema WMS Segundo avance académico';d.core_properties.keywords='WMS PMI Scrum ISO 25010 aceptación inventario';d.core_properties.created=datetime.now(timezone.utc);d.core_properties.modified=datetime.now(timezone.utc);cover(d,mini)")
p.write_text(s,encoding='utf-8');compile(s,'build_reports.py','exec')
