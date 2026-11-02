from pathlib import Path
p=Path('audit_work/build_workbook.mjs');s=p.read_text(encoding='utf-8').replace('Date.UTC(2026,9,19+7*i,12)','Date.UTC(2026,9,19+7*i,0)')
anchor="pm.getRange('J5:Q16').conditionalFormats.add('containsText'"
extra="""for(let j=0;j<8;j++){const col=String.fromCharCode(74+j);for(const [r,sc]of [[9,'C'],[14,'H']])pm.getRange(`${col}${r}`).formulas=[[`=IF(COUNTIFS('Sprints'!$${sc}$3:$${sc}$6,\">=\"&${col}$3,'Sprints'!$${sc}$3:$${sc}$6,\"<\"&${col}$3+7)>0,\"X\",\"\")`]];}
"""
s=s.replace(anchor,extra+anchor);p.write_text(s,encoding='utf-8')
p=Path('audit_work/build_reports.py');s=p.read_text(encoding='utf-8')
s=s.replace("   start=datetime(2026,10,19);weeks=[start+timedelta(days=7*j)for j in range(8)]", "   from openpyxl import load_workbook\n   wb=load_workbook(O/'Product_Backlog_WMS_Segundo_Avance.xlsx',data_only=True);ws=wb['Gestión PMI'];weeks=[ws.cell(3,10+j).value for j in range(8)]")
s=s.replace("   for rec in json.loads((A/'pmi_plan.json').read_text(encoding='utf-8')):\n    lo=datetime.fromisoformat(rec[4]);hi=datetime.fromisoformat(rec[5]);rows.append([rec[0],rec[1]]+['X'if lo<=w+timedelta(days=6)and hi>=w else''for w in weeks])", "   for idx,rec in enumerate(json.loads((A/'pmi_plan.json').read_text(encoding='utf-8'))):\n    rows.append([rec[0],rec[1]]+[ws.cell(idx+5,10+j).value or''for j in range(8)])")
p.write_text(s,encoding='utf-8');compile(s,'build_reports.py','exec');print('Eventos por fecha de Sprint, sin marcas intermedias ficticias')
