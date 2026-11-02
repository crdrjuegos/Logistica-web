from pathlib import Path
p=Path('audit_work/render_native_pdf.py');s=p.read_text(encoding='utf-8').replace('import fitz','import pypdfium2 as pdfium\nfrom pypdf import PdfReader')
s=s.replace("pdf=fitz.open(A/(name+'.pdf'));out=", "pdf=pdfium.PdfDocument(A/(name+'.pdf'));reader=PdfReader(A/(name+'.pdf'));out=")
s=s.replace("pix=p.get_pixmap(matrix=fitz.Matrix(1.3,1.3));pix.save(out/f'page-{i+1}.png');txt=p.get_text();", "p.render(scale=1.3).to_pil().save(out/f'page-{i+1}.png');txt=reader.pages[i].extract_text()or'';")
s=s.replace("len(p.get_images())", "len(reader.pages[i].images)").replace('p.rect.width,p.rect.height','p.get_width(),p.get_height()')
p.write_text(s,encoding='utf-8')
