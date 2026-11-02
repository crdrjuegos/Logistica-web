# Resumen de la revisión
El sistema dispone de una base funcional amplia y de controles automáticos satisfactorios. La suite existente aprobó 253 pruebas y 1363 aserciones. La revisión adicional identificó tres criterios no conformes que no quedan cubiertos por ese resultado: conflicto de idempotencia, repetición de apertura y visibilidad de una exportación sin el permiso esperado. La versión revisada no debe presentarse como aceptada integralmente por la empresa.
La revisión comprendió requisitos del Excel, estructura de módulos, casos de uso, modelos, políticas, recursos y acciones del panel, migraciones, vistas y restricciones de PostgreSQL, configuración de Docker y pruebas. Se contrastaron archivos y se ejecutaron herramientas; no se afirma una inspección manual exhaustiva de cada línea ni una sesión completa de todos los flujos en navegador.
# 1 Entregables revisados y alcance
Se conserva el archivo original y se entrega una versión nueva del Excel con sus ocho hojas de origen y una hoja Gestión PMI. Se mantienen 63 historias y la planificación del 19 de octubre al 10 de diciembre. La aceptación pasa de 36 escenarios para 12 historias a 191 escenarios para 63 historias. Los tres elementos futuros HU-047, HU-055 y HU-056 permanecen identificados fuera de la fase inicial.
Cada escenario registra contexto, evento, resultado esperado, valores de entrada, pasos, clase o componente, pruebas relacionadas, nivel, resultado observado y pendientes. El estado técnico de una historia se diferencia del cierre de sus criterios. La media de criterios conformes de Cronograma se calcula desde el backlog y no representa el porcentaje de desarrollo del sistema.
Se amplió Sprint 1 a las tareas que le corresponden y se corrigió su duración a 14 días. El gráfico de esfuerzo muestra únicamente la serie prevista; no se inventó un historial diario real. Se actualizaron estados y notas de evidencia, conservando las estimaciones de origen y los responsables que aún están por asignar. Las fechas de revisión se identifican como previstas.
El informe académico mantiene los capítulos de origen y corrige el stack, las métricas y las afirmaciones no demostradas. El capítulo 5 desarrolla todo el producto objetivo y el capítulo 6 informa las pruebas ejecutadas y sus límites. Los modelos se generaron en Enterprise Architect; los elementos futuros están identificados como propuestos. El inventario ERwin incluye entidades, atributos y claves primarias, con la limitación relacional declarada en los anexos.
# 2 Entorno y resultados reproducibles
| Elemento | Resultado verificado |
| Fecha de corte | 28 octubre 2026 |
| Entorno de ejecución | Docker Engine 29.5.2 y PHP 8.4.26 en tools |
| Aplicación y base | Laravel 13.34.0 y PostgreSQL 17 |
| Panel instalado | Filament 5.9.0 y Livewire 4.4.7 |
| Pest ordinario | 253 aprobadas, 1363 aserciones, cero fallidas u omitidas; 365,80 segundos |
| Pest con PCOV | 253 aprobadas y 1363 aserciones; 353,51 segundos |
| Cobertura de líneas | 1913 de 1997; 95,79 por ciento; filtro app/Modules |
| Cobertura de métodos y clases | 320 de 370 métodos y 99 de 138 clases según el generador |
| Pint | 377 archivos conformes |
| PHPStan nivel 6 | Sin errores |
| Deptrac | Cero infracciones, cero no cubiertas y 769 permitidas |
La cobertura no comprende el panel y otros archivos fuera de app/Modules. PCOV no midió ramas ni caminos. Las duraciones de Pest no son mediciones de latencia del sistema. No se ejecutaron JMeter, ZAP, SUS ni una restauración de respaldo y no se acredita una canalización remota de integración continua.
# 3 Validación de aceptación
Se ejecutaron 41 criterios mediante clases reales con dobles de dominio y cinco comprobaciones adicionales mediante Laravel y PostgreSQL. CA-019-4 aparece en ambos procedimientos; por ello, el total consolidado es 45 criterios distintos y no 46. Cuarenta y dos resultaron conformes y tres no conformes. Los restantes 146 se distinguen entre 119 casos específicos aún no ejecutados y 27 vinculados a funciones pendientes.
La conexión de la comprobación SQL se restringió de forma explícita a wms_test con APP_ENV=testing. Se comprobó el nombre efectivo de la base y se revirtieron los datos de cada ensayo al terminar. El esquema de pruebas fue migrado sin reconstruir la base operativa. Las comprobaciones SQL adicionales no insertaron operaciones de negocio en wms.
Los 36 escenarios iniciales se conservaron y se relacionaron con comprobaciones de dominio o integración. La validación de dominio con dobles no prueba la interfaz ni el almacenamiento real. La aceptación empresarial y los recorridos completos por navegador siguen pendientes.
# 4 Hallazgos que requieren corrección
## D01 Conflicto de contenido en un reintento
Criterio CA-019-4, relacionado con HU-019. Se registró un movimiento de cantidad 10 y después se repitieron el UUID y la clave de idempotencia cambiando la cantidad a 99. RegisterMovement devolvió el resultado como duplicado porque compara la identidad, pero no rechaza el cambio de contenido. El saldo no se duplicó; el defecto consiste en ocultar una solicitud contradictoria.
Se recomienda definir una representación canónica de los datos relevantes y comparar producto, tipo, cantidad, costo, origen, destino y documento según el contrato de la operación. Un reintento idéntico debe recuperar el resultado original; uno incompatible debe rechazarse de forma explícita. La corrección debe incluir regresión de ambos comportamientos y ejecución concurrente.
## D02 Apertura de inventario repetida
Criterio CA-053-2, relacionado con HU-053. Se registraron dos movimientos SALDO_INICIAL de cantidad 10 para el mismo producto y ubicación con identificadores distintos. El saldo final observado fue 20,000. La idempotencia de cada movimiento no evita una segunda apertura cuando se emplea otra clave.
La unicidad de apertura debe ratificarse con el negocio y materializarse en el importador y en una protección transaccional consistente. Las rectificaciones posteriores requieren un procedimiento autorizado y auditable, con pruebas que distingan apertura, ajuste y reintento. No se propone borrar movimientos del ledger para corregir el problema.
## D03 Exportación visible sin el permiso previsto
Criterio CA-045-4, relacionado con HU-045. Un usuario con rol lectura y sin reports.export obtuvo visible la acción export de ListReplenishment. Se comprobó la configuración de visibilidad de la acción dentro de Laravel; no se ejecutó una descarga HTTP completa con ese usuario.
Se recomienda aplicar la autorización en la acción y en el servidor, y probar ambos puntos para roles permitidos y denegados. El hallazgo demuestra una brecha en la visibilidad prevista; el alcance de acceso efectivo al archivo necesita una prueba integral adicional antes de describirlo como una descarga no autorizada confirmada.
## D04 Dependencias del panel no declaradas
Filament y Livewire existen en vendor, pero no se encuentran declarados en composer.json ni composer.lock. Composer validate aprobó la estructura del manifiesto; esa validación no demuestra que composer install reconstruya el panel en un equipo limpio. Deben declararse las dependencias compatibles y verificarse la instalación con el archivo de bloqueo actualizado en un entorno separado.
No se modificó el código de la aplicación para ocultar los hallazgos ni se cambiaron dependencias durante esta auditoría. Los escenarios nuevos expresan reglas propuestas o precisadas que requieren validación del responsable del producto y, después, corrección y regresión según la decisión adoptada.
# 5 Observaciones adicionales del sistema
| Área | Observación | Siguiente comprobación |
| Kardex | La consulta limita a 500 movimientos sin paginación ni aviso | Definir alcance y probar productos con más de 500 movimientos |
| Exportaciones | Las revisadas utilizan vistas globales sin trasladar filtros de pantalla | Confirmar si cada exportación es global o filtrada y explicitarlo |
| Exactitud de conteo | Dos líneas conformes de tres producen 66,66 por truncamiento | Acordar política de redondeo y probar valores límite |
| Stock y reposición | Las fronteras y cantidades responden a expresiones SQL específicas | Validar mínimo, máximo y lote de reposición con compras |
| Conciliación | Existe soporte SQL pero no programación nocturna identificada | Completar tarea, registro de diferencias y programación |
| Arquitectura | Deptrac no incluye toda la presentación real; pruebas explícitas de cuatro módulos | Ampliar límites y comprobar dependencias efectivas |
| Roles de catálogo | Políticas actuales difieren de algunos actores del backlog original | Ratificar con empresa y actualizar requisito o política |
| Movilidad y carga | Importadores, cámara y operación sin conexión no tienen flujo completo | Construir y ejecutar sus criterios según la planificación |
| Continuidad y entrega | Respaldo restaurado, despliegue productivo y métricas con usuarios no acreditados | Ejecutar protocolos y conservar evidencia antes del cierre |
Las correcciones editoriales no deben cambiar el compromiso del negocio para hacer coincidir el documento con el código. Por ello, el informe registra la necesidad de ratificar los roles de catálogo y las reglas nuevas de apertura e idempotencia.
# 6 Procedimiento para repetir los controles
Ejecutar desde la raíz del proyecto con Docker operativo. El servicio tools es temporal y monta el código del proyecto. Las pruebas de base de datos deben conservar su conexión aislada de testing; revisar esa configuración antes de ejecutar una suite que migra o limpia datos de prueba.
@code docker compose run --rm -T tools php vendor/bin/pint --test
@code docker compose run --rm -T tools php vendor/bin/phpstan analyse --memory-limit=1G
@code docker compose run --rm -T tools php vendor/bin/deptrac analyse
@code docker compose run --rm -T tools php vendor/bin/pest --colors=never
Los resultados se conservan en pest-junit.xml, coverage.xml, coverage.txt y las salidas de los controles. La medición de cobertura usó PCOV instalado en un contenedor temporal; no se declara PCOV como dependencia ya persistida de la imagen del proyecto. El filtro de cobertura debe conservarse o modificarse de manera explícita para poder comparar ejecuciones.
Las comprobaciones adicionales se conservan como archivos de texto acceptance_probe.php.txt y sql_acceptance.php.txt, con sus resultados JSON. Cargan código real y verifican casos específicos; no sustituyen pruebas permanentes de la suite. Antes de incorporarlas al proyecto deben transformarse en pruebas mantenibles y revisarse las reglas de negocio con el Product Owner.
# 7 Prioridad del siguiente incremento
Resolver y revalidar D01, D02 y D03; declarar las dependencias y comprobar instalación limpia; ratificar actores y permisos; ampliar la aceptación específica y la prueba completa del panel. Después, completar carga inicial, conciliación y capacidades móviles según prioridad, medir carga y SUS, ensayar restauración y preparar la entrega.
La revisión del profesor puede apoyarse en la trazabilidad de una fila del Excel hacia su historia, clase y prueba y en la evidencia del resultado. Los datos ausentes se mantienen como pendientes. La aprobación final requiere resultados reproducibles y validación de la empresa, además de documentación corregida.
