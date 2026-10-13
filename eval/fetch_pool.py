"""Collect candidate episodes: metadata now, transcripts in the next pass."""
import json, re, urllib.request, urllib.parse

KEY = "os.environ.get("YOUTUBE_API_KEY", "")"
CHANNEL = "UC5KgvCdS0BP4dYGkGJ06hiQ"
UPLOADS = "UU" + CHANNEL[2:]

def api(path, **params):
    params["key"] = KEY
    url = f"https://www.googleapis.com/youtube/v3/{path}?" + urllib.parse.urlencode(params)
    req = urllib.request.Request(url, headers={"Referer": "https://jayfonseca.com"})
    with urllib.request.urlopen(req) as r:
        return json.load(r)

def seconds(iso):
    m = re.match(r"PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?", iso or "")
    if not m: return 0
    h, mi, s = [int(x) if x else 0 for x in m.groups()]
    return h * 3600 + mi * 60 + s

pool, token = [], None
for _ in range(5):
    page = api("playlistItems", part="snippet", playlistId=UPLOADS, maxResults=50,
               **({"pageToken": token} if token else {}))
    ids = [i["snippet"]["resourceId"]["videoId"] for i in page.get("items", [])]
    if not ids: break
    detail = api("videos", part="snippet,contentDetails,statistics", id=",".join(ids))
    for v in detail.get("items", []):
        secs = seconds(v["contentDetails"]["duration"])
        pool.append({
            "video_id": v["id"],
            "title": v["snippet"]["title"],
            "description": v["snippet"].get("description", ""),
            "published_at": v["snippet"]["publishedAt"],
            "duration_seconds": secs,
            "duration_iso": v["contentDetails"]["duration"],
            "views": int(v["statistics"].get("viewCount", 0)),
        })
    token = page.get("nextPageToken")
    if not token: break

json.dump(pool, open("pool.json", "w"), ensure_ascii=False)
longs = [p for p in pool if 180 <= p["duration_seconds"] <= 7200]
print(f"pool: {len(pool)} videos | dentro de 3-120 min: {len(longs)}")
buckets = {"3-15 min": 0, "15-30 min": 0, "30-60 min": 0, "60-120 min": 0}
for p in longs:
    m = p["duration_seconds"] / 60
    k = "3-15 min" if m < 15 else "15-30 min" if m < 30 else "30-60 min" if m < 60 else "60-120 min"
    buckets[k] += 1
for k, v in buckets.items():
    print(f"  {k:10} {v}")
