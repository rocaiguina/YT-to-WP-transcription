=== YT to WP Transcription ===
Contributors: jagualmedia
Tags: youtube, transcript, captions, drafts, ai
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.2.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Watches a YouTube channel and turns each upload into a WordPress draft carrying an edited, readable transcript.

== Description ==

The video is the original work. This plugin writes it up: it takes the stories a journalist covered on camera and turns them into the article covering the same ground, organised by subject and written in that journalist's own voice. It adds no reporting of its own, and invents no fact, figure or quote that was not spoken.

Nothing is ever published automatically. Every post is created as a draft for a human to read, correct and publish.

**How it works**

1. Checks the channel hourly and notes new uploads in a queue.
2. Every five minutes, takes one queued video, downloads its caption track, sends the transcript to Claude for editing, and creates a draft.
3. Shows the queue under Tools, with the reason for anything passed over and a retry button for anything that failed.

Videos too short to carry an article get a lighter draft holding the video and its description, so every upload still ends up with a page. That can be switched off.

**Why one video per run**

A long episode produces tens of thousands of words of transcript, and the model call can take well over a minute. WP-Cron runs inside an ordinary PHP request, and managed hosts cut those off after 30 to 60 seconds. Handling one video per run keeps each one short enough to finish, and the queue carries the rest forward.

**What the editor still has to do**

The plugin writes a checklist into each draft: names it could not resolve, figures that look mistranscribed, and claims about named people. The model is told to keep attributions intact and never turn an allegation into a statement of fact, but no automated pass can be trusted with that alone. Someone reads every draft before it goes live.

== External services ==

This plugin relies on two external APIs.

**Anthropic API** — the video transcript, title and description are sent to api.anthropic.com so the model can produce the edited article. Terms: https://www.anthropic.com/legal/commercial-terms Privacy: https://www.anthropic.com/legal/privacy

**YouTube Data API v3** — used to list the channel's uploads and download its caption tracks from googleapis.com. Terms: https://developers.google.com/youtube/terms/api-services-terms-of-service Privacy: https://policies.google.com/privacy

No visitor data is collected or transmitted.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or clone it there.
2. Activate it through the Plugins screen.
3. Open Settings, YT Transcription, and add your Anthropic and YouTube API keys.
4. Create an OAuth client of type Web application in Google Cloud Console, register the redirect URL shown on the settings screen, and press Connect to YouTube.
5. Switch on Run automatically.

== Frequently Asked Questions ==

= Why does it need OAuth and not just an API key? =

Listing uploads needs only an API key, but downloading a caption track is owner-only. The public timedtext endpoint older tools relied on no longer returns track bodies, so acting as the channel's own account is the only way.

= Does it publish anything by itself? =

No. Every post is a draft.

= What does it cost to run? =

On a 22-minute episode, about three cents with the default model. Clip posts never reach the model and cost nothing.

= Do I need Advanced Custom Fields? =

No. The article goes into the post content. If your theme renders from ACF fields instead, you can name those fields and the plugin fills them too.

= The queue is not moving. =

WP-Cron only fires when someone visits the site. On a quiet site, point a real cron job at wp-cron.php. The buttons on the queue screen also run both jobs on demand.

= 0.2.4 =
* Headlines are now short: the model aims for four words around the biggest story, and never more than eight.

= 0.2.3 =
* Connect to YouTube now always shows Google's account picker, so the channel owner's account can be chosen instead of whichever account the browser signed in first.

= 0.2.2 =
* Fixed a deprecation notice from PHP 8.1 onwards: caption offsets arriving as floats were converted to integers implicitly when formatting a timestamp, which filled the site's error log on every run.

= 0.2.1 =
* Episodes where anyone else speaks are now passed over instead of written up. An article is written in one voice, so a guest, co-host or panel would have their sentences published under the journalist's name. Only the transcript reveals who speaks, so the video is read first and then skipped, and the queue records who else was there. Can be switched off.

= 0.2.0 =
* The output is now a written news article rather than an edited transcript. It covers the same stories, organised by subject and written in the journalist's own voice, with nothing announcing that it began as speech: no transcript heading, no timestamps, no "in this episode". The rules against inventing anything and against turning an allegation into a statement of fact are stricter than before, because a piece in someone's own voice carries more weight than loose speech.

= 0.1.9 =
* Only videos inside a length range are transcribed now, set in minutes on the settings screen. This replaces the Shorts setting, which was the same idea expressed worse, and the maximum is new: a multi-hour recording is slow and expensive to transcribe and the most likely to time out. The check runs before the caption download, so a video outside the range costs nothing to pass over.

= 0.1.8 =
* The settings screen now names the channel the saved id resolves to, along with how many videos it holds. Two channels can share a name, and the upload count is what separates a main channel from a near-empty namesake.
* Added a date floor, so pointing the plugin at a channel with years of uploads no longer queues its entire history.

= 0.1.7 =
* Fixed: deleting a draft and running the video again kept answering that a draft already existed. A post deleted for good was being mistaken for one that was still there.
* Fixed: videos whose row never reached the queue were counted as queued anyway. Titles carrying emoji were the likely casualty, so the queue table now asks for utf8mb4 and migrates itself on upgrade.
* The channel check now reports a database write failure instead of passing over it.

= 0.1.6 =
* Fixed: articles were being translated into English instead of staying in the language of the recording. The editing instructions now name that language in full, lead with it, and forbid translating the speaker outright.

= 0.1.5 =
* Fixed: the headings inside published posts were always English, because they were written as interface strings. They now follow the caption language, with Spanish, Portuguese and French shipped, and can be overwritten on the settings screen.
* Any video in the queue can be run again, not only a failed one.

= 0.1.4 =
* Added an optional Anthropic workspace id. An API key that belongs to the whole organization rather than one workspace is rejected until the request names a workspace, so the plugin now sends that header when the id is filled in.

= 0.1.3 =
* Fixed: connecting to YouTube appeared to succeed but never stored the refresh token, so the connection always read as missing. register_setting() hooks the sanitize callback onto every write of the option, not just the settings form, and the token was being stripped there because it has no form field of its own.

= 0.1.2 =
* The settings screen now opens with a setup status table showing which credential is ready and which is missing, and the queue warning names what is missing instead of only saying that something is.

= 0.1.1 =
* The buttons on the queue screen now work before "Run automatically" is switched on. That setting gates the scheduled jobs only, which is what it was always meant to do: the buttons exist to prove the setup works before the automation is trusted with it.

= 0.1.0 =
* First release.
