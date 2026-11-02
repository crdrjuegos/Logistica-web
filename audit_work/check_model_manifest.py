import json
from pathlib import Path
from PIL import Image
A=Path('audit_work');v=json.loads((A/'model_exports.json').read_text(encoding='utf-8-sig'));print('MODELOS',len(v),len({x['key']for x in v}));print('Todos los PNG existen',all(Path(x['path']).exists()for x in v));print('Cortes',[(x['key'],Image.open(x['path']).size)for x in v if Image.open(x['path']).width<100])
print('Páginas principales de texto y cronograma')
for x in json.loads((A/'qa_main/page_stats.json').read_text(encoding='utf-8')):
 if 'Programación de actividades' in x['text'] or x['page']<=7:print(x['page'],x['text'],x['size'])
