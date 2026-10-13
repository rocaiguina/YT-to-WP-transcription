import json, glob, os

cases = [json.load(open(f)) for f in glob.glob("cases/*.json") if not f.endswith("index.json")]
cases.sort(key=lambda c: c["duration_seconds"])

def mins(s): return f"{s//60}:{s%60:02d}"
def fence(t):
    n = 3
    while "`" * n in t: n += 1
    return "`" * n

out = ["# Casos del eval\n",
       "25 episodios reales del canal, repartidos por duración. Cada uno trae su transcripción completa en `cases/<video_id>.json`; aquí va el arranque de cada uno para que juzgues si son representativos.\n"]

out.append("| # | Fecha | Duración | Palabras | Tipo | Título |")
out.append("|---|---|---|---|---|---|")
for i, c in enumerate(cases, 1):
    out.append(f"| {i} | {c['published_at'][:10]} | {mins(c['duration_seconds'])} | {c['transcript_words']:,} | {c['bucket']} | {c['title'][:58]} |")

out.append("\n---\n")
for i, c in enumerate(cases, 1):
    text = " ".join(s["text"] for s in c["segments"])
    head = " ".join(text.split()[:110])
    mid = " ".join(text.split()[len(text.split())//2:][:70])
    f = fence(head + mid)
    out.append(f"## {i}. {c['title']}\n")
    out.append(f"`{c['video_id']}` · {c['published_at'][:10]} · {mins(c['duration_seconds'])} · {c['transcript_words']:,} palabras · {c['views']:,} vistas\n")
    out.append("Arranque:\n")
    out.append(f"{f}\n{head}…\n{f}\n")
    out.append("Mitad:\n")
    out.append(f"{f}\n…{mid}…\n{f}\n")

open("CASES.md", "w").write("\n".join(out))

tot = sum(c["transcript_words"] for c in cases)
inp = tot * 1.4 + len(cases) * 1500
outp = len(cases) * 4000
app = inp/1e6*1 + outp/1e6*5
judge = (inp+outp)/1e6*4 + len(cases)*1000/1e6*20
print(f"escrito CASES.md con {len(cases)} casos")
print(f"palabras: {tot:,}")
print()
print(f"costo estimado por corrida completa:")
print(f"  app (claude-haiku-4-5)  ${app:.2f}")
print(f"  juez (claude-opus-5-5)  ${judge:.2f}")
print(f"  total                   ${app+judge:.2f}")
