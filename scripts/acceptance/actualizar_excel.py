"""
Actualiza "Criterios de Aceptación_WMS.xlsx" con la evidencia real de las pruebas de aceptación.

Edita solo el XML de las celdas (no reescribe el libro), así se conservan gráficos, formatos,
validaciones y fórmulas. Es reejecutable: cada corrida parte de los resultados JUnit de Pest.

Uso (desde la raíz del repositorio, con Python 3.10+ y lxml):
    docker compose run --rm -T tools vendor/bin/pest --log-junit storage/logs/pest-junit.xml
    docker compose run --rm -T tools vendor/bin/pest --group=tooling --log-junit storage/logs/pest-tooling.xml
    python scripts/acceptance/actualizar_excel.py "Criterios de Aceptación_WMS.xlsx" 29/10/2026 \
        storage/logs/pest-junit.xml storage/logs/pest-tooling.xml
"""

from __future__ import annotations

import json
import os
import re
import shutil
import sys
import tempfile
import zipfile
from pathlib import Path

from lxml import etree

NS = {"m": "http://schemas.openxmlformats.org/spreadsheetml/2006/main",
      "r": "http://schemas.openxmlformats.org/officeDocument/2006/relationships"}
M = "{%s}" % NS["m"]
HERE = Path(__file__).resolve().parent
SHEET = "Criterios de Aceptación"
FIRST_ROW, LAST_ROW = 6, 196
COL = {"hu": "B", "id": "G", "dado": "I", "cuando": "J", "entonces": "K", "valores": "L", "pasos": "M",
       "pruebas": "O", "nivel": "P", "resultado": "Q", "observado": "R", "notas": "S"}
GENERIC_DADO = "Dado un entorno de pruebas aislado"
GENERIC_CUANDO = "Cuando ejecuta la operación de la historia"
GENERIC_PASOS = "1. Preparar fixture descrita"
REPEATED_NOTE = "Implementación y pruebas relacionadas identificadas; aceptación integral del escenario debe verificarse por separado."
CA_RE = re.compile(r"\b(CA-\d{3}-\d)\b")


def load_tests(junit_files: list[Path]) -> dict[str, dict]:
    """CA-xxx-y -> {status, name, file, message} a partir de los informes JUnit de Pest."""
    tests: dict[str, dict] = {}
    for path in junit_files:
        if not path.exists():
            continue
        for case in etree.parse(str(path)).iter("testcase"):
            name = case.get("name", "")
            match = CA_RE.search(name)
            if not match:
                continue
            failure = case.find("failure")
            if failure is None:
                failure = case.find("error")
            skipped = case.find("skipped") is not None
            file = (case.get("file") or "").split("::")[0].replace("/app/", "")
            file = file[file.find("tests/"):] if "tests/" in file else file
            status = "skipped" if skipped else ("failed" if failure is not None else "passed")
            message = ""
            if failure is not None:
                message = (failure.get("message") or failure.text or "").strip().splitlines()[0][:300]
            clean = CA_RE.sub("", name).replace("it ", "", 1).strip(" :-")
            tests[match.group(1)] = {"status": status, "name": clean, "file": file, "message": message}
    return tests


def sheet_paths(z: zipfile.ZipFile) -> dict[str, str]:
    wb = etree.fromstring(z.read("xl/workbook.xml"))
    rels = etree.fromstring(z.read("xl/_rels/workbook.xml.rels"))
    targets = {r.get("Id"): r.get("Target") for r in rels}
    out = {}
    for s in wb.find("m:sheets", NS):
        target = targets[s.get("{%s}id" % NS["r"])]
        out[s.get("name")] = "xl/" + target.lstrip("/").removeprefix("xl/")
    return out


class Sheet:
    def __init__(self, xml: bytes, shared: list[str]):
        self.root = etree.fromstring(xml)
        self.shared = shared
        self.rows = {int(r.get("r")): r for r in self.root.find("m:sheetData", NS)}

    def cell(self, ref: str, create: bool = True):
        row_num = int(re.sub(r"\D", "", ref))
        row = self.rows.get(row_num)
        if row is None:
            return None
        for c in row:
            if c.get("r") == ref:
                return c
        if not create:
            return None
        c = etree.SubElement(row, M + "c", r=ref)
        cells = sorted(row, key=lambda e: col_index(re.sub(r"\d", "", e.get("r"))))
        for e in cells:
            row.append(e)
        return c

    def get(self, ref: str) -> str:
        c = self.cell(ref, create=False)
        if c is None:
            return ""
        t = c.get("t")
        if t == "s":
            v = c.find("m:v", NS)
            return self.shared[int(v.text)] if v is not None else ""
        if t == "inlineStr":
            return "".join(c.find("m:is", NS).itertext())
        v = c.find("m:v", NS)
        return v.text if v is not None and v.text else ""

    def set(self, ref: str, text: str) -> None:
        c = self.cell(ref)
        for child in list(c):
            c.remove(child)
        c.set("t", "inlineStr")
        is_ = etree.SubElement(c, M + "is")
        t = etree.SubElement(is_, M + "t")
        t.text = text
        t.set("{http://www.w3.org/XML/1998/namespace}space", "preserve")

    def xml(self) -> bytes:
        return etree.tostring(self.root, xml_declaration=True, encoding="UTF-8", standalone=True)


def col_index(letters: str) -> int:
    n = 0
    for ch in letters:
        n = n * 26 + (ord(ch) - 64)
    return n


def shared_strings(z: zipfile.ZipFile) -> list[str]:
    if "xl/sharedStrings.xml" not in z.namelist():
        return []
    root = etree.fromstring(z.read("xl/sharedStrings.xml"))
    return ["".join(si.itertext()) for si in root.findall("m:si", NS)]


def level_for(test: dict) -> str:
    if "QualityTooling" in test["file"]:
        return "Herramienta de calidad (Pest, grupo tooling)"
    return "Aceptación automatizada (Pest + PostgreSQL 17)"


def build_steps(dado: str, cuando: str, entonces: str, test: dict | None) -> str:
    strip = lambda s, w: re.sub(rf"^{w}\s+", "", s.strip()).rstrip(".")
    steps = (f"1. Preparar: {strip(dado, 'Dado')}. 2. Ejecutar: {strip(cuando, 'Cuando')}. "
             f"3. Verificar: {entonces.strip().rstrip('.')}.")
    if test:
        steps += f" 4. Evidencia: {test['file']} › «{test['name']}»."
    else:
        steps += " 4. Sin prueba automatizada todavía (ver observaciones)."
    return steps


def main() -> None:
    xlsx = Path(sys.argv[1])
    cut_date = sys.argv[2]
    tests = load_tests([Path(p) for p in sys.argv[3:]])
    scenarios = json.loads((HERE / "escenarios.json").read_text(encoding="utf-8"))
    notes = json.loads((HERE / "notas.json").read_text(encoding="utf-8"))

    with zipfile.ZipFile(xlsx) as z:
        paths = sheet_paths(z)
        shared = shared_strings(z)
        sheet = Sheet(z.read(paths[SHEET]), shared)
        workbook_xml = etree.fromstring(z.read("xl/workbook.xml"))

    counts = {"passed": 0, "failed": 0, "untested": 0}
    for r in range(FIRST_ROW, LAST_ROW + 1):
        ca = sheet.get(f"{COL['id']}{r}").strip()
        if not CA_RE.fullmatch(ca):
            continue
        test = tests.get(ca)
        dado, cuando = sheet.get(f"{COL['dado']}{r}"), sheet.get(f"{COL['cuando']}{r}")
        if ca in scenarios and (dado.startswith(GENERIC_DADO) or cuando.startswith(GENERIC_CUANDO)):
            dado, cuando = scenarios[ca]
            sheet.set(f"{COL['dado']}{r}", dado)
            sheet.set(f"{COL['cuando']}{r}", cuando)
        if sheet.get(f"{COL['pasos']}{r}").startswith(GENERIC_PASOS) or test:
            sheet.set(f"{COL['pasos']}{r}", build_steps(dado, cuando, sheet.get(f"{COL['entonces']}{r}"), test))

        related = sheet.get(f"{COL['pruebas']}{r}")
        if "audit_work/sql_acceptance.php.txt" in related:
            parts = []
            for part in related.replace("audit_work/sql_acceptance.php.txt", "scripts/acceptance/sql_acceptance.php").split("; "):
                if part and part not in parts:
                    parts.append(part)
            sheet.set(f"{COL['pruebas']}{r}", "; ".join(parts))

        note = notes.get(ca, "")
        current_note = sheet.get(f"{COL['notas']}{r}")
        if test and test["status"] == "passed":
            counts["passed"] += 1
            sheet.set(f"{COL['nivel']}{r}", level_for(test))
            sheet.set(f"{COL['resultado']}{r}", "Conforme en integración")
            sheet.set(f"{COL['observado']}{r}", f"{test['name']}. Prueba aprobada el {cut_date}.")
            related = sheet.get(f"{COL['pruebas']}{r}")
            if test["file"] and test["file"] not in related:
                sheet.set(f"{COL['pruebas']}{r}", f"{test['file']}; {related}".strip("; "))
            sheet.set(f"{COL['notas']}{r}", note)
        elif test and test["status"] == "failed":
            counts["failed"] += 1
            sheet.set(f"{COL['nivel']}{r}", level_for(test))
            sheet.set(f"{COL['resultado']}{r}", "No conforme")
            sheet.set(f"{COL['observado']}{r}", f"Falla: {test['message'] or test['name']}")
            sheet.set(f"{COL['notas']}{r}", note or "Defecto abierto: la prueba automatizada falla.")
        else:
            counts["untested"] += 1
            if note:
                sheet.set(f"{COL['notas']}{r}", note)
            elif current_note.strip() == REPEATED_NOTE:
                sheet.set(f"{COL['notas']}{r}", "")

    status = {}
    for r in range(FIRST_ROW, LAST_ROW + 1):
        if CA_RE.fullmatch(sheet.get(f"{COL['id']}{r}").strip()):
            s = sheet.get(f"{COL['resultado']}{r}")
            status[s] = status.get(s, 0) + 1
    ok = status.get("Conforme en integración", 0) + status.get("Conforme en dominio", 0) + status.get("Aceptado por usuario", 0)
    sheet.set("B2", (f"Corte {cut_date}. 63 historias y 191 escenarios: {ok} verificados conformes "
                     f"({status.get('Conforme en integración', 0)} en integración con PostgreSQL, "
                     f"{status.get('Conforme en dominio', 0)} en dominio), {status.get('No conforme', 0)} no conformes y "
                     f"{191 - ok - status.get('No conforme', 0)} sin verificar. Conforme en integración no equivale a aceptación del usuario."))

    calc = workbook_xml.find("m:calcPr", NS)
    if calc is None:
        calc = etree.SubElement(workbook_xml, M + "calcPr")
    calc.set("fullCalcOnLoad", "1")  # las hojas Backlog y Sprints recalculan sus fórmulas al abrir

    fd, tmp_name = tempfile.mkstemp(suffix=".xlsx")
    os.close(fd)  # en Windows el descriptor abierto impide mover el archivo
    tmp = Path(tmp_name)
    with zipfile.ZipFile(xlsx) as zin, zipfile.ZipFile(tmp, "w", zipfile.ZIP_DEFLATED) as zout:
        for item in zin.infolist():
            if item.filename == paths[SHEET]:
                zout.writestr(item, sheet.xml())
            elif item.filename == "xl/workbook.xml":
                zout.writestr(item, etree.tostring(workbook_xml, xml_declaration=True, encoding="UTF-8", standalone=True))
            else:
                zout.writestr(item, zin.read(item.filename))
    shutil.move(str(tmp), xlsx)
    print(json.dumps({"filas": counts, "estado_al_corte": status, "verificados": ok}, ensure_ascii=False))


if __name__ == "__main__":
    main()
