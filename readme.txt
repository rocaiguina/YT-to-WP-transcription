=== YT to WP Transcription ===
Contributors: jagualmedia
Tags: youtube, transcript, captions, drafts, ai
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Watches a YouTube channel and turns each upload into a WordPress draft carrying an edited, readable transcript.

== Description ==

The video is the original work. This plugin does not write journalism: it takes words that were already spoken, corrects what speech recognition got wrong, cuts them into sections, and adds a headline and a short lede so the page reads as an article rather than a wall of machine output.

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

== Changelog ==

= 0.1.1 =
* The buttons on the queue screen now work before "Run automatically" is switched on. That setting gates the scheduled jobs only, which is what it was always meant to do: the buttons exist to prove the setup works before the automation is trusted with it.

= 0.1.0 =
* First release.
