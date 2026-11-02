from pathlib import Path
import json,zipfile,hashlib,csv
A=Path('audit_work');O=Path('outputs/avance2_2026_10_28').resolve();manifest=json.loads((A/'model_exports.json').read_text(encoding='utf-8-sig'));files=[O/'LEEME_Modelos_Evidencias.md',O/'modelos/Diseno_Integral_WMS.eap',O/'modelos/Modelo_Datos_Entidades_Claves.erwin']+[Path(v['path'])for v in manifest]+list((O/'modelos').glob('*.csv'))+[O/'modelos/Diccionario_Datos_WMS.html']
source_evidence=['pest-junit.xml','pest-docker.log','coverage.xml','coverage.txt','coverage-junit.xml','coverage-docker.log','pint-docker.log','phpstan-docker.log','deptrac-docker.log','composer-validate.log','acceptance_results.json','sql_acceptance_results.json','acceptance_probe.php.txt','sql_acceptance.php.txt','acceptance_probe_docker.log','sql_acceptance.log','test_stats.json','class_model.json','model_exports.json']
items=[]
for p in files:
 assert p.exists(),p
 items.append((p,p.relative_to(O).as_posix()))
for n in source_evidence:
 p=A/n;assert p.exists(),p;items.append((p,'evidencias/'+n))
checksum='\n'.join(hashlib.sha256(p.read_bytes()).hexdigest()+'  '+name for p,name in items)+'\n';(O/'SHA256_Modelos_Evidencias.txt').write_text(checksum,encoding='utf-8')
zpath=O/'Modelos_y_Evidencias_WMS.zip'
with zipfile.ZipFile(zpath,'w',zipfile.ZIP_DEFLATED)as z:
 for p,n in items:z.write(p,n)
 z.write(O/'SHA256_Modelos_Evidencias.txt','SHA256_Modelos_Evidencias.txt')
with zipfile.ZipFile(zpath)as z:
 assert z.testzip()is None
 print('PAQUETE',len(z.namelist()),'archivos',zpath.stat().st_size,'bytes',len(manifest),'diagramas')
