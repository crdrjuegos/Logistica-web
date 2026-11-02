import fs from'node:fs/promises';
let p='audit_work/build_workbook.mjs';let t=await fs.readFile(p,'utf8');t=t.replace('format.rowHeight=118','format.rowHeight=78');t=t.replace('"Conforme*"', '"Conforme en dominio"');
const old=`=IFERROR(COUNTIFS('Criterios de Aceptación'!$B$6:$B$\${last},F\${n},'Criterios de Aceptación'!$Q$6:$Q$\${last},"Conforme en dominio")/COUNTIF('Criterios de Aceptación'!$B$6:$B$\${last},F\${n}),0)`;
const neu=`=IFERROR((COUNTIFS('Criterios de Aceptación'!$B$6:$B$\${last},F\${n},'Criterios de Aceptación'!$Q$6:$Q$\${last},"Conforme en dominio")+COUNTIFS('Criterios de Aceptación'!$B$6:$B$\${last},F\${n},'Criterios de Aceptación'!$Q$6:$Q$\${last},"Conforme en integración"))/COUNTIF('Criterios de Aceptación'!$B$6:$B$\${last},F\${n}),0)`;
if(!t.includes(old))throw Error('Fórmula no encontrada');t=t.replace(old,neu);await fs.writeFile(p,t);
