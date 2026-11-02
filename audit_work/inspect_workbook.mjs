import fs from 'node:fs/promises';
import {FileBlob,SpreadsheetFile} from '@oai/artifact-tool';
const w=await SpreadsheetFile.importXlsx(await FileBlob.load('C:/Users/USUARIO/Downloads/Product_Backlog_WMS_Fase_Inicial_Cronograma.xlsx'));
console.log((await w.inspect({kind:'sheet',include:'id,name'})).ndjson);
for(const [sheet,range,name] of [['Backlog del Producto','B5:P10','backlog_before'],['Criterios de Aceptación','F5:K8','criteria_before']]){
 const blob=await w.render({sheetName:sheet,range,scale:1.2,format:'png'});
 await fs.writeFile('audit_work/'+name+'.png',new Uint8Array(await blob.arrayBuffer()));
}
