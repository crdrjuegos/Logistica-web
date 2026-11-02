from pathlib import Path
p=Path('audit_work/build_workbook.mjs');s=p.read_text(encoding='utf-8')
s=s.replace("const sprint=w.worksheets.getItem('Sprint 1');const st=", "w.worksheets.getItem('Sprint 1').delete();const sprint=w.worksheets.add('Sprint 1');sprint.index=4;sprint.showGridLines=false;sprint.tabColor='#F4B183';const st=")
s=s.replace("w.names.getItem('TaskRows').delete();w.names.addRange('TaskRows',\"'Sprint 1'!$D$17\");", "for(const [nm,ref]of Object.entries({TaskRows:\"'Sprint 1'!$D$17\",ImplementationDays:\"'Sprint 1'!$D$16\",TotalEffort:\"'Sprint 1'!$G$17\",DoneDays:\"'Sprint 1'!$F$18\",TrendDays:\"'Sprint 1'!$F$20\"})){try{w.names.getItem(nm).delete();}catch{}w.names.addRange(nm,ref);}")
anchor="sprint.getRange('B16:AP100').clear({applyTo:'all'});"
extra="""sprint.getRange('B2:W3').merge();sprint.getRange('B2').values=[['CITY OFERTAS WMS Sprint 1']];sprint.getRange('B2:W3').format={fill:'#214C7C',font:{name:'Arial',size:18,bold:true,color:'#FFFFFF'},verticalAlignment:'center'};
sprint.getRange('B4:C4').merge();sprint.getRange('B4').values=[['Meta del sprint']];sprint.getRange('D4:W5').merge();sprint.getRange('D4').values=[['Cimientos: libro mayor de inventario, accesos y catálogo base, arquitectura y controles de calidad.']];sprint.getRange('B4:W5').format={font:{name:'Arial',size:11},wrapText:true,rowHeight:25};
sprint.getRange('B6:G6').merge();sprint.getRange('B6').values=[['19/10/2026 al 01/11/2026 · Revisión prevista 30/10/2026']];sprint.getRange('B7:W7').merge();sprint.getRange('B7:W7').format={font:{name:'Arial',size:10},wrapText:true,rowHeight:32};
sprint.getRange('B9:G10').merge();sprint.getRange('B9').values=[['Planificación del sprint. Las tareas y horas se conservan del archivo de origen. Los responsables nominales siguen pendientes de asignación.']];sprint.getRange('B12:G14').merge();sprint.getRange('B12').values=[['No hay mediciones diarias de esfuerzo restante. La serie real del gráfico queda vacía hasta disponer de registros comprobables.']];sprint.getRange('B9:G14').format={font:{name:'Arial',size:10},wrapText:true,verticalAlignment:'center'};
sprint.getRange('B:B').format.columnWidth=15;sprint.getRange('D:D').format.columnWidth=15;sprint.getRange('G:G').format.columnWidth=12;sprint.getRange('H:W').format.columnWidth=5;
"""
s=s.replace(anchor,extra+anchor)
p.write_text(s,encoding='utf-8')
