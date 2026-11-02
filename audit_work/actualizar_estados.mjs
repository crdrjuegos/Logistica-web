import fs from 'node:fs/promises';
import {FileBlob,SpreadsheetFile} from '@oai/artifact-tool';
try {
const source='C:/Users/USUARIO/Downloads/Product_Backlog_WMS_Segundo_Avance_Mejorado.xlsx';
const out='outputs/revision_formato_2026_10_28';await fs.mkdir(out,{recursive:true});
const w=await SpreadsheetFile.importXlsx(await FileBlob.load(source));
const data=JSON.parse(await fs.readFile('audit_work/estados_revision.json','utf8'));
const native=JSON.parse(await fs.readFile('audit_work/current_source.json','utf8'));
const plan=JSON.parse(await fs.readFile('audit_work/formal_pmi.json','utf8'));
const back=w.worksheets.getItem('Backlog del Producto'),ca=w.worksheets.getItem('Criterios de Aceptación');
back.conditionalFormattings.clear('B7:T189');back.conditionalFormattings.clear('R1:T4');
back.getRange('O6:O189').dataValidation.clear();
back.getRange('O6').values=[['Implementación técnica']];back.getRange('Q6').values=[['Verificación de aceptación']];
back.getRange('O6:O81').format.columnWidth=28;back.getRange('Q6:Q81').format.columnWidth=34;
back.getRange('O6:O81').format.wrapText=true;back.getRange('Q6:Q81').format.wrapText=true;
back.getRange('O8:O81').dataValidation={rule:{type:'list',values:['Implementado','Parcial','Pendiente']},ignoreBlanks:true,errorAlert:{show:true,style:'stop',message:'Seleccione Implementado, Parcial o Pendiente.'}};
back.getRange('S1:S4').values=[['Implementado'],['Parcial'],['Pendiente'],['Corte 28/10/2026']];
for(let i=1;i<=3;i++)back.getRange(`T${i}`).formulas=[[`=COUNTIF(O8:O81,S${i})`]];
back.getRange('H3').values=[['63 HU aprobadas: 60 de fase inicial y 3 futuras. Implementación técnica y aceptación se presentan por separado. Corte 28/10/2026.']];
const match=(r,status)=>`COUNTIFS('Criterios de Aceptación'!$B$6:$B$196,F${r},'Criterios de Aceptación'!$Q$6:$Q$196,"${status}")`;
for(const s of data.stories){const r=s.row,n=`COUNTIF('Criterios de Aceptación'!$B$6:$B$196,F${r})`,ok=`(${match(r,'Conforme en dominio')}+${match(r,'Conforme en integración')}+${match(r,'Aceptado por usuario')})`;
 back.getRange(`O${r}`).values=[[s.technical]];
 back.getRange(`Q${r}`).formulas=[[`=IF(${n}=0,"Sin criterios asociados",IF(${match(r,'No conforme')}>0,"Con incidencias",IF(ISNUMBER(SEARCH("pendiente de ratificación",P${r})),"Permisos por ratificar",IF(${match(r,'Aceptado por usuario')}=${n},"Casos aceptados por usuario",IF(${ok}=${n},"Escenarios conformes",IF(O${r}="Pendiente","Por implementar",IF(${ok}>0,"Verificación parcial","Sin verificación específica")))))))`]];
 back.getRange(`R${r}`).formulas=[[`=IF(${n}=0,"",${ok}/${n})`]];
}
for(const [text,color]of [['Implementado','#E2F0D9'],['Parcial','#FFF2CC'],['Pendiente','#F2F2F2']])back.getRange('O8:O81').conditionalFormats.add('containsText',{text,format:{fill:color}});
for(const [text,color]of [['Con incidencias','#FCE4D6'],['Permisos por ratificar','#FFE699'],['Escenarios conformes','#E2F0D9'],['Por implementar','#F2F2F2'],['Verificación parcial','#FFF2CC']])back.getRange('Q8:Q81').conditionalFormats.add('containsText',{text,format:{fill:color}});
const roleFix=new Map(data.stories.filter(s=>['HU-008','HU-009','HU-010','HU-016'].includes(s.id)).map(s=>[s.id,s.role]));
for(let r=6;r<=196;r++){
 const vals=native['Criterios de Aceptación'].values[r-1],id=vals[1];if(!roleFix.has(id))continue;
 const role=roleFix.get(id);ca.getRange(`C${r}`).values=[[role]];
 const context=String(vals[8]||'').replace(/actor (?:Supervisor o Administrador|Supervisor|Administrador)/g,`actor ${role}`);
 ca.getRange(`I${r}`).values=[[context]];
 const scope='Autorización del actor original ('+role+') pendiente de ratificación: las políticas actuales restringen la escritura a Supervisor o Administrador. El resultado técnico registrado no acredita ese permiso.';
 ca.getRange(`S${r}`).values=[[(vals[18]?vals[18]+' ':'')+scope]];
}
const cron=w.worksheets.getItem('Cronograma');cron.getRange('F5').values=[['Implementación al corte']];
for(let r=7;r<=22;r++){
 const row=native.Cronograma.values[r-1],type=row[1];if(!['Sprint','Épica'].includes(type))continue;
 const epic=type==='Épica'?'EPIC'+String(r-10).padStart(2,'0'):null;
 const subset=data.stories.filter(s=>type==='Sprint'?s.sprint===row[2]:s.epic===epic);
 const n=String(subset.length),num=t=>type==='Sprint'?`COUNTIFS('Backlog del Producto'!$N$8:$N$81,C${r},'Backlog del Producto'!$O$8:$O$81,"${t}")`:`COUNTIF('Backlog del Producto'!$O$${Math.min(...subset.map(s=>s.row))}:$O$${Math.max(...subset.map(s=>s.row))},"${t}")`;

 cron.getRange(`F${r}`).formulas=[[`=IF(${n}=0,"Sin datos",IF(${num('Implementado')}=${n},"Implementado",IF(${num('Pendiente')}=${n},"Pendiente","Parcial")))`]];
}
const sp=w.worksheets.getItem('Sprints');sp.getRange('G2').values=[['Situación del sprint']];sp.getRange('E18').values=[['Puntos sin sprint']];
for(let r=9;r<=17;r++)sp.getRange(`G${r}`).formulas=[[`=IF(B${r}="","",IF(C${r}="","Sin planificar",IF(C${r}>DATE(2026,10,28),"Planeado",IF(E${r}<DATE(2026,10,28),"Periodo finalizado","En Progreso"))))`]];
sp.getRange('B20').values=[['La situación del sprint describe el periodo planificado. La implementación de cada HU y su aceptación se consultan por separado en Backlog del Producto; no acreditan reuniones realizadas.']];
const ind=w.worksheets.getItem('Indicaciones');
ind.getRange('B16:C21').values=[['Implementación técnica (O)','Implementado: comportamiento localizado en código y pruebas relacionadas. Parcial: existen componentes y brechas. Pendiente: funcionalidad sin implementación verificada. No representa aceptación empresarial.'],['Verificación de aceptación (Q)','Se calcula por criterio. Tiene prioridad No conforme; después permisos por ratificar. Escenarios conformes exige todos los criterios conformes. Casos aceptados por usuario exige evidencia registrada por criterio.'],['Criterios conformes (R)','Criterios conformes en dominio, integración o aceptados por usuario divididos entre criterios asociados. Una prueba de dominio no acredita interfaz ni autorización del actor.'],['Cierre de historia','Una HU se cierra cuando cumple todos sus criterios aplicables, la Definition of Done y la aceptación del representante autorizado. La implementación técnica no sustituye ese cierre.'],['Situación del sprint y tareas','La situación del sprint corresponde al calendario. Los estados de Tareas corresponden a tareas concretas y no se propagan automáticamente como aceptación de historias.'],['Cronograma PMI','Gestión PMI presenta la tabla del plan docente adaptada a 4 sprints y 8 semanas. X indica programación. El bloque de evidencias conserva las 12 actividades de seguimiento del archivo de origen.']];
// Recreate only the PMI view to apply the exact reference layout and retain its evidence records.
w.worksheets.getItem('Gestión PMI').delete();const pm=w.worksheets.add('Gestión PMI');pm.index=8;pm.showGridLines=false;
pm.getRange('B2:J2').merge();pm.getRange('B2').values=[['IV. ACTIVIDADES PRINCIPALES A REALIZARSE EN LA EMPRESA — WMS CITY OFERTAS']];
pm.getRange('B2:J2').format={font:{name:'Arial',size:13,bold:true},rowHeight:29};
pm.getRange('B3:J3').merge();pm.getRange('B3').values=[['Programación 19/10/2026–10/12/2026. X indica periodo previsto; no acredita ejecución. Mes 1 y Mes 2 son bloques de cuatro semanas.']];pm.getRange('B3:J3').format={font:{name:'Arial',size:10,italic:true},wrapText:true,rowHeight:30};
pm.getRange('B5:J46').format={font:{name:'Arial',size:10},rowHeight:26,verticalAlignment:'center',wrapText:true,borders:{preset:'all',style:'thin',color:'#808080'}};
pm.getRange('B5:B46').format.columnWidth=95;pm.getRange('C5:J46').format.columnWidth=12;pm.getRange('C5:J46').format.horizontalAlignment='center';
const mergeText=(rg,text)=>{pm.getRange(rg).merge();pm.getRange(rg.split(':')[0]).values=[[text]];};
mergeText('C5:J5','TIEMPO');pm.getRange('B5').values=[['DETALLAR LAS ACTIVIDADES']];pm.getRange('B6').values=[['FASE / ACTIVIDAD']];mergeText('C6:F6','Mes 1');mergeText('G6:J6','Mes 2');pm.getRange('B7').values=[['SPRINT']];
for(let i=0;i<4;i++)mergeText(`${String.fromCharCode(67+i*2)}7:${String.fromCharCode(68+i*2)}7`,'Sprint '+(i+1));
pm.getRange('B8').values=[['SEMANA']];pm.getRange('C8:J8').values=[[1,2,3,4,5,6,7,8]];
pm.getRange('B5:J6').format.fill='#A90020';pm.getRange('B5:J6').format.font={bold:true,color:'#FFFFFF'};pm.getRange('B7:J8').format.fill='#E7E6E6';pm.getRange('B7:J8').format.font.bold=true;
for(let i=0;i<plan.length;i++){const r=i+9,row=plan[i];if(row.phase){mergeText(`B${r}:J${r}`,row.phase);pm.getRange(`B${r}:J${r}`).format={fill:'#C00024',font:{bold:true,color:'#FFFFFF'},rowHeight:23};}else{pm.getRange(`B${r}`).values=[[row.number+'. '+row.activity]];pm.getRange(`B${r}:J${r}`).format.rowHeight=row.activity.length>104?38:29;for(const week of row.weeks){const col=String.fromCharCode(66+week);pm.getRange(`${col}${r}`).values=[['X']];pm.getRange(`${col}${r}`).format={fill:'#FFF200',font:{bold:true}};}}}
mergeText('B48:J48','Fuente: elaboración propia a partir del plan de prácticas, los cuatro sprints y el Product Backlog aprobado.');pm.getRange('B48:J48').format={font:{name:'Arial',size:10,italic:true},rowHeight:25};
mergeText('L53:S53','Seguimiento de evidencias por grupos de procesos PMI');pm.getRange('L53:S53').format.font={name:'Arial',size:12,bold:true};
pm.getRange('L55:S67').values=native['Gestión PMI'].values.slice(3,16).map((r,j)=>r.slice(1,9).map((v,i)=>j>0&&(i===4||i===5)?new Date(v):v));
pm.getRange('L55:S67').format={font:{name:'Arial',size:10},wrapText:true,rowHeight:78,verticalAlignment:'center',borders:{preset:'all',style:'thin',color:'#D9D9D9'}};
pm.getRange('L55:S55').format={fill:'#214C7C',font:{bold:true,color:'#FFFFFF'},rowHeight:40};
for(const [c,wi]of Object.entries({L:25,M:36,N:58,O:47,P:15,Q:15,R:31,S:62}))pm.getRange(`${c}55:${c}67`).format.columnWidth=wi;
pm.getRange('P56:Q67').setNumberFormat('dd/mm/yyyy');pm.freezePanes.freezeRows(8);
pm.getRange('C8:J8').values=[[1,2,3,4,5,6,7,8]];
pm.getRange('J8').values=[[8]];
w.recalculate();
console.log((await w.inspect({kind:'table',range:"'Backlog del Producto'!O6:R10",include:'values',tableMaxRows:5,tableMaxCols:4,maxChars:1800})).ndjson);
console.log((await w.inspect({kind:'match',searchTerm:'#REF!|#DIV/0!|#VALUE!|#NAME\\?|#NUM!|#NULL!',options:{useRegex:true,maxResults:20},maxChars:1000})).ndjson);
for(const [sheet,range,key]of [['Backlog del Producto','M6:T10','backlog'],['Gestión PMI','B5:J27','pmi1'],['Gestión PMI','B28:J46','pmi2']]){let b=await w.render({sheetName:sheet,range,scale:1,format:'png'});await fs.writeFile(`audit_work/nuevo_${key}.png`,new Uint8Array(await b.arrayBuffer()));}
await(await SpreadsheetFile.exportXlsx(w)).save(out+'/Product_Backlog_WMS_Formato_Docente.xlsx');console.log('Exportado');

} catch(e){ console.error(e.message);process.exitCode=1;}
