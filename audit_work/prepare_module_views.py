from pathlib import Path
import json,re
A=Path('audit_work');models=json.loads((A/'class_model.json').read_text(encoding='utf-8'));by={(m['module'],m['name']):m for m in models};specs=[
('clases_usuarios','Clases de usuarios roles y creación de cuentas','Iam','CreateUser NewUser UserRepository DatabaseUserRepository UserRecord RoleName'),
('clases_autenticacion','Clases de contraseñas y control de intentos','Iam','LockoutPolicy LockoutState RecordLoginFailure RecordLoginSuccess ResetUserPassword PasswordPolicy PasswordHasher LaravelPasswordHasher'),
('clases_catalogo_alta','Clases de catálogo y generación de SKU','Catalog','CreateProduct NewProduct CatalogRepository DatabaseCatalogRepository ProductRecord SkuSequence DatabaseSkuSequence'),
('clases_catalogo_valores','Clases de códigos presentaciones y políticas','Catalog','Barcode Symbology PresentationInput Factor StockPolicy UpdateProduct ProductChanges LookupProduct ProductLookup'),
('clases_ubicaciones','Clases de almacenes ubicaciones y estados','Locations','GenerateLocations ChangeLocationState LocationBatch LocationCode LocationRecord LocationRepository DatabaseLocationRepository'),
('clases_proveedores','Clases de proveedores RUC y asociaciones','Suppliers','CreateSupplier UpdateSupplier LinkProductToSupplier NewSupplier Ruc SupplierRepository DatabaseSupplierRepository'),
('clases_auditoria','Clases de contratos y persistencia de auditoría','Audit','AuditEntry AuditTrail DatabaseAuditTrail'),
('clases_conteos','Clases de apertura registro y cierre de conteos','Operations','StartCount RecordCount CloseCount CountRepository DatabaseCountRepository CountRecord CountLineRecord CountStatus'),
('clases_pos','Clases de importación de ventas y errores','Operations','ImportPosSales PosSalesParser PosParseResult PosSaleRow ImportJobs ImportResult ImportError'),
('clases_transferencias','Clases de transferencias y confirmación','Operations','CreateTransferDraft CompleteTransfer TransferRepository DatabaseTransferRepository TransferRecord TransferLine'),
('clases_ajustes','Clases de ajustes solicitud y aprobación','Operations','RequestAdjustment ApproveAdjustment RejectAdjustment RecordDirectAdjustment AdjustmentRepository AdjustmentRecord AdjustmentStatus'),
('clases_continuidad','Clases propuestas de respaldo y restauración','Objetivo Operations','BackupDatabaseJob RestoreBackup')]
views=[]
for key,title,module,names in specs:
 nodes=[by[(module,n)]for n in names.split()];edges=[]
 for c in nodes:
  source=Path(c['path']).read_text(encoding='utf-8-sig')if c['state']=='Existente'else''
  for t in nodes:
   if c['id']==t['id']:continue
   if re.search(r'implements\s+[^\{]*\b'+t['name']+r'\b',source):kind='Realisation';label='implementa'
   elif re.search(r'extends\s+'+t['name']+r'\b',source):kind='Generalization';label='extiende'
   elif any(re.search(r'\b'+t['name']+r'\b',a['type'])for a in c['attributes']):kind='Association';label='referencia'
   elif any(re.search(r'\b'+t['name']+r'\b',x.get('type',''))for m in c['methods']for x in [m]+m.get('parameters',[])):kind='Dependency';label='firma'
   elif re.search(r'\b'+t['name']+r'::',source):kind='Dependency';label='utiliza'
   else:continue
   edges.append({'a':c['id'],'b':t['id'],'type':kind,'name':label})
 views.append({'key':key,'title':title,'nodes':nodes,'edges':edges})
(A/'module_class_views.json').write_text(json.dumps(views,ensure_ascii=False,indent=2),encoding='utf-8')
p=A/'report_main.md';s=p.read_text(encoding='utf-8');anchor='##### 5.2.3.1.3 Modelo de datos y reglas de integridad';extra='Las vistas complementarias detallan usuarios y autenticación, catálogo, ubicaciones, proveedores, auditoría, conteos, POS, transferencias, ajustes y continuidad. Sus relaciones proceden de atributos, firmas y adaptadores de las clases del proyecto. Las dos clases de continuidad son propuestas y se identifican como tales.\n'+'\n'.join('@figure '+v['key']+' portrait'for v in views)+'\n';s=s.replace(anchor,extra+anchor);p.write_text(s,encoding='utf-8');print('Vistas complementarias',len(views))
