from pathlib import Path
import json,re,hashlib
R=Path('.'); A=R/'audit_work'
models=[]
for p in sorted(R.glob('app/Modules/**/*.php')):
 text=p.read_text(encoding='utf-8-sig');ns=re.search(r'namespace\s+([^;]+);',text);cm=re.search(r'\b(class|interface|enum)\s+(\w+)',re.sub(r'/\*.*?\*/|//[^\n]*','',text,flags=re.S))
 if not ns or not cm:continue
 attrs=[]
 for m in re.finditer(r'\b(public|protected|private)\s+(?:readonly\s+)?([?\\\w|]+)\s+\$(\w+)',text):
  if not any(x['name']==m[3]for x in attrs):attrs.append({'name':m[3],'type':m[2].split('\\')[-1],'visibility':m[1].capitalize()})
 methods=[];constructor=''
 for m in re.finditer(r'(?:(public|protected|private)\s+)?(?:(static)\s+)?function\s+(\w+)\s*\((.*?)\)\s*(?::\s*([?\\\w|]+))?\s*[;{]',text,re.S):
  args=[]
  for raw in m[4].split(','):
   x=re.search(r'([?\\\w|]+)\s+\$(\w+)',raw)
   if x:args.append({'name':x[2],'type':x[1].split('\\')[-1]})
  if m[3]=='__construct':constructor='__construct('+', '.join(x['name']+': '+x['type']for x in args)+')';continue
  methods.append({'name':m[3],'type':(m[5]or'void').split('\\')[-1],'visibility':(m[1]or'public').capitalize(),'static':bool(m[2]),'parameters':args})
 models.append({'id':ns[1]+'\\'+cm[2],'name':cm[2],'kind':'Interface'if cm[1]=='interface'else'Enumeration'if cm[1]=='enum'else'Class','module':p.parts[2],'path':str(p).replace('\\','/'),'attributes':attrs,'methods':methods,'notes':str(p).replace('\\','/')+'\nFirma del constructor '+constructor+'\nModelo extraído del código al 28/10/2026.','state':'Existente'})
proposed=[
 ('Imports','CatalogImportService',[('simulate','ImportPreview',[('file','string'),('actorId','int')]),('commit','ImportResult',[('importId','int'),('actorId','int')])],[('parser','CatalogFileParser'),('jobs','ImportJobs')],'HU-052'),
 ('Imports','OpeningBalanceImporter',[('validate','array',[('rows','array')]),('commit','ImportResult',[('importId','int'),('actorId','int')])],[('ledger','StockMovements'),('jobs','ImportJobs')],'HU-053'),
 ('Imports','CatalogFileParser',[('parse','ImportPreview',[('file','string')])],[],'HU-052'),
 ('Inventory','StockReconciliationJob',[('handle','ReconciliationResult',[])],[('reader','ReconciliationReader'),('alerts','AlertDispatcher')],'HU-023'),
 ('Alerts','AlertDispatcher',[('notify','DeliveryReceipt',[('channel','string'),('recipient','string'),('alerts','array')])],[('email','EmailGateway'),('whatsapp','WhatsAppGateway')],'HU-047'),
 ('Mobile','CameraScanner',[('start','void',[]),('stop','void',[]),('decode','string',[])],[('camera','MediaStream')],'HU-054'),
 ('Mobile','OfflineQueue',[('enqueue','void',[('operation','PendingOperation')]),('pending','array',[]),('markSynced','void',[('uuid','string')])],[('store','IndexedDB')],'HU-055'),
 ('Mobile','PendingOperation',[],[('uuid','string'),('idempotencyKey','string'),('operationType','string'),('payload','object'),('status','string'),('createdAt','datetime'),('retryCount','int')],'HU-055, HU-056'),
 ('Mobile','SyncPendingOperations',[('flush','SyncResult',[]),('resolveConflict','void',[('uuid','string')])],[('queue','OfflineQueue'),('client','SyncEndpointClient')],'HU-055, HU-056'),
 ('Mobile','SyncOperations',[('execute','SyncResult',[('request','array'),('actorId','int')])],[('movements','StockMovements')],'HU-055'),
 ('Operations','MobileCapture',[('receive','void',[('barcode','string'),('quantity','decimal')]),('count','void',[('barcode','string'),('counted','decimal')])],[('scanner','CameraScanner'),('queue','OfflineQueue')],'HU-056'),
 ('Operations','BackupDatabaseJob',[('handle','BackupResult',[('destination','BackupDestination')])],[('encryptor','BackupEncryptor'),('storage','ExternalBackupStore')],'HU-060'),
 ('Operations','RestoreBackup',[('execute','RestoreResult',[('backupId','string'),('isolatedDatabase','string')])],[('verifier','BackupVerifier')],'HU-060')]
for module,name,methods,attrs,hu in proposed:models.append({'id':'Target\\'+module+'\\'+name,'name':name,'kind':'Class','module':'Objetivo '+module,'path':'Diseño propuesto','attributes':[{'name':a,'type':t,'visibility':'Private'if name!='PendingOperation'else'Public'}for a,t in attrs],'methods':[{'name':a,'type':t,'parameters':[{'name':n,'type':ty}for n,ty in par],'visibility':'Public','static':False}for a,t,par in methods],'notes':'Contrato propuesto para '+hu+'. No existe todavía en el repositorio. Requiere refinamiento y pruebas antes de implementarse.','state':'Propuesto'})
by={m['name']:m for m in models};views=[]
def view(key,title,names,edges):
 nodes=[]
 for i,name in enumerate(names):
  m=by[name];display=dict(m);display['methods']=[x for x in m['methods']if x['visibility']=='Public'];display['h']=52+len(display['attributes'])*18+len(display['methods'])*24;display['w']=420;display['x']=40+(i%3)*540;display['y']=40+(i//3)*430;nodes.append(display)
 views.append({'key':key,'title':title,'type':'Class','nodes':nodes,'edges':[{'a':by[a]['id'],'b':by[b]['id'],'type':t,'name':label}for a,b,t,label in edges]})
view('clases_inventario','Clases de aplicación y contratos de inventario',['RegisterMovement','StockLedger','PostgresStockLedger','Transaction','EventBus','MovementRecorded'],[('RegisterMovement','StockLedger','Dependency','persistencia'),('PostgresStockLedger','StockLedger','Realisation','implementa'),('RegisterMovement','Transaction','Dependency','atomicidad'),('RegisterMovement','EventBus','Dependency','evento'),('RegisterMovement','MovementRecorded','Dependency','resultado')])
# Se usa Transaction del dominio Inventory en esta vista.
for v in views:
 for n in v['nodes']:
  if n['name']=='Transaction':
   actual=next(m for m in models if m['id']=='App\\Modules\\Inventory\\Domain\\Transaction');n.update(actual)
view('clases_movimiento','Clases de dominio del movimiento y valores decimales',['NewMovement','Quantity','UnitCost','MovementType','RecordedMovement','ProductSnapshot'],[('NewMovement','Quantity','Association','quantity y presentationQty'),('NewMovement','UnitCost','Association','unitCost'),('NewMovement','MovementType','Association','type'),('ProductSnapshot','UnitCost','Association','costAvg')])
view('clases_recepciones','Clases del proceso de recepción',['CreateReceiptDraft','ConfirmReceipt','ReceiptRepository','StockMovements','ReceiptLine','ReceiptRecord'],[('CreateReceiptDraft','ReceiptRepository','Dependency','borrador'),('ConfirmReceipt','ReceiptRepository','Dependency','estado'),('ConfirmReceipt','StockMovements','Dependency','entradas'),('ReceiptRecord','ReceiptLine','Association','líneas')])
view('clases_objetivo','Clases propuestas para importación y conciliación',['CatalogImportService','OpeningBalanceImporter','CatalogFileParser','StockReconciliationJob','AlertDispatcher'],[('CatalogImportService','CatalogFileParser','Dependency','parsear'),('StockReconciliationJob','AlertDispatcher','Dependency','avisar diferencias')])
view('clases_movil','Clases objetivo del cliente móvil y sincronización',['CameraScanner','MobileCapture','OfflineQueue','PendingOperation','SyncPendingOperations','SyncOperations'],[('MobileCapture','CameraScanner','Dependency','captura'),('MobileCapture','OfflineQueue','Dependency','sin conexión'),('OfflineQueue','PendingOperation','Association','0..* pendientes'),('SyncPendingOperations','OfflineQueue','Dependency','vaciar cola'),('SyncPendingOperations','SyncOperations','Dependency','HTTPS propuesto')])
# Corregir tamaños y utilizar auto layout para evitar cruces entre nodos.
for v in views:v['layout']=True
A.joinpath('class_model.json').write_text(json.dumps(models,ensure_ascii=False,indent=2),encoding='utf-8')
A.joinpath('detailed_views.json').write_text(json.dumps(views,ensure_ascii=False,indent=2),encoding='utf-8')
print('Clases e interfaces existentes',sum(m['state']=='Existente'for m in models),'propuestas',len(proposed),'vistas',len(views))
