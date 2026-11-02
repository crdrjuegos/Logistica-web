import json
from pathlib import Path
from openpyxl import load_workbook
w=load_workbook('C:/Users/USUARIO/Downloads/Product_Backlog_WMS_Fase_Inicial_Cronograma.xlsx')
for s in ['Backlog del Producto','Criterios de Aceptación','Sprint 1','Sprints','Cronograma']:
 a=w[s]; print(s,'merges',list(map(str,a.merged_cells.ranges))[:15],'charts',len(a._charts),'validations',len(a.data_validations.dataValidation),'freeze',a.freeze_panes)
print('names',list(w.defined_names))
