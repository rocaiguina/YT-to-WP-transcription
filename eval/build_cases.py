"""Pick a stratified sample and pull each episode's captions."""
import json, random, os
from youtube_transcript_api import YouTubeTranscriptApi

random.seed(7)
pool = [p for p in json.load(open("pool.json")) if 180 <= p["duration_seconds"] <= 7200]

BUCKETS = [("corto", 180, 900, 3), ("diario", 900, 1800, 10),
           ("medio", 1800, 3600, 7), ("largo", 3600, 7200, 5)]

picked = []
for name, lo, hi, want in BUCKETS:
    group = [p for p in pool if lo <= p["duration_seconds"] < hi]
    random.shuffle(group)
    for p in group:
        if len([x for x in picked if x["bucket"] == name]) >= want:
            break
        p = dict(p, bucket=name)
        picked.append(p)

api = YouTubeTranscriptApi()
kept, dropped = [], []
for p in picked:
    try:
        tr = api.fetch(p["video_id"], languages=["es"])
        segs = [{"start": round(s.start, 2), "text": s.text} for s in tr]
        words = sum(len(s["text"].split()) for s in segs)
        if words < 400:
            dropped.append((p["video_id"], f"solo {words} palabras"))
            continue
        case = dict(p, segments=segs, transcript_words=words)
        json.dump(case, open(f"cases/{p['video_id']}.json", "w"), ensure_ascii=False)
        kept.append(case)
    except Exception as e:
        dropped.append((p["video_id"], type(e).__name__))

index = [{k: c[k] for k in ("video_id","title","published_at","duration_seconds","bucket","transcript_words","views")} for c in kept]
index.sort(key=lambda c: c["duration_seconds"])
json.dump(index, open("cases/index.json", "w"), ensure_ascii=False, indent=1)

print(f"casos con transcripción: {len(kept)}   descartados: {len(dropped)}")
for vid, why in dropped: print(f"   descartado {vid}: {why}")
print()
tot = sum(c["transcript_words"] for c in kept)
print(f"palabras totales: {tot:,}  |  mediana: {sorted(c['transcript_words'] for c in kept)[len(kept)//2]:,}")
