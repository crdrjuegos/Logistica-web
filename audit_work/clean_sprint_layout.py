from pathlib import Path
p=Path('audit_work/build_workbook.mjs');s=p.read_text(encoding='utf-8')
s=s.replace("DoneDays:\"'Sprint 1'!$F$18\",TrendDays:\"'Sprint 1'!$F$20\"", "DoneDays:\"'Sprint 1'!$AK$14\",TrendDays:\"'Sprint 1'!$AL$14\"")
s=s.replace("sprint.getRange('F18').formulas=", "sprint.getRange('AK14').formulas=").replace("sprint.getRange('F20').values=", "sprint.getRange('AL14').values=")
s=s.replace("sprint.getRange(`${col}18`).formulas=[[`=$G$17*(14-${d+1})/13`]];", '')
s=s.replace("sprint.getRange(`B22:G${sl}`).format.rowHeight=56;", "sprint.getRange(`B22:G${sl}`).format.rowHeight=56;sprint.getRange(`B22:U${sl}`).format.verticalAlignment='center';sprint.getRange('B18:W20').format.rowHeight=6;for(let r=22;r<=sl;r++)sprint.getRange(`B${r}:U${r}`).format.borders={bottom:{style:'thin',color:'#D9D9D9'}};for(let j=0;j<14;j++){const c=String.fromCharCode(72+j);sprint.getRange(`${c}21:${c}${sl}`).format.borders={left:{style:'thin',color:'#D9D9D9'},right:{style:'thin',color:'#D9D9D9'}};sprint.getRange('AK13:AL13').values=[['Registros reales','Ventana días']];")
p.write_text(s,encoding='utf-8')
