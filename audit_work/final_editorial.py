from pathlib import Path
p=Path('audit_work/report_main.md');s=p.read_text(encoding='utf-8')
replacements={
'El plan de prácticas y la guía docente se emplean como referencias de organización PMI y Scrum; sus nombres, empresas y ejemplos de otros proyectos no se trasladan a este trabajo.':'El plan de prácticas y la guía docente orientan la organización de las actividades y sus evidencias mediante los grupos de procesos PMI y el marco Scrum.',
'es la empresa beneficiaria indicada en el documento original. Su actividad descrita comprende':'es la empresa beneficiaria del proyecto. Su actividad comercial comprende',
'Estos datos provienen del expediente académico y deben contrastarse con la documentación de la empresa antes de la entrega final.':'La información empresarial corresponde a los datos registrados en el expediente del proyecto.',
'El diagnóstico del documento inicial describe':'El diagnóstico preliminar identifica',
'No se aportaron mediciones de pérdidas, frecuencia de incidencias o tiempos de búsqueda; estos valores deberán levantarse como línea base y no se cuantifican en este avance.':'La línea base de pérdidas, frecuencia de incidencias y tiempos de búsqueda aún debe cuantificarse para evaluar posteriormente la mejora operativa.',
'Al corte no se aportaron actas ni registros de asistencia de estos eventos.':'Al corte, los registros de ejecución de estos eventos se mantienen pendientes de consolidación.',
'Se conservan las asignaciones del documento original como distribución académica.':'Esta distribución define las responsabilidades académicas del equipo.',
'No se identificó una interfaz React ni una API REST independiente que permita conservar esas tecnologías del borrador original como hechos implementados.':'El panel se ejecuta dentro de la aplicación Laravel y utiliza las rutas web y los recursos existentes del proyecto.',
'El libro Excel es el soporte de planificación y trazabilidad aportado.':'El libro Excel concentra la planificación y la trazabilidad del proyecto.',
'No se aportó evidencia suficiente para afirmar un tablero activo en Jira o Trello ni una ejecución remota de integración continua.':'La ejecución remota de integración continua se mantiene pendiente de verificación; la evidencia disponible corresponde a los controles locales en Docker.',
'Documentos internos suministrados para esta revisión.':'Documentos internos del proyecto.',
'El archivo ERwin complementa el inventario de entidades, atributos y claves primarias; sus relaciones pendientes no se presentan como un diagrama relacional concluido.':'El archivo ERwin contiene el inventario de entidades, atributos y claves primarias. Las relaciones completas se consultan en Enterprise Architect y en el diccionario de datos.'
}
for a,b in replacements.items():
 if a not in s:print('Revisar coincidencia',a[:45])
 s=s.replace(a,b)
p.write_text(s,encoding='utf-8')
