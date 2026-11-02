import fs from 'node:fs/promises';
import {FileBlob,SpreadsheetFile} from '@oai/artifact-tool';
const data=JSON.parse(await fs.readFile('audit_work/review_data.json','utf8'));
const w=await SpreadsheetFile.importXlsx(await FileBlob.load('C:/Users/USUARIO/Downloads/Product_Backlog_WMS_Fase_Inicial_Cronograma.xlsx'));
const out='outputs/avance2_2026_10_28';
const criteria=w.worksheets.getItem('Criterios de Aceptación');
const original=data.source.find(s=>s.sheet==='Criterios de Aceptación');
for(const m of original.merged){const r=Number(m.match(/\d+/)[0]);if(r>=6)criteria.unmergeCells(m);}
criteria.getRange('B6:U250').clear({applyTo:'all'});
const headers=['ID Historia','Rol','Deseo','Para','Escenario','ID Criterio','Criterio de aceptación','Contexto dado','Evento cuando','Resultado esperado entonces','Valores de entrada','Pasos de validación','Clase o componente responsable','Pruebas relacionadas','Nivel de prueba','Resultado al corte','Resultado observado','Observaciones y pendientes'];
criteria.getRange('B5:S5').values=[headers];
criteria.getRange('B2').values=[['Corte 28/10/2026. 63 historias y 191 escenarios. Conforme en dominio no equivale a aceptación empresarial ni validación completa de interfaz.']];
const by=new Map(data.stories.map(s=>[s.id,s]));
const rows=data.criteria.map(c=>{const s=by.get(c.story);return [c.story,s.role,s.want,s.why,c.seq,c.id,c.title,c.context,c.event,c.expected,c.input,c.steps,c.classes,c.test_ref,c.level,c.status,c.observed,c.notes];});
criteria.getRange(`B6:S${5+rows.length}`).values=rows;
criteria.getRange(`B5:S${5+rows.length}`).format.font={name:'Arial',size:10,color:'#172A3A'};
criteria.getRange('B5:S5').format={fill:'#214C7C',font:{name:'Arial',size:10,bold:true,color:'#FFFFFF'},rowHeight:44,wrapText:true,horizontalAlignment:'center',verticalAlignment:'center'};
criteria.getRange(`B6:S${5+rows.length}`).format.wrapText=true;
criteria.getRange(`B6:S${5+rows.length}`).format.verticalAlignment='top';
const widths={B:14,C:24,D:42,E:42,F:10,G:17,H:32,I:49,J:42,K:61,L:54,M:48,N:57,O:70,P:25,Q:30,R:60,S:66};
for(const [col,width]of Object.entries(widths))criteria.getRange(`${col}5:${col}${5+rows.length}`).format.columnWidth=width;
criteria.getRange(`B6:S${5+rows.length}`).format.rowHeight=78;
criteria.getRange(`F6:G${5+rows.length}`).format.horizontalAlignment='center';
criteria.tables.add(`B5:S${5+rows.length}`,true,'CriteriosValidacion');
criteria.getRange(`Q6:Q${5+rows.length}`).dataValidation={rule:{type:'list',values:['Conforme en dominio','Conforme en integración','No conforme','Caso específico no ejecutado','Pendiente de implementar','Aceptado por usuario']}};
criteria.getRange(`Q6:Q${5+rows.length}`).conditionalFormats.add('containsText',{text:'No conforme',format:{fill:'#FCE8E6',font:{color:'#9C2525',bold:true}}});
criteria.getRange(`Q6:Q${5+rows.length}`).conditionalFormats.add('containsText',{text:'Pendiente',format:{fill:'#FFF2CC',font:{color:'#78520A'}}});
criteria.freezePanes.freezeRows(5);criteria.freezePanes.freezeColumns(2);criteria.showGridLines=false;
const last=5+rows.length;
const back=w.worksheets.getItem('Backlog del Producto');
back.getRange('H3').values=[['Fase inicial 19/10/2026 al 10/12/2026: 60 HU; 3 HU futuras. Estado técnico y verificación separados de aceptación final.']];
back.getRange('Q6:R6').values=[['Estado técnico','Criterios conformes al corte']];
back.getRange('Q6:R81').format.font={name:'Arial',size:10};
back.getRange('Q6:R6').format={fill:'#214C7C',font:{color:'#FFFFFF',bold:true},rowHeight:44,wrapText:true};
back.getRange('Q7:Q81').format.columnWidth=20;back.getRange('R7:R81').format.columnWidth=24;
back.getRange('P7:P81').format.columnWidth=76;back.getRange('P7:R81').format.wrapText=true;
for(const s of data.stories){
 const n=s.row;back.getRange(`G${n}`).values=[[s.role]];
 back.getRange(`J${n}`).values=[[data.criteria.filter(c=>c.story===s.id).map(c=>c.id).join(', ')]];
 back.getRange(`O${n}`).values=[[s.status]];back.getRange(`P${n}`).values=[[s.notes+' Fuentes: '+(s.sources.join('; ')||s.classes)+'. Pruebas: '+(s.tests.join('; ')||'sin evidencia específica')]];
 back.getRange(`Q${n}`).values=[[s.technical]];
 back.getRange(`R${n}`).formulas=[[`=IFERROR((COUNTIFS('Criterios de Aceptación'!$B$6:$B${last},F${n},'Criterios de Aceptación'!$Q$6:$Q${last},"Conforme en dominio")+COUNTIFS('Criterios de Aceptación'!$B$6:$B${last},F${n},'Criterios de Aceptación'!$Q$6:$Q${last},"Conforme en integración"))/COUNTIF('Criterios de Aceptación'!$B$6:$B${last},F${n}),0)`]];
 back.getRange(`R${n}`).setNumberFormat('0.0%');back.getRange(`B${n}:R${n}`).format.rowHeight=126;
}
back.freezePanes.freezeRows(6);back.freezePanes.freezeColumns(6);
back.getRange('J7:J81').format.wrapText=true;back.getRange('J7:J81').format.columnWidth=36;
w.worksheets.getItem('Sprints').getRange('H2').values=[['Fecha de revisión prevista']];
const sprPlan=w.worksheets.getItem('Sprints');sprPlan.getRange('F2').values=[['Estimación SP']];
for(const [i,dt]of ['2026-10-30','2026-11-13','2026-11-27','2026-12-10'].entries())sprPlan.getRange(`H${i+3}`).values=[[new Date(dt+'T12:00:00Z')]];
for(const c of ['C','E','H'])sprPlan.getRange(`${c}3:${c}6`).setNumberFormat('dd/mm/yyyy');
sprPlan.getRange('I4').values=[['Operación de almacén: ubicaciones y proveedores, recepciones, transferencias, ajustes con aprobación, kardex y carga inicial de datos con pruebas definidas.']];
const tasks=w.worksheets.getItem('Tareas');
tasks.getRange('B2').values=[['Estimaciones y fechas planificadas conservadas. Pruebas existentes no acreditan por sí solas la secuencia histórica TDD. Responsables nominales pendientes de confirmación.']];
tasks.getRange('K4').values=[['Evidencia y alcance del estado']];tasks.getRange('K4').format={fill:'#D9E2F3',font:{name:'Arial',size:10,bold:true},wrapText:true};tasks.getRange('K5:K184').format.font={name:'Arial',size:10};tasks.getRange('K5:K184').format.verticalAlignment='center';tasks.getRange('K4:K184').format.columnWidth=72;tasks.getRange('K4:K184').format.wrapText=true;
const taskSource=data.source.find(s=>s.sheet==='Tareas');
const taskRows=[];
for(const rec of taskSource.data){const v=rec.values;if(rec.row<5||!by.has(v[2]))continue;const s=by.get(v[2]);const kind=String(v[0]).slice(-2);let state='Por Hacer';
 if(s.technical!=='Pendiente')state=(kind==='01'&&s.tests.length)||(kind==='02'&&s.technical==='Implementado')?'Terminado':'En Progreso';
 const desc=String(v[1]).replace('Escribir pruebas (Rojo):','Diseñar y ejecutar pruebas relacionadas:').replace('Implementar lo mínimo (Verde):','Implementar el comportamiento:');
 tasks.getRange(`B${rec.row}`).values=[[desc]];tasks.getRange(`G${rec.row}`).values=[[state]];
 tasks.getRange(`K${rec.row}`).values=[[kind==='01'&&s.tests.length?'Suite relacionada ejecutada en Docker; los escenarios adicionales tienen su resultado individual en Criterios de Aceptación.':s.notes]];
 tasks.getRange(`A${rec.row}:K${rec.row}`).format.rowHeight=64;taskRows.push({...rec,state,desc});
}
tasks.freezePanes.freezeRows(4);tasks.freezePanes.freezeColumns(3);
w.worksheets.getItem('Sprint 1').delete();const sprint=w.worksheets.add('Sprint 1');sprint.index=4;sprint.showGridLines=false;sprint.tabColor='#F4B183';const st=taskRows.filter(r=>r.values[4]===1);const sl=21+st.length;
sprint.getRange('B2:W3').merge();sprint.getRange('B2').values=[['CITY OFERTAS WMS Sprint 1']];sprint.getRange('B2:W3').format={fill:'#214C7C',font:{name:'Arial',size:18,bold:true,color:'#FFFFFF'},verticalAlignment:'center'};
sprint.getRange('B4:C4').merge();sprint.getRange('B4').values=[['Meta del sprint']];sprint.getRange('D4:W5').merge();sprint.getRange('D4').values=[['Cimientos: libro mayor de inventario, accesos y catálogo base, arquitectura y controles de calidad.']];sprint.getRange('B4:W5').format={font:{name:'Arial',size:11},wrapText:true,rowHeight:25};
sprint.getRange('B6:G6').merge();sprint.getRange('B6').values=[['19/10/2026 al 01/11/2026 · Revisión prevista 30/10/2026']];sprint.getRange('B7:W7').merge();sprint.getRange('B7:W7').format={font:{name:'Arial',size:10},wrapText:true,rowHeight:32};
sprint.getRange('B9:G10').merge();sprint.getRange('B9').values=[['Planificación del sprint. Las tareas y horas se conservan del archivo de origen. Los responsables nominales siguen pendientes de asignación.']];sprint.getRange('B12:G14').merge();sprint.getRange('B12').values=[['No hay mediciones diarias de esfuerzo restante. La serie real del gráfico queda vacía hasta disponer de registros comprobables.']];sprint.getRange('B9:G14').format={font:{name:'Arial',size:10},wrapText:true,verticalAlignment:'center'};
sprint.getRange('B:B').format.columnWidth=15;sprint.getRange('D:D').format.columnWidth=15;sprint.getRange('G:G').format.columnWidth=12;sprint.getRange('H:W').format.columnWidth=5;
sprint.getRange('B16:AP100').clear({applyTo:'all'});sprint.getRange('B16:AP100').formulas=Array.from({length:85},()=>Array(41).fill(null));sprint.getRange('B16:U90').format.font={name:'Arial',size:10};
sprint.getRange('C16:D17').values=[['Duración del sprint',14],['Tareas incluidas',st.length]];for(const [nm,ref]of Object.entries({TaskRows:"'Sprint 1'!$D$17",ImplementationDays:"'Sprint 1'!$D$16",TotalEffort:"'Sprint 1'!$G$17",DoneDays:"'Sprint 1'!$AK$14",TrendDays:"'Sprint 1'!$AL$14"})){try{w.names.getItem(nm).delete();}catch{}w.names.addRange(nm,ref);}
sprint.getRange('AK14').formulas=[['=COUNT($AI$9:$AI$22)']];sprint.getRange('AL14').values=[[5]];
sprint.getRange('F17').values=[['Total horas estimadas']];sprint.getRange('G17').formulas=[[`=SUM(G22:G${sl})`]];
sprint.getRange('AK14').formulas=[['=COUNT($AI$9:$AI$22)']];
sprint.getRange('B21:U21').format={fill:'#FCE4D6',font:{name:'Arial',size:10,bold:true},rowHeight:30,wrapText:true,verticalAlignment:'center'};sprint.getRange('C16:G17').format.fill='#FCE4D6';sprint.getRange(`F22:F${sl}`).dataValidation={rule:{type:'list',values:['Terminado','En Progreso','Por Hacer']}};sprint.getRange('B21:G21').values=[['ID Tarea','Tarea','ID Historia','Responsable','Estado','Est. h']];
for(let i=0;i<st.length;i++){const r=st[i];sprint.getRange(`B${22+i}:G${22+i}`).format.fill=r.state==='Terminado'?'#E2F0D9':r.state==='En Progreso'?'#FFF2CC':'#FFFFFF';sprint.getRange(`B${22+i}:G${22+i}`).values=[[r.values[0],r.desc,r.values[2],r.values[5],r.state,r.values[7]]];}
sprint.getRange(`B21:G${sl}`).format.wrapText=true;sprint.getRange(`B22:G${sl}`).format.rowHeight=56;sprint.getRange(`B22:U${sl}`).format.verticalAlignment='center';sprint.getRange('B18:W20').format.rowHeight=6;for(let r=22;r<=sl;r++)sprint.getRange(`B${r}:U${r}`).format.borders={bottom:{style:'thin',color:'#D9D9D9'}};for(let j=0;j<14;j++){const c=String.fromCharCode(72+j);sprint.getRange(`${c}21:${c}${sl}`).format.borders={left:{style:'thin',color:'#D9D9D9'},right:{style:'thin',color:'#D9D9D9'}};}sprint.getRange('AK13:AL13').values=[['Registros reales','Ventana días']];
sprint.getRange('C21:C90').format.columnWidth=58;sprint.getRange('E21:E90').format.columnWidth=20;sprint.getRange('F21:F90').format.columnWidth=20;
sprint.getRange('V21:AF90').clear({applyTo:'all'});sprint.getRange('B7').values=[['Sprint 1 del 19/10 al 01/11. Revisión planificada 30/10. Curva planificada; no se inventan registros diarios reales.']];
sprint.getRange('H17:U17').values=[Array(14).fill(null)];
for(let d=0;d<14;d++){const col=String.fromCharCode(72+d);sprint.getRange(`${col}21`).values=[[d+1]];}
sprint.getRange('AG8:AI22').clear({applyTo:'all'});
sprint.getRange('AG8:AI8').values=[['Día','Plan restante h','Real restante h']];
for(let d=0;d<14;d++){sprint.getRange(`AG${9+d}`).values=[[d+1]];sprint.getRange(`AH${9+d}`).formulas=[[`=$G$17*(14-AG${9+d})/13`]];}
sprint.getRange('AK8:AL11').values=[['Estado','Tareas'],['Terminado',null],['En Progreso',null],['Por Hacer',null]];
for(let r=9;r<=11;r++)sprint.getRange(`AL${r}`).formulas=[[`=COUNTIF($F$22:$F$${sl},AK${r})`]];
sprint.charts.deleteAll();
const c1=sprint.charts.add('line',sprint.getRange('AG8:AI22'));c1.title='Esfuerzo planificado del sprint';c1.setPosition('H8','O16');c1.hasLegend=true;
const c2=sprint.charts.add('bar',sprint.getRange('AK8:AL11'));c2.title='Estado de las tareas al corte';c2.setPosition('P8','W16');c2.hasLegend=false;
sprint.freezePanes.freezeRows(21);sprint.freezePanes.freezeColumns(4);
const cron=w.worksheets.getItem('Cronograma');cron.getRange('G5').values=[['% verificación media por HU']];cron.getRange('G5').format.wrapText=true;cron.getRange('G5:G38').format.columnWidth=24;
for(let r=7;r<=10;r++)cron.getRange(`G${r}`).formulas=[[`=IFERROR(SUMIF('Backlog del Producto'!$N$7:$N$81,C${r},'Backlog del Producto'!$R$7:$R$81)/COUNTIF('Backlog del Producto'!$N$7:$N$81,C${r}),0)`]];
for(let r=11;r<=22;r++){const epic='EPIC'+String(r-10).padStart(2,'0');const ss=data.stories.filter(s=>s.epic===epic);const sum=ss.reduce((a,s)=>a+data.criteria.filter(c=>c.story===s.id&&c.status.startsWith('Conforme')).length/data.criteria.filter(c=>c.story===s.id).length,0);cron.getRange(`G${r}`).formulas=[[`=IFERROR((${ss.map(s=>'\'Backlog del Producto\'!R'+s.row).join('+')})/${ss.length},0)`]];cron.getRange(`F${r}`).values=[[ss.some(s=>s.technical!=='Pendiente')?'En Progreso':'Por Hacer']];}
cron.getRange('F6').values=[['En Progreso']];cron.getRange('F23').values=[['En Progreso']];cron.getRange('G23:G27').clear({applyTo:'all'});cron.getRange('G23:G27').formulas=Array.from({length:5},()=>[null]);
cron.getRange('A39').values=[['Verificación media: proporción de criterios conformes de cada HU, promediada por grupo. No equivale a avance físico ni aceptación empresarial. Fechas conservan la planificación.']];
cron.getRange('A39').format.font={italic:true,size:10};cron.getRange('G6:G38').setNumberFormat('0.0%');
const indicators=w.worksheets.getItem('Indicaciones');
const extra=[['Resultado al corte','Conforme en dominio: comprobación ejecutada con dobles en memoria. Conforme en integración: comprobación ejecutada contra PostgreSQL. Caso específico no ejecutado: hay código o pruebas relacionadas, pero no evidencia exacta de esa entrada.'],['Aceptación empresarial','Una HU se cierra cuando todos sus criterios aplicables y la DoD se cumplen, con validación de la persona responsable. No se acredita esa aceptación a partir del número de archivos o pantallas.'],['Uso y actualización','Filtrar por ID de historia, resultado y nivel. Registrar dato observado y evidencia antes de cambiar un criterio. Conservar negativos y límites aunque no estén implementados.'],['Planificación y TDD','Fechas y horas son estimaciones del archivo original. La existencia de pruebas no demuestra que se escribieran primero. No se reconstruyen reuniones, firmas ni mediciones históricas.'],['Estado técnico','Implementado: componente localizado con pruebas relacionadas. Parcial: brecha funcional o de validación relevante. Pendiente: falta flujo completo. Este estado no sustituye el resultado de aceptación.'],['Trazabilidad','Fuentes locales del proyecto en clases y pruebas; resultados del corte 28/10/2026 en las evidencias de auditoría. Casos con valores nuevos deben ejecutarse de manera específica.']];
indicators.getRange('B16:C21').values=extra;indicators.getRange('B16:C21').format.wrapText=true;indicators.getRange('C16:C21').format.columnWidth=112;indicators.getRange('B16:C21').format.rowHeight=64;
const pm=w.worksheets.add('Gestión PMI');pm.getRange('B2').values=[['Gestión del proyecto y evidencias por grupo de procesos']];pm.getRange('B2').format.font={name:'Arial',size:14,bold:true};
pm.getRange('B4:I4').values=[['Grupo PMI','Actividad','Evidencia o entregable','Referencias','Inicio previsto','Fin previsto','Responsable por rol','Estado de evidencia']];
const pmi=[['Inicio','Identificar necesidad e interesados','Problema, visión, objetivo e interesados en informe','Capítulos 1 y 2','2026-10-19','2026-10-23','Equipo y representante de empresa','Diagnóstico documental; acta y validación de campo pendientes'],['Planificación','Definir alcance y Product Goal','Alcance y exclusiones, backlog y prioridades','63 HU; 60 en fase inicial','2026-10-19','2026-10-30','Product Owner y Developers','Revisado al corte; priorización con empresa pendiente'],['Planificación','Refinar historias y aceptación','191 criterios con valores, clase y prueba','Criterios de Aceptación','2026-10-19','2026-12-10','Product Owner y QA','Documento revisado; ejecución individual según resultado'],['Planificación','Planificar sprints y calidad','Cuatro sprints, riesgos, DoD y plan de calidad','Sprints; Cronograma; capítulo 4','2026-10-19','2026-10-30','Scrum Master y Developers','Plan propuesto; confirmar responsables nominales'],['Ejecución','Sprint Planning al inicio de cada sprint','Sprint Goal y selección de tareas','S1 19/10; S2 02/11; S3 16/11; S4 30/11','2026-10-19','2026-11-30','Scrum Team','Planificación en Excel; actas de reuniones no aportadas'],['Ejecución','Diseñar e implementar incrementos','Módulos, panel, esquema y modelos UML y ER','HU001 a HU046 y HU048 a HU051 según alcance','2026-10-19','2026-12-10','Developers','Componentes localizados; brechas en estado técnico'],['Ejecución','Ejecutar pruebas e integrar','Pest, Pint, PHPStan y Deptrac; comprobaciones adicionales','Capítulo 6 y evidencia técnica','2026-10-19','2026-12-10','Developers y QA','Ejecución local en Docker al 28/10/2026'],['Monitoreo y Control','Inspeccionar avance y riesgos','Daily, impedimentos y actualización de tareas','Tareas y matriz de riesgos','2026-10-19','2026-12-10','Developers y Scrum Master','Estados revisados; no hay actas de Daily verificadas'],['Monitoreo y Control','Verificar aceptación y DoD','Resultados por criterio, defectos y revalidación','191 escenarios; D01 y demás hallazgos','2026-10-19','2026-12-10','QA y Product Owner','Verificación parcial documentada, sin aceptación empresarial final'],['Monitoreo y Control','Review y Retrospective de cada sprint','Demostración, feedback y acciones de mejora','30/10; 13/11; 27/11; 10/12','2026-10-30','2026-12-10','Scrum Team e interesados','Eventos futuros al corte; registros pendientes'],['Cierre','Validar y transferir el producto','Acta de aceptación, manuales y respaldo restaurado','HU060; HU061; HU063; capítulos finales','2026-12-01','2026-12-10','Empresa y equipo','Planificado; no se declara realizado'],['Cierre','Registrar lecciones y pendientes','Lecciones aprendidas, entrega y conformidad','Cierre del proyecto','2026-12-08','2026-12-10','Scrum Team y representante','Planificado']];
pm.getRange('B5:I16').values=pmi.map(r=>r.map((x,i)=>i===4||i===5?new Date(x+'T12:00:00Z'):x));pm.getRange('F5:G16').setNumberFormat('dd/mm/yyyy');
pm.getRange('B4:I16').format.font={name:'Arial',size:10};pm.getRange('B4:I4').format={fill:'#214C7C',font:{bold:true,color:'#FFFFFF'},wrapText:true,rowHeight:36};pm.getRange('B5:I16').format.wrapText=true;pm.getRange('B5:I16').format.rowHeight=64;pm.getRange('B5:I16').format.verticalAlignment='center';
for(const [col,wi]of Object.entries({B:25,C:36,D:58,E:47,F:15,G:15,H:31,I:62}))pm.getRange(`${col}4:${col}16`).format.columnWidth=wi;
pm.tables.add('B4:I16',true,'GestionPMI');pm.freezePanes.freezeRows(4);pm.showGridLines=false;
await fs.writeFile('audit_work/pmi_plan.json',JSON.stringify(pmi));
pm.getRange('J2').values=[['Programación prevista por semana. X no acredita ejecución.']];
pm.getRange('J3:Q3').values=[Array.from({length:8},(_,i)=>new Date(Date.UTC(2026,9,19+7*i,0)))];pm.getRange('J3:Q3').setNumberFormat('dd/mm');
pm.getRange('J4:Q4').values=[Array.from({length:8},(_,i)=>'Semana '+(i+1))];
pm.getRange('J4:Q4').format={fill:'#214C7C',font:{name:'Arial',size:10,bold:true,color:'#FFFFFF'},horizontalAlignment:'center',verticalAlignment:'center',wrapText:true};
pm.getRange('J3:Q16').format.columnWidth=12;pm.getRange('J5:Q16').format={font:{name:'Arial',size:10,bold:true},horizontalAlignment:'center',verticalAlignment:'center'};
for(let r=5;r<=16;r++)for(let j=0;j<8;j++){const col=String.fromCharCode(74+j);pm.getRange(`${col}${r}`).formulas=[[`=IF(AND($F${r}<=${col}$3+6,$G${r}>=${col}$3),"X","")`]];}
for(let j=0;j<8;j++){const col=String.fromCharCode(74+j);for(const [r,sc]of [[9,'C'],[14,'H']])pm.getRange(`${col}${r}`).formulas=[[`=IF(COUNTIFS('Sprints'!$${sc}$3:$${sc}$6,">="&${col}$3,'Sprints'!$${sc}$3:$${sc}$6,"<"&${col}$3+7)>0,"X","")`]];}
pm.getRange('J5:Q16').conditionalFormats.add('containsText',{text:'X',format:{fill:'#FFF2CC'}});
w.recalculate();
console.log((await w.inspect({kind:'match',searchTerm:'#REF!|#DIV/0!|#VALUE!|#NAME\\?|#NUM!|#NULL!|#SPILL!|#CALC!',options:{useRegex:true,maxResults:30},summary:'Errores de fórmula al cierre',maxChars:3000})).ndjson);
console.log((await w.inspect({kind:'table',range:"'Backlog del Producto'!Q6:R12",include:'values,formulas',tableMaxRows:7,tableMaxCols:2,maxChars:2000})).ndjson);
for(const [name,range,key]of [['Backlog del Producto','F6:R9','backlog'],['Sprints','B2:I6','sprints'],['Cronograma','A4:O14','cronograma'],['Tareas','A4:K8','tareas'],['Sprint 1','B16:U24','sprint'],['Criterios de Aceptación','F5:L8','criterios'],['Indicaciones','B16:C18','indicaciones'],['Ejemplo','B4:K8','ejemplo'],['Gestión PMI','B4:I7','pmi']]){
 const blob=await w.render({sheetName:name,range,scale:1,format:'png'});await fs.writeFile(`audit_work/excel_${key}.png`,new Uint8Array(await blob.arrayBuffer()));
}
await (await SpreadsheetFile.exportXlsx(w)).save(out+'/Product_Backlog_WMS_Segundo_Avance.xlsx');
console.log('EXPORTADO',out+'/Product_Backlog_WMS_Segundo_Avance.xlsx');
