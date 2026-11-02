from pathlib import Path
p=Path('audit_work/acceptance_probe.php');t=p.read_text(encoding='utf-8-sig');t=t.replace('eq(count($r->rows),1);});\nprobe(\'CA-012','eq(count($r->products),1);});\nprobe(\'CA-012',1).replace('$r->stock[$id]=true;','$r->stock[$id]=\'1.000\';').replace('$r->find($id)->status','$r->products[$id]->status');p.write_text(t,encoding='utf-8')
