"""Rebuild the case set with episodes where only Jay speaks."""
import json, glob, os, random, sys
sys.path.insert(0, ".")
from detect_guests import score, THRESHOLD
from youtube_transcript_api import YouTubeTranscriptApi

random.seed(11)
# Known false negative from manual inspection: clear back-and-forth with a guest.
MANUAL_GUESTS = {"Un Plan pa"}

def is_guest(text, title):
    if any(m in title for m in MANUAL_GUESTS):
        return True
    return score(text, title)[0] >= THRESHOLD

keep = []
for f in glob.glob("cases/*.json"):
    if "index" in f: continue
    c = json.load(open(f))
    text = " ".join(s["text"] for s in c["segments"])
    if is_guest(text, c["title"]):
        os.remove(f)
    else:
        keep.append(c)
print(f"conservados del set anterior: {len(keep)}")

have = {c["video_id"] for c in keep}
pool = [p for p in json.load(open("pool.json"))
        if 180 <= p["duration_seconds"] <= 7200 and p["video_id"] not in have]
# The daily podcast is reliably one voice, so it anchors the backfill.
pool.sort(key=lambda p: (0 if "NOTICIAS CON CALLE" in p["title"].upper() else 1, random.random()))

api = YouTubeTranscriptApi()
added = 0
for p in pool:
    if len(keep) >= 25: break
    try:
        tr = api.fetch(p["video_id"], languages=["es"])
    except Exception:
        continue
    segs = [{"start": round(s.start, 2), "text": s.text} for s in tr]
    words = sum(len(s["text"].split()) for s in segs)
    if words < 400: continue
    text = " ".join(s["text"] for s in segs)
    if is_guest(text, p["title"]): continue
    secs = p["duration_seconds"]
    bucket = "corto" if secs < 900 else "diario" if secs < 1800 else "medio" if secs < 3600 else "largo"
    c = dict(p, bucket=bucket, segments=segs, transcript_words=words)
    json.dump(c, open(f"cases/{p['video_id']}.json", "w"), ensure_ascii=False)
    keep.append(c); added += 1

print(f"añadidos: {added}   total: {len(keep)}")
keep.sort(key=lambda c: c["duration_seconds"])
idx = [{k: c[k] for k in ("video_id","title","published_at","duration_seconds","bucket","transcript_words","views")} for c in keep]
json.dump(idx, open("cases/index.json","w"), ensure_ascii=False, indent=1)
from collections import Counter
print(dict(Counter(c["bucket"] for c in keep)))
print(f"palabras totales: {sum(c['transcript_words'] for c in keep):,}")
