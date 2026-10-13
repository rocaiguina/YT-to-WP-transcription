"""Checks that code can settle, without spending anything.

Everything here is deterministic. What needs judgement - whether it sounds
like him, whether an allegation stayed an allegation - is the judge's job in
run_eval.py. Keeping the two apart matters: a number that appears in the
article but not in the transcript is a fact, not an opinion, and a grader
that can prove it should not be asking a model.
"""

import re
import unicodedata

# Words common enough in one language and rare in the other to separate them
# on a paragraph of text.
ES_MARKERS = {"que", "de", "la", "el", "en", "los", "las", "por", "con", "para",
              "una", "del", "se", "no", "es", "al", "lo", "más", "pero", "esto"}
EN_MARKERS = {"the", "of", "and", "to", "in", "that", "for", "with", "is",
              "was", "on", "this", "which", "as", "by", "from", "they", "said"}

# Phrases that give away a transcript wearing an article's clothes.
FRAMING = [
    (re.compile(r"\b(en|durante)\s+(este|el)\s+(episodio|v[ií]deo|programa|podcast)\b", re.I), "refiere al episodio"),
    (re.compile(r"\bcomo (dije|mencion[eé]|expliqu[eé]|coment[eé]) (antes|al principio|arriba)\b", re.I), "se refiere a lo que dijo antes"),
    (re.compile(r"\b(la )?transcripci[oó]n\b", re.I), "menciona la transcripción"),
    (re.compile(r"\b(el|este) (anfitri[oó]n|presentador|locutor)\b", re.I), "habla del presentador en tercera persona"),
    (re.compile(r"\b(minuto|min\.)\s*\d{1,3}\b", re.I), "cita un minuto del video"),
    # A video timestamp, not a clock time. "a las 11:30 de la mañana" is a
    # fact from the news and must not count, so a time introduced by "las"
    # or followed by a time-of-day marker is left alone; what gets flagged
    # is a bare or bracketed offset, which is how a transcript marks itself.
    (re.compile(
        r"(?<!\d)(?<!las )(?<!las\s)\(?\[?\b\d{1,3}:\d{2}\b\]?\)?"
        r"(?!\s*(?:de la (?:mañana|tarde|noche)|a\.?\s*m|p\.?\s*m|horas|hrs))(?!\d)",
        re.I,
    ), "marca de tiempo"),
    (re.compile(r"\b(al inicio|m[aá]s adelante) (del|en el) (v[ií]deo|episodio|programa)\b", re.I), "ubica algo dentro del video"),
]

# Digits that are part of a word (a law number, a brand) rather than a figure.
NUM = re.compile(r"\d[\d.,]*")


def article_text(draft):
    """Every piece of prose the article publishes, as one string."""
    parts = [draft.get("title", "")]
    parts += draft.get("lede", []) or []
    parts += draft.get("key_points", []) or []
    for section in draft.get("sections", []) or []:
        parts.append(section.get("heading", ""))
        parts += section.get("paragraphs", []) or []
    return "\n".join(p for p in parts if p)


def _words(text):
    return re.findall(r"[a-záéíóúüñ]+", text.lower())


def check_language(draft, expect="es"):
    """Which language the article came out in."""
    words = _words(article_text(draft))
    if not words:
        return False, "el artículo vino vacío"
    es = sum(1 for w in words if w in ES_MARKERS)
    en = sum(1 for w in words if w in EN_MARKERS)
    got = "es" if es >= en else "en"
    if got == expect:
        return True, ""
    return False, f"parece {got}, no {expect} ({es} marcadores españoles contra {en} ingleses)"


def check_no_framing(draft):
    """Nothing that reveals the article began as speech."""
    text = article_text(draft)
    found = []
    for rx, label in FRAMING:
        m = rx.search(text)
        if m:
            found.append(f"{label} ({m.group(0).strip()!r})")
    return (not found), "; ".join(found)


def _normalize_number(raw):
    """'1.6' and '1,6' are the same figure; '7,214' and '7214' too."""
    digits = re.sub(r"[.,](?=\d{3}\b)", "", raw)      # thousands separators
    digits = digits.replace(",", ".")                  # decimal comma
    digits = digits.rstrip(".")
    try:
        value = float(digits)
    except ValueError:
        return None
    return round(value, 4)


def check_numbers_grounded(draft, transcript_text):
    """Every figure in the article has to come from the transcript.

    The strongest objective signal available: an invented figure is the one
    failure that reads as authoritative and is wrong, and it needs no
    judgement to catch.
    """
    source = {_normalize_number(m) for m in NUM.findall(transcript_text)}
    source.discard(None)

    invented = []
    for raw in NUM.findall(article_text(draft)):
        value = _normalize_number(raw)
        if value is None:
            continue
        # Small integers are ordinals and counts the writer can legitimately
        # reach for ("las tres agencias"), so they are not evidence either way.
        if value in source or (float(value).is_integer() and value <= 20):
            continue
        invented.append(raw)

    unique = sorted(set(invented))
    return (not unique), ("cifras que no están en la transcripción: " + ", ".join(unique[:8]) if unique else "")


def check_structure(draft):
    """The shape an article has to have to be publishable."""
    problems = []

    title = (draft.get("title") or "").strip()
    if not title:
        problems.append("sin titular")
    elif len(title) > 110:
        problems.append(f"titular de {len(title)} caracteres")
    elif title.isupper():
        problems.append("titular en mayúsculas sostenidas")

    lede = [p for p in (draft.get("lede") or []) if p.strip()]
    if not lede:
        problems.append("sin entrada")
    else:
        n = len(" ".join(lede).split())
        if n < 60:
            problems.append(f"entrada de {n} palabras")

    points = [p for p in (draft.get("key_points") or []) if p.strip()]
    if not 3 <= len(points) <= 8:
        problems.append(f"{len(points)} puntos clave")

    sections = draft.get("sections") or []
    if not sections:
        problems.append("sin cuerpo")
    for i, s in enumerate(sections, 1):
        if not (s.get("heading") or "").strip():
            problems.append(f"sección {i} sin titular")
        if not [p for p in (s.get("paragraphs") or []) if p.strip()]:
            problems.append(f"sección {i} sin texto")

    return (not problems), "; ".join(problems)


def _fold(text):
    return "".join(c for c in unicodedata.normalize("NFKD", text.lower()) if not unicodedata.combining(c))


def check_sources_grounded(draft, description):
    """Outlets have to come from the video description, not from memory."""
    sources = draft.get("sources") or []
    if not sources:
        return True, ""           # none claimed is not a failure
    if not (description or "").strip():
        return True, ""           # nothing to check against

    haystack = _fold(description)
    missing = []
    for s in sources:
        outlet = (s.get("outlet") or "").strip()
        if not outlet:
            continue
        if _fold(outlet) not in haystack:
            missing.append(outlet)

    unique = sorted(set(missing))
    return (not unique), ("medios que no aparecen en la descripción: " + ", ".join(unique[:6]) if unique else "")


def run_all(draft, case, language="es"):
    """Every deterministic check, as {name: (passed, detail)}."""
    transcript = " ".join(s["text"] for s in case["segments"])
    return {
        "idioma":      check_language(draft, language),
        "sin_marcas":  check_no_framing(draft),
        "cifras":      check_numbers_grounded(draft, transcript),
        "estructura":  check_structure(draft),
        "fuentes":     check_sources_grounded(draft, case.get("description", "")),
    }
