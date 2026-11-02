import json
from pathlib import Path
p=Path('audit_work/ea_diagrams.json');D=json.loads(p.read_text(encoding='utf-8'))
cl=next(x for x in D if x['key']=='clases')
for n in cl['nodes']:
 if n['id']=='qty':n['name']='Quantity';n['notes']='value: string de tres decimales; aritmética exacta con BCMath.'
cl['nodes'].append({'id':'cost','name':'UnitCost','kind':'Class','x':680,'y':510,'w':230,'h':100,'notes':'value: string de cuatro decimales.'})
cl['edges'].append({'a':'new','b':'cost','type':'Dependency','name':'costo de compra'})
attrs={'reg':[('ledger','StockLedger'),('transaction','Transaction'),('events','EventBus')],'new':[('uuid','string'),('idempotencyKey','string'),('productId','int'),('quantity','Quantity'),('unitCost','UnitCost?')],'qty':[('value','string')],'cost':[('value','string')],'result':[('movementId','int'),('uuid','string'),('duplicate','bool')]}
methods={'reg':[('__invoke','MovementRecorded')],'ledger':[('lockProduct','ProductSnapshot?'),('append','int'),('increase','void'),('decrease','bool')],'tx':[('run','mixed')],'qty':[('of','Quantity'),('add','Quantity'),('subtract','Quantity')],'cost':[('of','UnitCost')]}
for n in cl['nodes']:
 n['attributes']=[{'name':a,'type':t}for a,t in attrs.get(n['id'],[])];n['methods']=[{'name':a,'type':t}for a,t in methods.get(n['id'],[])]
 n['h']=max(n['h'],175 if n['attributes'] or n['methods'] else 120)
 if n['y']==300:n['y']=330
 if n['y']==510:n['y']=620
schema=json.loads(Path('audit_work/er_model_data.json').read_text(encoding='utf-8'));ent={t['name']:t for t in schema['entities']}
for key,title,names in [('er_catalogo','Modelo de datos del catálogo',['categories','units','products','product_units','product_barcodes','suppliers','product_suppliers']),('er_inventario','Modelo de datos del inventario',['warehouses','locations','products','stock_balances','stock_movements','users','stock_counts','stock_count_lines'])]:
 nodes=[]
 for i,name in enumerate(names):
  cols=ent[name]['columns'];selected=[c for c in cols if c['primary'] or c['column_name']in ['name','sku','warehouse_id','product_id','location_id','quantity','type','idempotency_key','cost_avg','count_id','expected_qty','counted_qty','parent_id','base_unit_id','unit_id','factor','barcode','ruc','supplier_id','from_location_id','to_location_id','user_id','status']][:7]
  nodes.append({'id':name,'name':name,'kind':'Class','x':40+(i%4)*300,'y':40+(i//4)*390,'w':245,'h':265,'notes':'Tabla PostgreSQL. Se muestran campos principales. La estructura completa está en el archivo ERwin.','attributes':[{'name':c['column_name']+(' {PK}' if c['primary']else ''),'type':c['data_type']}for c in selected],'methods':[],'stereotype':'table'})
 edges=[{'a':r['table_name'],'b':r['referenced_table'],'type':'Association','name':r['column_name']}for r in schema['relations']if r['table_name']in names and r['referenced_table']in names]
 D.append({'key':key,'title':title,'type':'Class','nodes':nodes,'edges':edges})
p.write_text(json.dumps(D,ensure_ascii=False,indent=2),encoding='utf-8');print('Diagramas',len(D))
