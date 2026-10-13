"""Tighter guest detection, built from how the real guest episodes actually read."""
import re

# A name being handed the floor, or thanked for being there, is the signal that
# survived inspection. "para adelante" is an idiom and must not count.
SIGNALS = [
    (re.compile(r"\bgracias por (estar|acompañarnos|venir)", re.I), 6, "agradece la presencia"),
    (re.compile(r"\b[A-ZÁÉÍÓÚÑ][a-záéíóúñ]{2,},\s*adelante\b"), 6, "cede la palabra a un nombre"),
    (re.compile(r"\bnos acompaña\b|\bmi invitad[oa]\b|\bnuestro invitad[oa]\b", re.I), 6, "presenta invitado"),
    (re.compile(r"\b(profesor|doctor|doctora|licenciado|licenciada)\s*,", re.I), 4, "vocativo profesional"),
    (re.compile(r"\b(néstor|duprey|josé efraín|efraín hernández)\b", re.I), 4, "panelista conocido"),
    (re.compile(r"\bbienvenid[oa]s?\s+(a|al)?\s*(la|el)?\s*\w*\s*(programa|espacio|cátedra)", re.I), 3, "da la bienvenida"),
    (re.compile(r"\b(déjame preguntarte|te pregunto a ti|¿qué opinas|cómo lo ves tú)\b", re.I), 3, "pregunta dirigida"),
]
THRESHOLD = 6

def score(text, title=""):
    hits, total = [], 0
    for rx, weight, label in SIGNALS:
        n = len(rx.findall(text))
        if n:
            total += weight * min(n, 3)
            hits.append(f"{label} x{n}")
    if "|" in title and "cátedra" in title.lower():
        total += 4
        hits.append("formato de panel en el título")
    return total, hits

def has_guest(text, title=""):
    return score(text, title)[0] >= THRESHOLD
