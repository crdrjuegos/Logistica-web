from pathlib import Path
p=Path('audit_work/build_reports.py');s=p.read_text(encoding='utf-8').replace('sec=d.sections[0];sec.page_width=', 'sec=d.sections[0];sec.different_first_page_header_footer=True;sec.page_width=').replace('sec=d.add_section(WD_SECTION_START.NEW_PAGE);sec.orientation=', 'sec=d.add_section(WD_SECTION_START.NEW_PAGE);sec.different_first_page_header_footer=False;sec.orientation=')
p.write_text(s,encoding='utf-8');compile(s,'build_reports.py','exec')
