from pathlib import Path
p=Path('audit_work/build_reports.py');s=p.read_text(encoding='utf-8')
s=s.replace("fig_no+=1;v=exports[key];large=key.startswith(('er_','clases_'));land=orient=='landscape'and not large;sec=section(d,land)","fig_no+=1;v=exports[key];large=key.startswith(('er_','clases_'));im=Image.open(v['path']);wide=large and im.width/im.height>1.2;land=orient=='landscape'and not large;sec=section(d,land)")
s=s.replace("if large:sec.page_width=Cm(29.7);sec.page_height=Cm(42);", "if large:sec.page_width=Cm(42 if wide else 29.7);sec.page_height=Cm(29.7 if wide else 42);")
s=s.replace("mxw,mxh=(25.5,34.5)if large else", "mxw,mxh=((37.5,22.7)if wide else(25.5,34.5))if large else")
p.write_text(s,encoding='utf-8');compile(s,'build_reports.py','exec')
p=Path('audit_work/report_main.md');s=p.read_text(encoding='utf-8').replace('Los actores representan roles funcionales;', 'Se utiliza notación rectangular de casos de uso con su icono UML. Los actores se repiten por historia para mantener asociaciones legibles y representan el mismo rol funcional;').replace('Las vistas de clases muestran atributos, visibilidad, operaciones, parámetros, tipos y relaciones relevantes.', 'Las vistas de clases muestran atributos, visibilidad, operaciones, parámetros, tipos y relaciones relevantes. Las láminas de clases y datos utilizan formato A3 para conservar la legibilidad del detalle; el texto principal mantiene A4.')
p.write_text(s,encoding='utf-8')
