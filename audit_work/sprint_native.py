from openpyxl import load_workbook
w=load_workbook('C:/Users/USUARIO/Downloads/Product_Backlog_WMS_Fase_Inicial_Cronograma.xlsx')
for n,v in w.defined_names.items():print(n,v.attr_text)
s=w['Sprint 1']
for i,c in enumerate(s._charts):
 print('chart',i,'title',str(c.title)[:350])
 for a in c.ser:print('series',a.val,a.cat)
for row in [18,19,20,21]: print(row,[c.value for c in s[row]][:23])
