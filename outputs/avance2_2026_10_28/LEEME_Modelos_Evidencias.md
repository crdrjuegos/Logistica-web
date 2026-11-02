# Modelos y evidencias del segundo avance WMS

Corte 28 de octubre de 2026. El diseño comprende las 63 historias del backlog, incluidas las tres futuras. Las pruebas reflejan únicamente lo ejecutado al corte.

## Modelos

- Diseno_Integral_WMS.eap: repositorio editable de Enterprise Architect. Incluye requisitos, escenarios de aceptación, clases existentes, contratos propuestos, datos, componentes, despliegue y estados. Abrir una copia si se desea conservar esta versión de entrega.
- Las figuras PNG se exportaron desde Enterprise Architect y conservan los identificadores HU. La notación rectangular con icono corresponde a casos de uso UML. Los actores se repiten para facilitar la lectura y representan el mismo rol funcional.
- Los elementos con el estereotipo propuesto pertenecen al diseño futuro. Su presencia en el modelo no acredita implementación ni prueba satisfactoria.
- Modelo_Datos_Entidades_Claves.erwin: modelo lógico editable de ERwin con 46 entidades, 343 atributos y 46 claves primarias con 61 miembros. Se reabrió y comprobó el archivo guardado. Su automatización falla al confirmar relaciones; este archivo no se presenta como un modelo de claves foráneas terminado. El esquema relacional y las 66 referencias se conservan en Enterprise Architect y en el diccionario.
- Diccionario_Datos_WMS.html y CSV: tipos SQL exactos, nulabilidad, claves y referencias. Incluyen infraestructura y una partición. No contienen registros operativos de la empresa.
- Referencias_FK_WMS.csv: las 66 referencias obtenidas de PostgreSQL.
- Indice_Clases_WMS.csv: clases, interfaces y enumeraciones con módulo, estado y fuente. El repositorio contiene 169 definiciones existentes y 13 propuestas; las doce enumeraciones existentes incorporan sus 55 valores reales.

## Evidencias

- Pest: 253 pruebas aprobadas y 1363 aserciones, sin fallos ni omisiones en la ejecución ordinaria.
- PCOV: 1913 de 1997 líneas ejecutables cubiertas en app/Modules, 95,79 por ciento. No mide toda la interfaz, ramas ni caminos.
- Pint: 377 archivos conformes. PHPStan nivel 6: sin errores. Deptrac: cero infracciones y cero no cubiertas, dentro del alcance configurado.
- acceptance_results.json: comprobaciones de dominio con dobles. sql_acceptance_results.json: comprobaciones contra wms_test, con transacción revertida.
- Consolidación: 45 criterios distintos ejecutados, 42 conformes y tres no conformes. CA-019-4 aparece en ambos niveles y se cuenta una sola vez. Existen 191 escenarios especificados; 146 aún no tienen ejecución específica conforme al registro del Excel.
- Los archivos de comprobación se conservan como texto para revisión. Antes de ejecutarlos debe revisarse su conexión de testing. No sustituir wms_test por la base operativa.

El informe académico y el mini informe explican D01 idempotencia con contenido distinto, D02 apertura repetida, D03 visibilidad de exportación sin permiso y la brecha de dependencias no declaradas. No se corrigió el código de la aplicación durante esta revisión. La aceptación empresarial, las mediciones de carga y SUS y la restauración siguen pendientes.
