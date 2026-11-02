import fs from 'node:fs/promises';
import {FileBlob,SpreadsheetFile} from '@oai/artifact-tool';
const w=await SpreadsheetFile.importXlsx(await FileBlob.load('C:/Users/USUARIO/Downloads/Product_Backlog_WMS_Segundo_Avance_Mejorado.xlsx'));
for(const [sheet,range,key]of [['Backlog del Producto','M6:T10','estados'],['Gestión PMI','B4:K8','plan']]){let b=await w.render({sheetName:sheet,range,scale:1,format:'png'});await fs.writeFile(`audit_work/antes_${key}.png`,new Uint8Array(await b.arrayBuffer()));}console.log('Vistas originales guardadas');
