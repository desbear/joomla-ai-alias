# AI Alias for Joomla

A standalone Joomla system plugin that generates SEO-friendly article aliases with an
OpenAI-compatible API, instead of Joomla's own transliteration. For Chinese sites this matters:
Joomla turns `如何設定郵件通知` into `ru-he-she-ding-you-jian-tong-zhi` (pinyin, meaningless to search
engines and to readers), while this plugin produces `configure-email-notifications`.

Works with Joomla articles (`com_content`) and with [FLEXIcontent](https://www.flexicontent.org/)
items. When the API is unavailable or out of credits, the plugin silently falls back to Joomla's
built-in conversion, so saving an article never fails because of the AI.

## Features

- **Button in the editor** — an "AI alias" button next to the alias field, on the back-end article
  form, the front-end submission form and the FLEXIcontent item form.
- **Automatic on save** — articles saved without a usable alias get one from the AI. This covers the
  edit form as well as articles created by the Joomla API, an MCP server, a feed importer or the CLI.
- **Chinese aliases are replaced too** — an alias that is not URL safe yet would be transliterated
  into pinyin by Joomla, so the plugin treats it as "no alias" and generates one.
- **Aliases you typed are never touched** — a plain ASCII alias is left exactly as entered.
- **Graceful fallback** — out of credits, a bad key, a timeout or a rate limit all fall back to
  Joomla's own conversion (pinyin, or a Unicode slug that keeps the original characters).
- **Cost controls** — a site-wide calls-per-minute ceiling and a cooldown that stops calling the API
  for an hour after an out-of-credit response.
- **Unique aliases** — a numeric suffix is added when the category already uses the alias.

## Requirements

- Joomla 4.4, 5.x or 6.x
- PHP 7.4 or newer (Joomla 6 itself requires PHP 8.1+)
- An API key for OpenAI, or any OpenAI-compatible chat completions endpoint
- FLEXIcontent is optional; nothing else is needed

## Install

Download the package from [Releases](../../releases), or build it yourself:

```sh
./build.sh
```

Install the resulting `dist/plg_system_aialias-*.zip` through **System → Install → Extensions**, then
enable **System - AI Alias** and set the API key.

## Settings

| Setting | Default | Notes |
| --- | --- | --- |
| API Key | — | Field is locked; press *Modify* to replace it. Saving without unlocking keeps the stored key. |
| API Base URL | `https://api.openai.com/v1` | Any OpenAI-compatible endpoint; `/chat/completions` is appended. |
| Model | `gpt-4.1-mini` | A small model is enough for slugs. |
| Timeout | 15 s | Caps how long a save can wait for the API. |
| Maximum alias length | 60 | The slug is cut at a hyphen below this length. |
| Extra instructions | — | Site-specific guidance, e.g. how product names should be spelled. |
| Joomla articles / FLEXIcontent items | on | Enable the button and the automatic alias per component. |
| Text field IDs | `jform_articletext,jform_text,jform_description` | Editor fields read for the article text, tried in order. |
| Automatic aliases | All articles | Off, non-form only (API/MCP/importers), or all saves including the edit form. |
| Fallback conversion | Joomla core | Core transliteration (pinyin for Chinese), or a Unicode slug that keeps the characters. |
| Fallback on other errors | yes | When off, errors other than quota/rate limits are shown instead of silently falling back. |
| Calls per minute | 20 | Site-wide ceiling. Saves above it use the fallback. 0 disables the limit. |
| Credit cooldown | 60 min | After an out-of-credit response, skip the API for this long. |
| Log | on | Writes generated aliases and fallback reasons to `administrator/logs/plg_system_aialias.php`. |

## How it works

The plugin subscribes to `onContentBeforeSave`. By the time that event fires, Joomla (or FLEXIcontent)
has already filled the alias from the title, so the plugin inspects what was actually submitted: if
the alias field was left empty — or holds text that is not URL safe yet — it replaces the alias with
the AI result before the row is stored. Everything else is left alone.

The editor button posts to `com_ajax` (`index.php?option=com_ajax&plugin=aialias&group=system`),
which requires a valid CSRF token and `core.create`, `core.edit` or `core.edit.own` on `com_content`.

The AI response is never trusted as-is: it is run through `OutputFilter::stringURLSafe`, lowercased,
cut to the configured length and rejected when it ends up empty or purely numeric. The worst a
prompt-injected article can produce is an odd but valid slug.

## Privacy

The article title and the first 1500 characters of its text are sent to the configured API endpoint,
including for unpublished articles. If that is not acceptable for your content, point **API Base URL**
at a self-hosted OpenAI-compatible endpoint, or turn the automatic mode off and use the button only.

## Security notes

- The API key is stored in the `#__extensions` table as plain text, like every other Joomla plugin
  setting. Anyone who can edit plugins can replace it; the locked field keeps it out of the rendered
  HTML, and error messages only ever show its last four characters.
- The AJAX endpoint needs a CSRF token and article permissions, and the calls-per-minute ceiling
  limits what a logged-in author (or a runaway import) can spend.
- Article text reaches the API as prompt content only. It is never written back into the page, and
  the generated alias is sanitised before use.

## Troubleshooting

**401 "Incorrect API key provided"** — Joomla's password fields default to `maxlength="99"`, and an
`sk-proj-…` key is far longer, so older builds stored a truncated key. This plugin sets
`maxlength="500"`. Re-paste the key, then compare the last four characters with the OpenAI dashboard.

**The alias is still pinyin** — the AI call failed and the fallback ran. Check
`administrator/logs/plg_system_aialias.php` for the reason, and that **Automatic aliases** is set to
*All articles* if you want human edits covered too.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
