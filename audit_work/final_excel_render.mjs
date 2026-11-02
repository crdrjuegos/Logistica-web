import fs from 'node:fs/promises';
import {FileBlob,SpreadsheetFile} from '@oai/artifact-tool';
const w=await SpreadsheetFile.importXlsx(await FileBlob.load('outputs/avance2_2026_10_28/Product_Backlog_WMS_Segundo_Avance.xlsx'));
for(const [sheet,range,key]of [['Criterios de Aceptación','N5:S8','criteria_evidence'],['Criterios de Aceptación','G193:S196','criteria_end'],['Gestión PMI','J3:Q16','pmi_weeks'],['Sprint 1','H7:W16','sprint_charts']]){const b=await w.render({sheetName:sheet,range,scale:1,format:'png'});await fs.writeFile(`audit_work/final_${key}.png`,new Uint8Array(await b.arrayBuffer()));}
console.log((await w.inspect({kind:'match',searchTerm:'#REF!|#DIV/0!|#VALUE!|#NAME\\?|#NUM!|#NULL!|#SPILL!|#CALC!',options:{useRegex:true,maxResults:20},maxChars:1000})).ndjson);
