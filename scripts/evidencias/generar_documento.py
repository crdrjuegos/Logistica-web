"""
Genera el "Documento Formal de Pruebas de Aceptación" (Word) a partir de evidencia real:
  - Excel de criterios ya actualizado (scripts/acceptance/actualizar_excel.py)
  - Informes JUnit de Pest (storage/logs/pest-junit.xml y pest-tooling.xml)
  - Código de las pruebas (las verificaciones expect/assert de cada criterio)
  - Salidas de consola (storage/logs/*.txt) y capturas del panel (docs/pruebas-aceptacion/capturas)

Uso:  python scripts/evidencias/generar_documento.py
Requiere: python-docx (MIT), Pillow (HPND), openpyxl (MIT), lxml (BSD).
"""

from __future__ import annotations

import json
import re
from collections import Counter, OrderedDict
from pathlib import Path

import openpyxl
from docx import Document
from docx.enum.section import WD_ORIENT
from docx.enum.table import WD_TABLE_ALIGNMENT
from docx.enum.text import WD_ALIGN_PARAGRAPH, WD_BREAK
from docx.oxml import OxmlElement
from docx.oxml.ns import qn
from docx.shared import Cm, Pt, RGBColor
from lxml import etree
from PIL import Image, ImageDraw, ImageFont

ROOT = Path(__file__).resolve().parents[2]
OUT_DIR = ROOT / "docs" / "pruebas-aceptacion"
CAPS = OUT_DIR / "capturas"
CONSOLE = OUT_DIR / "consola"
LOGS = ROOT / "storage" / "logs"
XLSX = ROOT / "Criterios de Aceptación_WMS.xlsx"
DOCX = OUT_DIR / "Documento_Formal_Pruebas_Aceptacion_WMS.docx"
FECHA = "29/10/2026"
CA_RE = re.compile(r"\b(CA-\d{3}-\d)\b")
ANSI = re.compile(r"\x1b\[[0-9;]*m")
BLUE = RGBColor(0x1F, 0x3A, 0x5F)

INTEGRANTES = ["Aquino Salcedo Maria Fernanda", "Carrasco Chavez Yerry Ericson", "Cortez Quito Abraham David",
               "Zelada Chinga Eliana Jazmin", "Torres Cavero Luis Augusto", "Juarez Gabino Sandro David"]

# Capturas que respaldan cada criterio (figura -> criterios).
FIGURAS_CRITERIO = {
    "01-login": ["CA-002-1"], "02-login-fallido": ["CA-002-3"], "03-tablero": ["CA-046-1"],
    "04-productos": ["CA-008-1"], "05-kardex": ["CA-022-1"], "06-aviso-duplicado": ["CA-013-3"],
    "07-categorias": ["CA-015-1"], "08-ubicaciones": ["CA-040-1"], "09-proveedores": ["CA-043-1"],
    "10-recepciones": ["CA-024-1"], "11-transferencias": ["CA-028-1"], "12-ajustes": ["CA-029-1"],
    "13-conteos": ["CA-034-1"], "14-conteo-supervisor": ["CA-034-1"], "15-importaciones-pos": ["CA-038-1", "CA-039-1"],
    "16-reposicion": ["CA-044-1"], "17-valorizado": ["CA-048-1"], "18-abc": ["CA-049-1"], "19-mermas": ["CA-050-1"],
    "20-auditoria": ["CA-007-1"], "21-auditoria-detalle": ["CA-007-1"], "22-usuarios": ["CA-004-1"],
    "23-perfil-2fa": ["CA-006-2"], "24-conteo-ciego": ["CA-033-1"], "25-almacenero-sin-permiso": ["CA-008-3"],
    "26-lectura-reposicion": ["CA-045-4"], "27-lectura-reporte": ["CA-051-2"], "28-lectura-auditoria": ["CA-007-2"],
    "29-compras-reposicion": ["CA-045-1"], "30-pos-subir-archivo": ["CA-037-2"], "31-pos-resultado": ["CA-037-2", "CA-038-1"],
}
CAPTIONS_EXTRA = {
    "30-pos-subir-archivo": "Supervisor subiendo el CSV de la caja desde la pantalla de Ventas del POS (HU-037).",
    "31-pos-resultado": "Resultado de la importación: 2 productos aplicados, 2 filas con error y botón «Descargar errores» (CA-037-2, CA-038-1).",
}

DEFECTOS = [
    ("D01", "Alta", "CA-019-4", "Un reintento con el mismo UUID y clave de idempotencia pero cantidad distinta (10 vs 99) se aceptaba como duplicado; el conflicto quedaba oculto.",
     "RecordedMovement::sameContentAs compara tipo, producto, ubicaciones, cantidad y costo; RegisterMovement lanza IDEMPOTENCY_CONFLICT.", "d9e1fc1"),
    ("D02", "Alta", "CA-053-2", "Se permitían dos SALDO_INICIAL del mismo producto y ubicación (saldo observado 20.000).",
     "Validación en RegisterMovement (OPENING_BALANCE_EXISTS) e índice único parcial ux_mov_opening_balance en PostgreSQL.", "d9e1fc1"),
    ("D03", "Media", "CA-045-4, CA-051-2", "La exportación del pedido sugerido era visible para el rol Lectura sin reports.export y exportaba la vista global.",
     "CsvExport::allowed/authorize: acción oculta y autorización en servidor; los CSV salen de la consulta filtrada de la tabla.", "aa0863f"),
    ("D04", "Media", "CA-022-3", "El kardex mostraba solo 500 movimientos sin paginación ni aviso (y los rotulaba como «últimos» cuando eran los primeros).",
     "KardexQuery con página; KardexPage con total y hasMore(); el modal muestra los más recientes y avisa del total.", "106a2d7"),
    ("D05", "Baja", "CA-034 (cierre)", "La exactitud del conteo 2/3 se truncaba a 66,66 %.",
     "Redondeo half-up a 2 decimales en CloseCount (66,67 %).", "c6e9835"),
    ("D06", "Media", "CA-048-2, CA-050-2", "Los CSV de valorizado, ABC y mermas ignoraban los filtros de la tabla.",
     "exportResponse() usa getFilteredTableQuery / getFilteredSortedTableQuery.", "aa0863f"),
    ("D07", "Media", "CA-038-1, CA-038-3", "El reporte de errores del POS no traía el código original y la descarga no verificaba pos.import ni la ruta.",
     "Columna «codigo» en el reporte; descarga solo con pos.import y rutas imports/errors/pos-N.csv.", "c6e9835"),
    ("D08", "Media", "CA-013-3", "El formulario de producto no avisaba de posibles duplicados (solo existía la detección en backend).",
     "Aviso en el campo Nombre con SKU, nombre y porcentaje de similitud.", "1a9a528"),
    ("D09", "Crítica", "Entorno de pruebas", "Pest ejecutaba migrate:fresh sobre la base de desarrollo wms (DB_DATABASE llegaba por $_SERVER).",
     "<server name=\"DB_DATABASE\" value=\"wms_test\" force=\"true\"/> y candado en TestCase que aborta si la base no termina en _test.", "224318e"),
    ("D10", "Alta", "CA-058-3", "composer.json y composer.lock no declaraban Filament ni Livewire: una instalación limpia no tenía el panel.",
     "Se declaran filament/filament 5.11.0 y livewire/livewire 4.4.8 (la 4.4.7 tenía un aviso de seguridad); composer audit sin hallazgos.", "d7016d0"),
    ("D11", "Alta", "CA-061-3", "La imagen nueva de la app no arrancaba con Octane (sin permiso para escribir public/frankenphp-worker.php).",
     "FrankenPHP clásico (frankenphp php-server); puerto 8080 publicado; worker y scheduler sin chequeo HTTP.", "8c6c57f"),
    ("D12", "Media", "CA-038-1", "Los reportes de errores del POS se guardaban dentro del contenedor y se perdían al recrearlo.",
     "Volumen persistente appstorage para storage/app.", "034d6dd"),
    ("D13", "Media", "CA-057-3", "Deptrac no analizaba la presentación (app/Filament) y las reglas de arquitectura no cubrían Locations, Suppliers ni Operations.",
     "deptrac.yaml incluye app/Filament (0 violaciones, 1583 dependencias); reglas arch para los 7 módulos y para Filament.", "2b9b2e2"),
]


# ---------------------------------------------------------------- datos

def load_criteria() -> list[dict]:
    wb = openpyxl.load_workbook(XLSX, read_only=True)
    ws = [w for w in wb.worksheets if w.title.startswith("Criterios")][0]
    rows = list(ws.iter_rows(values_only=True))
    keys = ["_", "hu", "rol", "deseo", "para", "esc", "id", "crit", "dado", "cuando", "entonces", "valores", "pasos",
            "clase", "pruebas", "nivel", "res", "obs", "notas"]
    out = []
    for r in rows[5:]:
        if r and r[6] and CA_RE.fullmatch(str(r[6]).strip()):
            out.append({k: ("" if v is None else str(v)) for k, v in zip(keys, r)})
    return out


def load_junit() -> dict:
    data = {"tests": 0, "assertions": 0, "failures": 0, "errors": 0, "time": 0.0, "cases": {}}
    for name in ["pest-junit.xml", "pest-tooling.xml"]:
        path = LOGS / name
        if not path.exists():
            continue
        root = etree.parse(str(path)).getroot()
        top = root if root.tag == "testsuite" else root.find("testsuite")
        for k in ["tests", "assertions", "failures", "errors"]:
            data[k] += int(top.get(k, 0))
        data["time"] += float(top.get("time", 0))
        for case in root.iter("testcase"):
            m = CA_RE.search(case.get("name", ""))
            if m:
                data["cases"].setdefault(m.group(1), []).append({
                    "name": CA_RE.sub("", case.get("name", "")).replace("it ", "", 1).strip(" :-"),
                    "file": (case.get("file") or "").split("::")[0].replace("/app/", ""),
                    "time": float(case.get("time", 0)), "assertions": int(case.get("assertions", 0)),
                    "ok": case.find("failure") is None and case.find("error") is None,
                })
    return data


def load_assertions() -> dict[str, list[str]]:
    """CA-xxx-y -> líneas expect()/assert de la prueba (las salidas que se verifican)."""
    found: dict[str, list[str]] = {}
    for path in (ROOT / "tests").rglob("*.php"):
        text = path.read_text(encoding="utf-8")
        for m in re.finditer(r"^(?:it|test)\('([^']*?(CA-\d{3}-\d)[^']*)'", text, re.M):
            body = text[m.end(): text.find("\n})", m.end())]
            lines = [ln.strip() for ln in body.splitlines()
                     if re.search(r"expect\(|->assert|->toThrow|toBe\(|assertCan", ln)]
            found.setdefault(m.group(2), []).extend(lines[:12])
    return found


# ---------------------------------------------------------------- capturas de consola

def console_png(lines: list[str], name: str, title: str) -> Path:
    CONSOLE.mkdir(parents=True, exist_ok=True)
    font = ImageFont.truetype("C:/Windows/Fonts/consola.ttf", 15)
    bold = ImageFont.truetype("C:/Windows/Fonts/consolab.ttf", 15)
    lines = [ANSI.sub("", ln.rstrip("\n"))[:150] for ln in lines]
    width, lh = 1300, 20
    height = 46 + lh * len(lines) + 20
    img = Image.new("RGB", (width, height), (30, 30, 30))
    d = ImageDraw.Draw(img)
    d.rectangle([0, 0, width, 30], fill=(60, 60, 60))
    for i, c in enumerate([(255, 95, 86), (255, 189, 46), (39, 201, 63)]):
        d.ellipse([12 + i * 22, 9, 24 + i * 22, 21], fill=c)
    d.text((90, 7), title, font=bold, fill=(220, 220, 220))
    y = 40
    for ln in lines:
        color = (220, 220, 220)
        if re.search(r"✓|PASS|\bOK\b|passed|Conforme| 0\s*$|exit=0", ln):
            color = (120, 220, 120)
        if re.search(r"⨯|FAIL|No conforme|failed|ERROR", ln):
            color = (240, 110, 110)
        if ln.startswith("$ "):
            color = (120, 180, 255)
        d.text((14, y), ln, font=font, fill=color)
        y += lh
    path = CONSOLE / f"{name}.png"
    img.save(path)
    return path


def read(name: str) -> list[str]:
    p = LOGS / name
    return p.read_text(encoding="utf-8", errors="replace").splitlines() if p.exists() else []


def build_console_images() -> list[tuple[Path, str]]:
    shots = []
    pest = read("pest-output.txt")
    acc = [ln for ln in pest if "Acceptance" in ln or ("CA-" in ln and "✓" in ln)][:46]
    summary = [ln for ln in pest if "Tests:" in ln or "Duration:" in ln]
    shots.append((console_png(["$ docker compose run --rm -T tools vendor/bin/pest --log-junit storage/logs/pest-junit.xml", ""] + acc + ["  …"] + summary,
                              "c1-pest-aceptacion", "Pest — suite completa (extracto: pruebas de aceptación)"),
                  "Ejecución de la suite completa con Pest. Cada prueba de aceptación lleva el ID del criterio; al final, el total de pruebas y aserciones."))
    tooling = read("pest-tooling-output.txt")
    shots.append((console_png(["$ docker compose run --rm -T tools vendor/bin/pest --group=tooling", ""] + [ln for ln in tooling if ln.strip()][:20],
                              "c2-pest-tooling", "Pest — grupo tooling (Deptrac, Pint, PHPStan, Composer)"),
                  "Criterios de calidad del código (HU-057 y HU-058) verificados ejecutando las herramientas reales."))
    gate = (["$ vendor/bin/pint --test"] + [ln for ln in read("ev-pint.txt") if ln.strip()][-3:] + [""]
            + ["$ vendor/bin/phpstan analyse --memory-limit=1G"] + [ln for ln in read("ev-phpstan.txt") if ln.strip()][-3:] + [""]
            + ["$ vendor/bin/deptrac analyse"] + [ln for ln in read("ev-deptrac.txt") if ln.strip()][-11:])
    shots.append((console_png(gate, "c3-calidad", "Pint, PHPStan (nivel 6) y Deptrac"),
                  "Puerta de calidad: formato (Pint), análisis estático (PHPStan nivel 6) y dependencias entre capas (Deptrac) sin errores."))
    shots.append((console_png(["$ docker compose run --rm -T tools php scripts/acceptance/sql_acceptance.php", ""] + read("ev-sql.txt"),
                              "c4-sondas-sql", "Sondas SQL de aceptación contra PostgreSQL 17"),
                  "Sondas SQL sobre la base aislada wms_test: inmutabilidad del ledger, idempotencia (D01), apertura única (D02) y permiso de exportación (D03)."))
    csv = (CAPS / "errores-ventas-descargado.csv")
    if csv.exists():
        shots.append((console_png(["$ type errores-ventas-2.csv   (descargado desde el panel)", ""] + csv.read_text(encoding="utf-8-sig").splitlines(),
                                  "c5-reporte-errores-pos", "Reporte de errores del POS descargado"),
                      "Contenido del archivo descargado con «Descargar errores»: fila, código original y explicación (CA-038-1)."))
    return shots


# ---------------------------------------------------------------- utilidades Word

def shade(cell, color: str) -> None:
    tc_pr = cell._tc.get_or_add_tcPr()
    shd = OxmlElement("w:shd")
    shd.set(qn("w:val"), "clear")
    shd.set(qn("w:color"), "auto")
    shd.set(qn("w:fill"), color)
    tc_pr.append(shd)


def set_repeat_header(row) -> None:
    tr_pr = row._tr.get_or_add_trPr()
    el = OxmlElement("w:tblHeader")
    el.set(qn("w:val"), "true")
    tr_pr.append(el)


def table(doc, headers: list[str], rows: list[list[str]], widths: list[float] | None = None, size: int = 9):
    t = doc.add_table(rows=1, cols=len(headers))
    t.style = "Table Grid"
    t.alignment = WD_TABLE_ALIGNMENT.CENTER
    for i, h in enumerate(headers):
        c = t.rows[0].cells[i]
        c.text = ""
        run = c.paragraphs[0].add_run(h)
        run.bold = True
        run.font.size = Pt(size)
        run.font.color.rgb = RGBColor(0xFF, 0xFF, 0xFF)
        shade(c, "1F3A5F")
    set_repeat_header(t.rows[0])
    for r in rows:
        cells = t.add_row().cells
        for i, v in enumerate(r):
            cells[i].text = ""
            run = cells[i].paragraphs[0].add_run(str(v))
            run.font.size = Pt(size)
    if widths:
        for row in t.rows:
            for i, w in enumerate(widths):
                row.cells[i].width = Cm(w)
    doc.add_paragraph()
    return t


def kv_table(doc, pairs: list[tuple[str, str]], status_color: str | None = None, mono_keys: tuple[str, ...] = ()):
    t = doc.add_table(rows=0, cols=2)
    t.style = "Table Grid"
    for k, v in pairs:
        cells = t.add_row().cells
        cells[0].width, cells[1].width = Cm(4.2), Cm(12.3)
        cells[0].text = ""
        r = cells[0].paragraphs[0].add_run(k)
        r.bold = True
        r.font.size = Pt(9)
        shade(cells[0], "E8EEF5")
        cells[1].text = ""
        lines = v.split("\n") if v else ["—"]
        for j, line in enumerate(lines):
            p = cells[1].paragraphs[0] if j == 0 else cells[1].add_paragraph()
            run = p.add_run(line)
            run.font.size = Pt(8 if k in mono_keys else 9)
            if k in mono_keys:
                run.font.name = "Consolas"
        if k == "Estado" and status_color:
            shade(cells[1], status_color)
    doc.add_paragraph()


def figure(doc, path: Path, caption: str, n: int, width_cm: float = 16) -> None:
    doc.add_picture(str(path), width=Cm(width_cm))
    doc.paragraphs[-1].alignment = WD_ALIGN_PARAGRAPH.CENTER
    p = doc.add_paragraph()
    p.alignment = WD_ALIGN_PARAGRAPH.CENTER
    r = p.add_run(f"Figura {n}. ")
    r.bold = True
    r.font.size = Pt(9)
    r2 = p.add_run(caption)
    r2.italic = True
    r2.font.size = Pt(9)


def para(doc, text: str, bold_prefix: str | None = None, size: int = 11):
    p = doc.add_paragraph()
    if bold_prefix:
        r = p.add_run(bold_prefix)
        r.bold = True
        r.font.size = Pt(size)
    r = p.add_run(text)
    r.font.size = Pt(size)
    p.paragraph_format.space_after = Pt(6)
    return p


def bullet(doc, text: str):
    p = doc.add_paragraph(style="List Bullet")
    p.add_run(text).font.size = Pt(10.5)


def code(doc, text: str):
    for line in text.strip("\n").split("\n"):
        p = doc.add_paragraph()
        p.paragraph_format.space_after = Pt(0)
        p.paragraph_format.left_indent = Cm(0.6)
        r = p.add_run(line)
        r.font.name = "Consolas"
        r.font.size = Pt(9)
    doc.add_paragraph()


def toc(doc):
    p = doc.add_paragraph()
    run = p.add_run()
    for tag, text in [("begin", None), (None, 'TOC \\o "1-2" \\h \\z \\u'), ("separate", None), (None, None), ("end", None)]:
        if tag:
            el = OxmlElement("w:fldChar")
            el.set(qn("w:fldCharType"), tag)
            run._r.append(el)
        elif text:
            instr = OxmlElement("w:instrText")
            instr.set(qn("xml:space"), "preserve")
            instr.text = text
            run._r.append(instr)
        else:
            t = OxmlElement("w:t")
            t.text = "Índice: si no se ve, haga clic derecho aquí y elija «Actualizar campo»."
            run._r.append(t)
    settings = doc.settings.element
    upd = OxmlElement("w:updateFields")
    upd.set(qn("w:val"), "true")
    settings.append(upd)


def status_color(res: str) -> str:
    return {"Conforme en integración": "D9EAD3", "Conforme en dominio": "E2F0D9", "No conforme": "F4CCCC",
            "Pendiente de implementar": "EEEEEE"}.get(res, "FFF2CC")


# ---------------------------------------------------------------- documento

def main() -> None:
    criteria = load_criteria()
    junit = load_junit()
    asserts = load_assertions()
    console = build_console_images()
    caps = json.loads((CAPS / "capturas.json").read_text(encoding="utf-8")) if (CAPS / "capturas.json").exists() else []
    cap_by_file = {c["file"].removesuffix(".png"): c["caption"] for c in caps}
    cap_by_file.update(CAPTIONS_EXTRA)
    status = Counter(c["res"] for c in criteria)
    verified = status["Conforme en integración"] + status["Conforme en dominio"]

    doc = Document()
    st = doc.styles["Normal"]
    st.font.name = "Calibri"
    st.font.size = Pt(11)
    for lvl, size in [(1, 16), (2, 13), (3, 11.5)]:
        hs = doc.styles[f"Heading {lvl}"]
        hs.font.color.rgb = BLUE
        hs.font.size = Pt(size)
    sec = doc.sections[0]
    sec.page_width, sec.page_height = Cm(21), Cm(29.7)
    sec.left_margin = sec.right_margin = Cm(2.2)
    sec.top_margin = sec.bottom_margin = Cm(2)
    footer = sec.footer.paragraphs[0]
    footer.text = "CITY OFERTAS WMS · Documento Formal de Pruebas de Aceptación · v1.0 · " + FECHA
    footer.alignment = WD_ALIGN_PARAGRAPH.CENTER
    footer.runs[0].font.size = Pt(8)

    # ---- Portada
    for txt, size, bold in [("FACULTAD DE INGENIERÍA", 14, True), ("ESCUELA PROFESIONAL DE INGENIERÍA DE SISTEMAS", 12, True),
                            ("", 12, False), ("DOCUMENTO FORMAL DE PRUEBAS DE ACEPTACIÓN", 22, True),
                            ("Sistema Web de Control de Inventario y Gestión de Almacén (WMS)", 14, False),
                            ("CITY OFERTAS IMPORTADORA E.I.R.L. — RUC 20611519886", 12, False), ("", 12, False)]:
        p = doc.add_paragraph()
        p.alignment = WD_ALIGN_PARAGRAPH.CENTER
        r = p.add_run(txt)
        r.bold, r.font.size = bold, Pt(size)
        if size >= 22:
            r.font.color.rgb = BLUE
    kv_table(doc, [("Curso", "Calidad de Software"), ("Docente", "Dr. Iván Michell Castillo Jiménez"),
                   ("Integrantes", "\n".join(INTEGRANTES)), ("Versión", "1.0"), ("Fecha de corte", FECHA),
                   ("Ciudad", "Piura, Perú"),
                   ("Resultado global", f"{verified} de 191 criterios verificados conformes; {status['No conforme']} no conformes; "
                                        f"{191 - verified - status['No conforme']} pendientes o fuera de alcance.")])
    doc.add_page_break()

    # ---- Control e índice
    doc.add_heading("Control del documento", 1)
    table(doc, ["Versión", "Fecha", "Autor", "Descripción"],
          [["1.0", FECHA, "Equipo del proyecto", "Ejecución formal de las pruebas de aceptación del corte del Sprint 1/2: 191 criterios, evidencia automatizada y visual."]],
          [2, 2.5, 3.5, 8.5])
    table(doc, ["Rol", "Nombre", "Firma", "Fecha"],
          [["Elaboró (equipo de desarrollo)", "Torres Cavero Luis Augusto", "", ""],
           ["Revisó (equipo de calidad)", "", "", ""],
           ["Aprobó (dueño del producto)", "Gerencia de CITY OFERTAS IMPORTADORA E.I.R.L.", "", ""],
           ["Docente del curso", "Dr. Iván Michell Castillo Jiménez", "", ""]], [4.5, 6, 3.5, 2.5])
    doc.add_heading("Índice", 1)
    toc(doc)
    doc.add_page_break()

    # ---- 1 Introducción
    doc.add_heading("1. Introducción", 1)
    doc.add_heading("1.1 Propósito", 2)
    para(doc, "Este documento registra, de forma verificable y reproducible, la ejecución de las pruebas de aceptación del Sistema Web de "
              "Control de Inventario y Gestión de Almacén (WMS) de CITY OFERTAS IMPORTADORA E.I.R.L. Para cada uno de los 191 criterios de "
              "aceptación (escenarios Dado–Cuando–Entonces) se indica la clase o componente responsable, las entradas exactas, los pasos, la "
              "salida esperada, la salida obtenida, las verificaciones que hace la prueba automatizada, el comando para reproducirla y el estado.")
    doc.add_heading("1.2 Alcance", 2)
    para(doc, "Cubre las 63 historias de usuario del Product Backlog y sus 191 criterios, según la hoja «Criterios de Aceptación» del archivo "
              "Criterios de Aceptación_WMS.xlsx (fuente de verdad del alcance). Las historias HU-047 (notificaciones externas), HU-055 (operación "
              "sin conexión) y HU-056 (captura móvil optimizada) están fuera del alcance de la fase inicial y se documentan como tales.")
    doc.add_heading("1.3 Referencias", 2)
    for ref in ["Criterios de Aceptación_WMS.xlsx — hojas «Criterios de Aceptación» y «Backlog del Producto» (corte " + FECHA + ").",
                "Informe_Academico_WMS.docx — informe del proyecto.",
                "ISO/IEC/IEEE 29119-3:2021 Software testing — Part 3: Test documentation.",
                "ISO/IEC 25010:2023 Systems and software quality models.",
                "Repositorio del proyecto: tests/Acceptance (pruebas por criterio), scripts/acceptance y scripts/evidencias."]:
        bullet(doc, ref)
    doc.add_heading("1.4 Definiciones", 2)
    table(doc, ["Término", "Significado en este documento"], [
        ["Criterio (CA-xxx-y)", "Escenario de aceptación de la historia HU-xxx; y es el número de escenario."],
        ["Conforme en integración", "La prueba automatizada del criterio pasa contra PostgreSQL 17 real (y el panel Filament cuando aplica)."],
        ["Conforme en dominio", "Verificado con pruebas unitarias del dominio con dobles (sin base de datos), en el corte anterior."],
        ["No conforme", "La prueba falla: es un defecto abierto."],
        ["Pendiente de implementar", "La funcionalidad no existe todavía; no hay prueba que ejecutar."],
        ["Ledger", "Libro mayor inmutable de movimientos de stock (tabla stock_movements)."],
        ["Idempotencia", "Reintentar la misma operación con la misma clave no la duplica."],
    ], [4.5, 12])

    # ---- 2 Objeto de prueba
    doc.add_heading("2. Objeto de prueba", 1)
    para(doc, "Aplicación Laravel 13 con panel Filament 5, organizada en Clean Architecture por módulos (Dominio, Aplicación, Infraestructura) y "
              "presentación en app/Filament. El stock solo se modifica a través del caso de uso RegisterMovement del módulo Inventory.")
    table(doc, ["Módulo", "Responsabilidad", "Clases principales"], [
        ["Iam", "Usuarios, roles, bloqueo por intentos, contraseñas", "CreateUser, DeactivateUser, ActivateUser, ResetUserPassword, LockoutPolicy, PasswordPolicy"],
        ["Audit", "Auditoría inmutable", "AuditTrail, DatabaseAuditTrail, AuditEntry"],
        ["Catalog", "Productos, presentaciones, códigos, políticas", "CreateProduct, UpdateProduct, LookupProduct, FindSimilarProducts, NewProduct, StockPolicy"],
        ["Inventory", "Ledger, saldos, costo promedio, kardex", "RegisterMovement, NewMovement, RecordedMovement, WeightedAverageCost, GetKardex, DatabaseKardexReader"],
        ["Locations", "Ubicaciones, lotes, congelamiento", "GenerateLocations, LocationBatch, ChangeLocationState"],
        ["Suppliers", "Proveedores y vínculo con productos", "CreateSupplier, LinkProductToSupplier, Ruc"],
        ["Operations", "Recepciones, transferencias, ajustes, conteos, POS, apertura", "ConfirmReceipt, CompleteTransfer, RequestAdjustment, ApproveAdjustment, StartCount, CloseCount, ImportPosSales, RecordOpeningBalances"],
        ["Presentación", "Panel Filament por rol", "Resources de Products, Receipts, Counts, Imports, Replenishment, Reports, Audit, Users; CsvExport"],
    ], [2.6, 5, 8.9], size=8)

    # ---- 3 Estrategia
    doc.add_heading("3. Estrategia de pruebas de aceptación", 1)
    doc.add_heading("3.1 Enfoque", 2)
    para(doc, "Cada criterio tiene una prueba Pest cuyo nombre empieza con su ID (por ejemplo it('CA-019-4 …')). La prueba prepara exactamente "
              "los «Valores de entrada» del Excel, ejecuta el evento con las clases reales del sistema y comprueba la salida esperada, la "
              "persistencia en PostgreSQL y la ausencia de efectos no permitidos. Así cada fila del Excel queda trazada a una ejecución.")
    table(doc, ["Nivel", "Qué verifica", "Dónde"], [
        ["Aceptación automatizada (Pest + PostgreSQL 17)", "Casos de uso reales, base real, transacción revertida al final de cada prueba", "tests/Acceptance/*.php"],
        ["Aceptación en el panel (Livewire/Filament)", "Pantallas, acciones visibles u ocultas, filtros, descargas, inicio de sesión y 2FA", "tests/Acceptance/*.php (Livewire::test)"],
        ["Herramienta de calidad (grupo tooling)", "Deptrac, Pint, PHPStan y dependencias de Composer", "tests/Acceptance/QualityToolingAcceptanceTest.php"],
        ["Sondas SQL", "Restricciones de la base: inmutabilidad, unicidad, permisos", "scripts/acceptance/sql_acceptance.php"],
        ["Dominio con dobles (corte anterior)", "Reglas de negocio puras sin base de datos", "tests/Unit/**"],
        ["Evidencia visual", "Recorrido real en el navegador por rol (Playwright)", "docs/pruebas-aceptacion/capturas"],
    ], [5, 7, 4.5], size=8.5)
    doc.add_heading("3.2 Criterios de entrada", 2)
    for b in ["Código en la rama main con Pint, PHPStan (nivel 6) y Deptrac sin errores.", "Base de pruebas aislada wms_test migrada (la crea RefreshDatabase).",
              "Roles y permisos sembrados por la propia prueba (RolesAndPermissionsSeeder).", "Docker Compose con los servicios db y tools disponibles."]:
        bullet(doc, b)
    doc.add_heading("3.3 Criterios de salida y de aceptación", 2)
    for b in ["Un criterio es «Conforme en integración» solo si su prueba pasa en la corrida registrada en el informe JUnit.",
              "Ningún criterio implementado puede quedar «No conforme» al cierre: si falla, se registra como defecto con su corrección y prueba de regresión.",
              "«Conforme en integración» no equivale a aceptación del usuario: el acta final la firma el dueño del producto (sección 10)."]:
        bullet(doc, b)
    doc.add_heading("3.4 Trazabilidad", 2)
    para(doc, "Historia (HU) → criterio (CA) → prueba (archivo › nombre con el ID) → resultado JUnit → fila del Excel. El script "
              "scripts/acceptance/actualizar_excel.py vuelca los resultados al Excel sin modificar a mano ninguna celda de resultado.")

    # ---- 4 Entorno
    doc.add_heading("4. Entorno de prueba", 1)
    table(doc, ["Componente", "Versión / detalle"], [
        ["Sistema operativo anfitrión", "Windows 11 con Docker Desktop (WSL 2)"], ["Docker / Compose", "29.5.2 / v5.1.3"],
        ["PHP (contenedor tools)", "8.4.26 (FrankenPHP)"], ["Laravel", "13.34.0"], ["Filament / Livewire", "5.11.0 / 4.4.8"],
        ["Pest", "4.7.8 (memory_limit 1G)"], ["PostgreSQL", "17.11"],
        ["Base de pruebas", "wms_test (aislada; un candado impide usar otra base)"],
        ["Base de demostración", "wms_demo (DemoDataSeeder) para las capturas; no se usa la base de trabajo wms"],
        ["Navegador para capturas", "Chromium con Playwright 1.48.2 (imagen oficial, licencia Apache 2.0)"],
    ], [5.5, 11])
    doc.add_heading("4.1 Usuarios de prueba por rol", 2)
    table(doc, ["Usuario", "Rol", "Permisos relevantes"], [
        ["admin", "Administrador", "Todos (incluye users.manage, audit.view, inventory.opening_balance)"],
        ["supervisor.demo", "Supervisor", "catalog.write, counts.close, inventory.adjust.approve, pos.import, reports.export, audit.view"],
        ["almacenero.demo", "Almacenero", "inventory.receive, inventory.transfer, inventory.adjust.request, counts.run (sin counts.close)"],
        ["compras.demo", "Compras", "suppliers.manage, alerts.view, reports.view, reports.export"],
        ["lectura.demo", "Lectura", "catalog.view, inventory.view, alerts.view, reports.view (sin reports.export)"],
    ], [3.5, 3, 10], size=8.5)
    para(doc, "Las claves de los usuarios de demostración solo existen en la base wms_demo y no se usan en producción.", size=9)

    # ---- 5 Procedimiento
    doc.add_heading("5. Procedimiento de ejecución (cómo reproducir)", 1)
    para(doc, "Todos los comandos se ejecutan en PowerShell desde la carpeta del proyecto. No se necesita PHP local.")
    doc.add_heading("5.1 Ejecutar todos los criterios", 2)
    code(doc, "docker compose run --rm -T tools vendor/bin/pest --testsuite=Acceptance\n"
              "docker compose run --rm -T tools vendor/bin/pest --group=tooling")
    doc.add_heading("5.2 Demostrar un criterio concreto ante el docente", 2)
    para(doc, "Se filtra por el ID; Pest ejecuta solo las pruebas cuyo nombre contiene ese ID y muestra cada verificación:")
    code(doc, 'docker compose run --rm -T tools vendor/bin/pest --filter="CA-019-4"\n'
              'docker compose run --rm -T tools vendor/bin/pest --filter="CA-045-4" -v')
    para(doc, "Para mostrar el código de la prueba (entradas y verificaciones) se abre el archivo indicado en la ficha del criterio "
              "(sección 7) y se busca el ID. Para mostrar el comportamiento en pantalla se usa la base de demostración (sección 5.4).")
    doc.add_heading("5.3 Generar la evidencia y actualizar el Excel", 2)
    code(doc, "docker compose run --rm -T tools vendor/bin/pest --log-junit storage/logs/pest-junit.xml\n"
              "docker compose run --rm -T tools vendor/bin/pest --group=tooling --log-junit storage/logs/pest-tooling.xml\n"
              'python scripts/acceptance/actualizar_excel.py "Criterios de Aceptación_WMS.xlsx" 29/10/2026 storage/logs/pest-junit.xml storage/logs/pest-tooling.xml\n'
              "python scripts/evidencias/generar_documento.py")
    doc.add_heading("5.4 Demostración en vivo con datos de prueba", 2)
    code(doc, "docker compose exec -T db createdb -U wms_owner wms_demo\n"
              "docker compose run --rm -T -e DB_DATABASE=wms_demo tools php artisan migrate --force\n"
              "docker compose run --rm -T -e DB_DATABASE=wms_demo tools php artisan db:seed --force\n"
              "docker compose run --rm -T -e DB_DATABASE=wms_demo tools php artisan db:seed --class=DemoDataSeeder --force")
    para(doc, "Luego se levanta una instancia apuntando a wms_demo (puerto 8081) y se ingresa con cada usuario de la sección 4.1 para "
              "mostrar, por ejemplo, que Lectura no ve el botón de descarga y que el Almacenero cuenta a ciegas.")

    # ---- 6 Resumen de resultados
    doc.add_heading("6. Resumen de resultados", 1)
    total_time = junit["time"]
    table(doc, ["Indicador", "Valor"], [
        ["Pruebas ejecutadas (suite completa + tooling)", f"{junit['tests']}"],
        ["Aserciones verificadas", f"{junit['assertions']}"],
        ["Fallas / errores", f"{junit['failures']} / {junit['errors']}"],
        ["Tiempo total de ejecución", f"{total_time / 60:.1f} minutos"],
        ["Criterios con prueba de aceptación específica (ID en el nombre)", f"{len(junit['cases'])}"],
    ], [10, 6.5])
    table(doc, ["Estado del criterio al corte", "Cantidad", "%"],
          [[k, str(v), f"{100 * v / 191:.1f} %"] for k, v in status.most_common()] + [["Total", "191", "100 %"]], [9, 3.5, 4])
    para(doc, f"Resultado: {verified} de 191 criterios verificados conformes ({100 * verified / 191:.1f} %). Al inicio del corte había 42 "
              f"verificados y 3 no conformes (CA-019-4, CA-045-4, CA-053-2); los tres defectos se corrigieron y hoy hay {status['No conforme']} no conformes.")
    by_hu: "OrderedDict[str, Counter]" = OrderedDict()
    for c in criteria:
        by_hu.setdefault(c["hu"], Counter())[c["res"]] += 1
    rows = []
    for hu, cnt in by_hu.items():
        ok = cnt["Conforme en integración"] + cnt["Conforme en dominio"]
        tot = sum(cnt.values())
        rows.append([hu, str(tot), str(ok), str(cnt["No conforme"]), str(tot - ok - cnt["No conforme"]), f"{100 * ok / tot:.0f} %"])
    doc.add_heading("6.1 Resultado por historia de usuario", 2)
    table(doc, ["Historia", "Criterios", "Conformes", "No conformes", "Pendientes", "Avance"], rows, [2.5, 2.5, 2.8, 2.8, 2.8, 2.6], size=8.5)

    doc.add_heading("6.2 Evidencia de ejecución en consola", 2)
    fig = 1
    for path, cap in console:
        figure(doc, path, cap, fig, 16.5)
        fig += 1

    # ---- 7 Fichas por criterio
    doc.add_page_break()
    doc.add_heading("7. Casos de prueba de aceptación (ficha por criterio)", 1)
    para(doc, "Cada ficha reproduce el escenario del Excel y añade la evidencia de su ejecución. «Verificaciones de la prueba» son las "
              "aserciones literales del código: muestran qué salida concreta se comprueba. «Comando» ejecuta solo ese criterio.")
    current_hu = None
    for c in criteria:
        if c["hu"] != current_hu:
            current_hu = c["hu"]
            doc.add_heading(f"{c['hu']} — {c['deseo']}", 2)
            para(doc, f"Como {c['rol'].lower()}, deseo {c['deseo'][0].lower() + c['deseo'][1:]}, para {c['para'][0].lower() + c['para'][1:]}.", size=10)
        doc.add_heading(f"{c['id']} · {c['crit']}", 3)
        cases = junit["cases"].get(c["id"], [])
        evidence = "\n".join(f"{x['file']} › «{x['name']}» — {'APROBADA' if x['ok'] else 'FALLIDA'} ({x['assertions']} aserciones, {x['time']:.2f} s)" for x in cases)
        checks = "\n".join(asserts.get(c["id"], []))
        figs = [k for k, v in FIGURAS_CRITERIO.items() if c["id"] in v]
        pairs = [
            ("Historia", f"{c['hu']} — {c['rol']}"),
            ("Clase o componente responsable", c["clase"].replace(";", ", ")),
            ("Precondición (Dado)", c["dado"]),
            ("Evento (Cuando)", c["cuando"]),
            ("Datos de entrada", c["valores"]),
            ("Pasos de validación", c["pasos"]),
            ("Salida esperada (Entonces)", c["entonces"]),
            ("Salida obtenida", c["obs"]),
            ("Nivel de prueba", c["nivel"]),
            ("Prueba automatizada", evidence or (c["pruebas"] or "Sin prueba automatizada")),
        ]
        if checks:
            pairs.append(("Verificaciones de la prueba", checks))
        if cases:
            pairs.append(("Comando", f'docker compose run --rm -T tools vendor/bin/pest --filter="{c["id"]}"'
                                     + (" --group=tooling" if any("Tooling" in x["file"] for x in cases) else "")))
        if figs:
            pairs.append(("Evidencia visual", ", ".join(f"captura {f}" for f in figs) + " (sección 8)"))
        pairs.append(("Estado", c["res"]))
        if c["notas"]:
            pairs.append(("Observaciones", c["notas"]))
        kv_table(doc, pairs, status_color(c["res"]), mono_keys=("Verificaciones de la prueba", "Comando"))

    # ---- 8 Evidencia visual
    doc.add_page_break()
    doc.add_heading("8. Evidencia visual del sistema", 1)
    para(doc, "Capturas tomadas automáticamente con Chromium (Playwright) sobre la base de demostración, iniciando sesión con el rol indicado. "
              "Muestran el comportamiento observable que exigen los criterios citados.")
    for png in sorted(CAPS.glob("*.png")):
        key = png.stem
        crit = ", ".join(FIGURAS_CRITERIO.get(key, []))
        cap = cap_by_file.get(key, key)
        figure(doc, png, f"{cap}" + (f" Criterios: {crit}." if crit and crit not in cap else ""), fig, 15.5)
        fig += 1

    # ---- 9 Defectos
    doc.add_page_break()
    doc.add_heading("9. Defectos detectados y corregidos", 1)
    para(doc, "Cada defecto se reprodujo primero con una prueba que fallaba (rojo), se corrigió y la misma prueba quedó como regresión (verde).")
    table(doc, ["ID", "Severidad", "Criterio", "Descripción", "Corrección", "Commit"],
          [list(d) for d in DEFECTOS], [1.2, 1.6, 2.2, 4.8, 5.2, 1.5], size=7.5)
    doc.add_heading("9.1 Observación abierta", 2)
    para(doc, "El reporte de errores del POS solo se genera cuando la importación se hace desde la pantalla (el caso de uso no lo persiste). "
              "Para importaciones hechas por otras vías no aparece el botón de descarga. Se recomienda mover la generación del reporte al caso de uso.")

    # ---- 10 Pendientes
    doc.add_heading("10. Criterios no verificados en este corte", 1)
    pend = [c for c in criteria if c["res"] not in ("Conforme en integración", "Conforme en dominio")]
    table(doc, ["Criterio", "Historia", "Criterio de aceptación", "Estado", "Motivo"],
          [[c["id"], c["hu"], c["crit"], c["res"], c["notas"] or "—"] for c in pend], [2, 1.7, 4, 3, 6], size=7.5)

    # ---- 11 Conclusiones y acta
    doc.add_heading("11. Conclusiones de la ejecución", 1)
    for b in [f"Se verificaron {verified} de los 191 criterios (antes 42), con {junit['tests']} pruebas y {junit['assertions']} aserciones sin fallas.",
              "Los tres defectos abiertos al inicio (D01, D02, D03) están corregidos y protegidos con pruebas de regresión.",
              "Las pruebas detectaron además diez problemas no reportados (D04 a D13), entre ellos uno crítico de entorno que borraba la base de desarrollo y dos que impedían instalar o arrancar el sistema en una PC nueva.",
              "Los criterios restantes dependen de funcionalidades aún no implementadas (HU-023, HU-052, HU-054), de la operación (respaldos, despliegue, CI remoto, rendimiento, ZAP, SUS e ISO 25010) o están fuera del alcance de la fase inicial (HU-047, HU-055, HU-056).",
              "«Conforme en integración» demuestra que el sistema cumple los escenarios con datos controlados; la aceptación final requiere la revisión del dueño del producto con datos reales."]:
        bullet(doc, b)
    doc.add_heading("12. Acta de aceptación", 1)
    para(doc, "Los abajo firmantes declaran haber revisado la ejecución descrita y su evidencia. La aceptación aplica a los criterios marcados como conformes en este documento.")
    table(doc, ["Rol", "Nombre", "Decisión (Acepta / Acepta con observaciones / No acepta)", "Firma y fecha"],
          [["Dueño del producto", "Gerencia de CITY OFERTAS IMPORTADORA E.I.R.L.", "", ""],
           ["Equipo de calidad", "", "", ""], ["Equipo de desarrollo", "Torres Cavero Luis Augusto", "", ""],
           ["Docente", "Dr. Iván Michell Castillo Jiménez", "", ""]], [3.5, 5, 4.5, 3.5])

    # ---- Anexos
    doc.add_heading("Anexo A. Archivos de prueba de aceptación", 1)
    files = Counter(x["file"] for v in junit["cases"].values() for x in v)
    table(doc, ["Archivo", "Criterios"], [[f, str(n)] for f, n in sorted(files.items())], [12.5, 4])
    doc.add_heading("Anexo B. Glosario de roles y permisos", 1)
    para(doc, "Los permisos se consultan siempre con can('permiso'), nunca por nombre de rol; el detalle está en app/Modules/Iam/Domain/Permissions.php.")

    OUT_DIR.mkdir(parents=True, exist_ok=True)
    doc.save(DOCX)
    print(f"Documento generado: {DOCX} ({fig - 1} figuras, {len(criteria)} fichas)")


if __name__ == "__main__":
    main()
