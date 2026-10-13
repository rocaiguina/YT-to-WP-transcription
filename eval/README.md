# Eval for the article prompt

Measures whether the prompt turns an episode into an article worth
publishing, so a change to it can be judged by a number rather than by
reading one output and hoping.

The prompt is **not** copied here. `render_prompt.php` loads the plugin's own
classes and prints the exact request it would send, so a prompt change shows
up in the next run with nothing to keep in sync.

## What it measures

Four things, split by what can be settled with code and what needs judgement.

**Deterministic, free, run on every case:**

| Check | Fails when |
|---|---|
| `idioma` | The article came out in a language other than the caption language. |
| `sin_marcas` | Something gives away that it began as speech: a timestamp, "en este episodio", the word transcript. Clock times from the news are left alone. |
| `cifras` | A figure appears in the article that is nowhere in the transcript. The strongest objective signal there is: an invented number reads as authoritative and is wrong. |
| `estructura` | No headline, no body, an all-caps headline, a lede under 60 words, a section with no text. |
| `fuentes` | An outlet is credited that the video description never mentions. |

**Judged by `claude-opus-5-5`:**

| Criterion | What it asks |
|---|---|
| `attribution` | Did anything he reported as someone else's claim get restated as plain fact? This is the one with legal consequences. |
| `faithful` | Is anything in the article absent from the transcript? |
| `voice` | 1 to 5, does it read as though he wrote it himself, judged against how he actually talks in that transcript. |
| `ads_removed` | Did a sponsor read survive into the article? |

The per-case score weighs the deterministic checks and the judge's three
pass/fail calls equally, then lets voice shift the result by a quarter. Voice
can't rescue an article that invented a figure, which is the intended
ordering: a well-written article with a made-up number is worse than a flat
one that is accurate.

## Running it

```bash
export ANTHROPIC_API_KEY=sk-ant-...
pip3 install anthropic youtube-transcript-api

python3 run_eval.py --limit 3     # smoke test, a few cents
python3 run_eval.py               # the full set
python3 run_eval.py --no-judge    # deterministic checks only, free
```

Roughly **$0.11 per case** with the default pair (`claude-haiku-4-5` writing,
`claude-opus-5-5` judging), so about **$2.80** for twenty-five. Set
`YTWP_MODEL` to measure a different writer.

A run is resumable: finished cases are skipped on a re-run, failures land in
`errors.jsonl` rather than scoring as zeros, and each case writes as it
completes so a crash costs only what was in flight.

Results go to `.hillclimb/article/<variant>/` - `results.jsonl`, one
`traces/<id>_rep0.json` per case with the full article, and `errors.jsonl`.

## Building the case set

The cases are not in the repository: they are one channel's transcripts, which
belong to whoever owns that channel and would bloat a plugin nobody else can
use them with.

```bash
python3 fetch_pool.py      # list the channel's uploads
python3 build_cases.py     # pull captions for a stratified sample
python3 review.py          # write CASES.md to read before trusting a score
```

Edit the channel id at the top of `fetch_pool.py` for a different channel.

Two things worth knowing. YouTube rate-limits caption downloads by IP, and
pulling a few dozen in a row will get you blocked for a while - the plugin
itself is unaffected, since it goes through the official API with OAuth.

And **episodes with a guest do not belong in the set**. The plugin passes
those over, so the prompt never sees them, and scoring them would measure
something that never happens. `detect_guests.py` flags the obvious ones, but
it is a blunt instrument: it misses an interview whose only tell is the
back-and-forth, and it flags a solo episode that thanks the audience for
being there. Treat it as a first pass and let the run itself settle the
rest - every result carries the model's own verdict on who speaks.
