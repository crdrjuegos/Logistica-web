from pathlib import Path
import json
A=Path('audit_work');d=json.loads((A/'review_data.json').read_text(encoding='utf-8'));weights={'Implementado':1,'Parcial':.5,'Pendiente':0};out={}
for layer,excluded in [('backend',[54,56]),('frontend',[17,18,19,20,21,23])]:
 stories=[s for s in d['stories']if s['number']<=56 and s['number']not in excluded];total=sum(s['points']for s in stories);achieved=sum(s['points']*weights[s['technical']]for s in stories)
 out[layer]={'stories':len(stories),'total_points':total,'equivalent_points':achieved,'estimate_percent':round(100*achieved/total,2),'excluded_ids':excluded}
out['method']='Estimación técnica por historias funcionales HU001-HU056 aplicables a cada capa. Peso 1 para implementado, 0.5 parcial y 0 pendiente; ponderación por puntos del backlog. Se excluyen cámara y captura móvil del backend y las invariantes y conciliación programada de la interfaz. No representa aceptación empresarial ni porcentaje de código terminado.'
(A/'progress_estimate_private.json').write_text(json.dumps(out,ensure_ascii=False,indent=2),encoding='utf-8');print(out)
