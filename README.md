# AI agent for Roundcube

**Chat with an AI assistant that knows your mailbox — right inside Roundcube.**

Ask *"what important came this week?"* or *"find the last invoice from my
provider and summarize it"*. The agent searches and reads your mail and
answers in Markdown. Besides the chat, users can switch on AI sorting of
incoming mail and daily or weekly reports, write their own instructions
for each agent, edit what the assistant remembers about them and see a
log of everything the AI did, including token usage.

![Chat with the AI agent](screenshots/chat.png)

## Built for SSHosting, made for anyone

The plugin was created for [SSHosting](https://www.sshosting.cz), where it
runs in production on top of an AI agent that works with real mailboxes.
It is not tied to that backend, though. The design follows today's common
patterns, and on the wire it is a combination of two simple things:

1. **The OpenAI Chat Completions API** (`/v1/chat/completions`, SSE
   streaming) with one small addition: a server-side session carried in the
   `X-Conversation-Id` header.
2. **A small REST-like data API**: one URL, the operation in `?fn=`,
   JSON responses and a Bearer token. Seven operations cover login and
   logout, mailbox settings, the task list, conversation history and usage
   sums.

Both are fully specified in [`config.inc.php.dist`](config.inc.php.dist).
Implement them over any agent you like: an LLM with IMAP tools, a local
model, a ticketing system or anything else. The plugin talks to nothing
else, with no database access and no daemons.

## Features

- **AI agent** in the main menu: the conversation window with the list of
  past conversations next to it, live streaming, a stop button and
  continuing older conversations. It uses the responsive layout of the
  Elastic skin.
- **Settings → AI → AI agent instructions**: switches for AI sorting of
  incoming mail and for daily and weekly reports, custom instructions for
  each agent (falling back to defaults) and the assistant's memory, which
  the user can edit.
- **Settings → AI → AI log**: every AI task by month with its state,
  folder, input and output tokens and result, plus monthly sums.
- Clear notices for a mailbox or domain without the service: it is
  either not purchased, or it has to be activated by an administrator.
- Elastic skin with dark mode. English and Czech.

| AI agent instructions | AI log |
|---|---|
| ![AI agent instructions](screenshots/instructions.png) | ![AI log](screenshots/log.png) |

## How it works

```
 Browser ──── chat (SSE), conversation list ─────────────────┐
    │                                                        ▼
 Roundcube + ai_agent ── login, settings, log (server side) ─► Data API ─► your AI agent
```

- **Login.** The plugin logs in to the API with the same username and
  password the user used for Roundcube (`do_login`). It gets a token that is
  valid for **this mailbox only** and keeps it in the Roundcube session. The
  password is never stored. On expiry (401) the plugin logs in again, and
  logging out of Roundcube calls `do_logout`.
- **Settings and log** are called by the plugin on the server.
- **The chat** is called by the browser directly, because an agent's answer
  can take minutes and is streamed. The API therefore has to send CORS
  headers. The token travels in a header and no cookies are used, so
  `Access-Control-Allow-Origin: *` is safe.
- **Answers are Markdown** that may quote foreign mail. They are rendered
  with [marked](https://github.com/markedjs/marked) and always sanitized
  with [DOMPurify](https://github.com/cure53/DOMPurify). Links open in a new
  window without an opener.

## Installation

With Composer, from the Roundcube directory:

```
composer require shimmi2/ai-agent
```

The Roundcube plugin installer puts the plugin into `plugins/ai_agent`,
offers to enable it and creates `config.inc.php` from the `.dist` file.

Without Composer:

```
git clone https://github.com/shimmi2/roundcube-ai-agent.git
roundcube-ai-agent/install.sh /path/to/roundcube    # [--web-group www-data]
```

Then add `'ai_agent'` to `$config['plugins']` in `config/config.inc.php`.

## Configuration

`plugins/ai_agent/config.inc.php`:

| option | meaning |
|---|---|
| `ai_agent_api_url` | base URL of the data API, e.g. `https://api.example.com/` (required) |
| `ai_agent_chat_url` | base URL of the OpenAI-compatible chat; `null` = `<api_url>v1/` |
| `ai_agent_model` | model name sent in chat requests (default `mail-ai`) |

## Implementing the backend

The full specification is in [`config.inc.php.dist`](config.inc.php.dist).
In short:

| operation | method | purpose |
|---|---|---|
| `do_login` / `do_logout` | POST | exchange mailbox credentials for a per-mailbox token / end it |
| `mail_get_mailbox` | GET | mailbox info, AI switches, instructions with defaults, memory |
| `mail_store_mailbox` | POST | store the fields the user may change |
| `ai_list_tasks` | GET | tasks with state and tokens; `type=live` = chat conversations |
| `ai_get_conversation` | GET | turns of one conversation |
| `ai_usage_months` | GET | monthly task and token sums |
| `chat/completions` | POST | OpenAI chat, SSE; session in `X-Conversation-Id` |

The service labels (for example a paid service activated by an
administrator) follow the SSHosting model. If your model differs, adjust
them in `localization/`.

## Requirements

- Roundcube 1.7 with the Elastic skin (developed and tested on 1.7.5)
- PHP 8.1 or newer
- an HTTPS backend implementing the API above

## Known limitations

- Dates and numbers in the AI log use a fixed format (`23. 10. 14:05`,
  `12 345`), not the user's locale.
- Only the Elastic skin is supported.

## License

GPL-3.0-or-later, see [LICENSE](LICENSE). For the bundled libraries see
[`js/README.md`](js/README.md).
