import zipfile,json
from pathlib import Path
z=zipfile.ZipFile('C:/Users/USUARIO/Downloads/Informe Académico del Proyecto 2026-I.docx')
for n in z.namelist():
 if n.startswith('word/media/'):
  p=Path('audit_work')/Path(n).name;p.write_bytes(z.read(n));print(p)
j=json.loads(Path('vendor/composer/installed.json').read_text(encoding='utf-8'))
for p in j['packages']:
 if p['name'] in ['filament/filament','livewire/livewire','laravel/framework','pestphp/pest']:print(p['name'],p['version'])
