# YT to WP Transcription

A WordPress plugin that watches a YouTube channel and turns each upload into
a draft post carrying an edited, readable transcript.

The video is the original work. The plugin does not write journalism: it
takes the words that were already spoken, fixes what speech recognition got
wrong, cuts them into sections, and adds a headline and a short lede so the
page reads as an article instead of a wall of machine output.

**Nothing is ever published automatically.** Every post is created as a
draft for a human to read, correct and publish.

## What it does

1. Checks the channel hourly and notes new uploads in a queue.
2. Every five minutes, takes one queued video and:
   - downloads its caption track from YouTube,
   - sends the transcript to Claude for editing,
   - creates a WordPress draft with the result.
3. Shows the queue under **Tools → Video queue**, with the reason for
   anything it passed over and a retry button for anything that failed.

Videos too short to carry an article (Shorts, clips of a couple of minutes,
or anything with no caption track) get a lighter draft holding the video and
its description, so every upload still ends up with a page. That can be
switched off.

## Why one video at a time

A long episode produces tens of thousands of words of transcript, and the
model call can take well over a minute. WP-Cron runs inside an ordinary PHP
request, and managed hosts cut those off after 30 to 60 seconds.

Handling a single video per run keeps each one short enough to finish. The
queue carries the rest forward, so a backlog drains steadily instead of one
long run dying halfway and losing its work.

## Requirements

- WordPress 6.0 or newer, PHP 7.4 or newer
- An [Anthropic API key](https://console.anthropic.com)
- A Google Cloud project with the YouTube Data API v3 enabled
- Ownership of the channel, since caption tracks are owner-only

Advanced Custom Fields is optional. Without it the article goes into the
post content like any other post.

## Installing

Clone into your plugins directory and activate:

```bash
cd wp-content/plugins
git clone https://github.com/rocaiguina/YT-to-WP-transcription.git
```

Then activate it from **Plugins** in wp-admin.

## Setting up

Everything lives under **Settings → YT Transcription**.

### 1. API keys

| Key | Where it comes from |
|---|---|
| Anthropic API key | console.anthropic.com |
| YouTube API key | Google Cloud Console, Credentials, API key |

Keys are stored in the WordPress options table. Leave a key box blank when
saving to keep the one already stored.

### 2. Connecting the channel

Listing uploads needs only the API key, but **downloading a caption track is
owner-only**, so the plugin has to act as the channel's own account. The
public `timedtext` endpoint that older tools relied on no longer returns
track bodies, so there is no way around this.

In Google Cloud Console:

1. Enable **YouTube Data API v3**.
2. Create an OAuth client of type **Web application**.
3. Copy the redirect URL shown on the plugin's settings screen into
   **Authorized redirect URIs**, exactly as it appears.
4. Paste the client id and secret into the plugin, save, then press
   **Connect to YouTube** and sign in with the account that owns the channel.

The plugin stores the refresh token and mints short-lived access tokens from
it. If Google ever returns no refresh token, remove the app from your Google
account permissions and connect again.

### 3. Choosing what becomes a post

| Setting | What it controls |
|---|---|
| Minimum transcript words | Below this a video is treated as a clip. 400 words is roughly three minutes of speech. |
| Shorts length | What counts as a Short. |
| Short videos | Whether to transcribe them, and whether to give them a post at all. |
| Category, Author | Applied to every draft. |
| House notes | Appended to the editing instructions. Good for recurring names the transcriber mishears. |

### 4. Advanced Custom Fields (optional)

If your theme renders posts from ACF fields rather than post content, name
those fields and the plugin fills them as well as the post content.

| Field setting | Receives |
|---|---|
| Body field | The whole article |
| Summary field | The key points, one per line |
| Video id field | The YouTube video id |
| Sources heading field | A heading for the sources block |
| Source link fields | Comma separated ACF link fields, filled in order |

Sources past the last link field stay in the body, so attribution is never
dropped.

## Running it

Switch on **Run automatically** and the two scheduled jobs take over. Both
buttons on the queue screen also work on demand, which is the quickest way
to confirm the setup end to end.

WP-Cron only fires when someone visits the site. On a quiet site, point a
real cron job at `wp-cron.php` and disable the built-in one, or the queue
will move only when a visitor happens to arrive.

## What the editor still has to do

The plugin writes a checklist into each draft and shows it in the queue
notes. It flags names it could not resolve, figures that look mistranscribed,
and claims about named people.

That checklist exists because the model is told to keep attributions intact
and never turn an allegation into a statement of fact, but no automated pass
can be trusted with that on its own. Someone reads every draft before it
goes live.

Ad reads and sponsor segments are stripped out of the transcript and listed
separately, so you can see what was removed.

## Cost

Measured on a 22-minute episode, roughly 4,400 words of raw transcript:

| Model | Per video | 30 videos |
|---|---|---|
| claude-haiku-4-5 | about $0.03 | about $0.84 |
| claude-sonnet-5 | about $0.06 | about $1.68 |

Haiku is the default because this is a formatting job rather than a
reasoning one. Clip posts never reach the model and cost nothing.

## Privacy

The plugin sends the video transcript, title and description to the
Anthropic API for editing, and talks to the YouTube Data API for everything
else. It sends nothing anywhere else and collects nothing about your
visitors.

## Licence

GPL-2.0-or-later. See [LICENSE](LICENSE).
