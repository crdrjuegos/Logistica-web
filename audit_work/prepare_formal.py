import json
from pathlib import Path
D=Path('audit_work');src=json.loads((D/'current_source.json').read_text(encoding='utf-8'))
rows=[]
def phase(t):rows.append({'phase':t})
def act(t,weeks):rows.append({'activity':t,'weeks':weeks})
phase('FASE 1. INICIO (PMI)')
for t in ['Identificación de la problemática y necesidad de CITY OFERTAS','Identificación de interesados y área beneficiaria','Elaboración de la visión y objetivo general del WMS','Elaboración del acta o ficha de inicio del proyecto']:act(t,[1])
phase('FASE 2. PLANIFICACIÓN (PMI)')
for t,w in [('Definición del alcance del WMS',[1]),('Identificación y análisis de requerimientos',[1,2]),('Definición del Product Goal',[1]),('Elaboración de épicas, historias de usuario y criterios de aceptación',[1,2]),('Creación y priorización del Product Backlog',[1,2]),('Elaboración del roadmap y planificación general de sprints',[2]),('Identificación de riesgos y acciones de respuesta',[1,2]),('Definición de criterios de calidad y Definition of Done (DoD)',[2])]:act(t,w)
phase('FASE 3. EJECUCIÓN — METODOLOGÍA SCRUM')
for t,w in [('Sprint Planning al inicio de cada sprint',[1,3,5,7]),('Sprint 1 — Cimientos: accesos, catálogo, ledger, arquitectura y herramientas de calidad',[1,2]),('Sprint 2 — Almacén: ubicaciones, proveedores, recepción, transferencias, ajustes, kardex y carga inicial',[3,4]),('Sprint 3 — Control y análisis: conteos, ventas POS, alertas, tablero y reportes',[5,6]),('Sprint 4 — Seguridad, cámara, calidad, respaldos, despliegue, informe y sustentación',[7,8]),('Diseño, desarrollo, pruebas, integración y documentación del incremento',list(range(1,9)))]:act(t,w)
phase('FASE 4. MONITOREO Y CONTROL — SCRUM')
for t,w in [('Daily Scrum y seguimiento del Sprint Goal',list(range(1,9))),('Seguimiento y actualización del Sprint Backlog',list(range(1,9))),('Gestión de impedimentos y seguimiento de riesgos',list(range(1,9))),('Verificación de criterios de aceptación, calidad y Definition of Done',list(range(1,9))),('Sprint Review e inspección del incremento',[2,4,6,8]),('Validación del incremento y retroalimentación de interesados',[2,4,6,8]),('Refinamiento y actualización del Product Backlog',list(range(1,9))),('Seguimiento de métricas de avance, calidad y cumplimiento',list(range(1,9))),('Sprint Retrospective e identificación de oportunidades de mejora',[2,4,6,8]),('Implementación de acciones de mejora del proceso',list(range(2,9)))]:act(t,w)
phase('FASE 5. CIERRE (PMI)')
for t,w in [('Validación final del WMS y consolidación de pruebas de carga, seguridad y calidad',[7,8]),('Consolidación de documentación técnica y funcional; prueba de restauración de respaldo',[7,8]),('Entrega, transferencia y aceptación del producto por la empresa',[8]),('Registro de lecciones aprendidas y cierre de pendientes',[8]),('Presentación final de resultados y conformidad del proyecto',[8])]:act(t,w)
n=0
for r in rows:
 if 'activity'in r:n+=1;r['number']=n
(D/'formal_pmi.json').write_text(json.dumps(rows,ensure_ascii=False,indent=2),encoding='utf-8')
print('Actividades',n,'filas',len(rows)+4)
