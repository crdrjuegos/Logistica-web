import fs from 'node:fs/promises';import{FileBlob,SpreadsheetFile}from'@oai/artifact-tool';
try{
 const w=await SpreadsheetFile.importXlsx(await FileBlob.load('outputs/revision_formato_2026_10_28/Product_Backlog_WMS_Formato_Docente.xlsx'));const s=w.worksheets.getItem('Criterios de Aceptación'),b=w.worksheets.getItem('Backlog del Producto');
 function value(addr){w.recalculate();return b.getRange(addr).values[0][0];}
 function assert(x,msg){if(!x)throw Error(msg);}
 const original=s.getRange('Q6').values;s.getRange('Q6').values=[['No conforme']];assert(value('Q8')==='Con incidencias','No prioriza la incidencia');assert(Math.abs(value('R8')-2/3)<1e-8,'Fracción incorrecta');s.getRange('Q6').values=original;
 s.getRange('Q9:Q11').values=[['Aceptado por usuario'],['Aceptado por usuario'],['Aceptado por usuario']];assert(value('Q9')==='Casos aceptados por usuario','Aceptación específica incorrecta');assert(value('O9')==='Implementado','La aceptación modificó la implementación');
 s.getRange('Q9:Q11').values=[['Conforme en integración'],['Caso específico no ejecutado'],['Caso específico no ejecutado']];assert(value('Q9')==='Verificación parcial','No identifica verificación parcial');
 assert(value('Q16')==='Permisos por ratificar','No conserva reserva de autorización');
 console.log('5 comprobaciones de estados y proporciones correctas. Simulaciones en memoria; archivo final intacto.');
}catch(e){console.error(e.message);process.exitCode=1;}
