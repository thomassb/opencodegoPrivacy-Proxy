# opencodegoPrivacy-Proxy

A small single-file PHP proxy (plus dashboard) for the OpenCode Go Responses API,
designed for OpenAI-compatible clients such as VS Code.

## Requirements

- PHP 8.1+ with the cURL extension
- A web server that forwards the `Authorization` header to PHP

## Setup

1. Copy `.env.example` to `.env` and fill in:
   - `PROXY_CLIENT_KEY` — the Bearer token your editor sends to this proxy
     (also used as the dashboard password unless `DASHBOARD_PASSWORD` is set).
   - `OPENCODE_GO_API_KEY` — your OpenCode Go upstream key.
   - `OPENCODE_GO_MODEL` — the model to force on every request.
2. Open the proxy URL in a browser to reach the dashboard and sign in.
3. Point your editor at the same URL (POST) with the Bearer token.

The proxy always forces the configured model, sets `store: false`, and removes
`previous_response_id` so requests are stateless.

## Request sanitizing

Some gateways echo non-standard fields such as `trace_id` on response items.
Clients replay those items verbatim on the next turn, and the upstream then
rejects the whole request with:

```
[InvalidParameter] Invalid request: invalid or unsupported parameter value trace_id: ...
```

The proxy recursively strips configured parameter names from the request body
before forwarding. Default:

```
PROXY_STRIP_PARAMS=trace_id
```

Add more names as a comma-separated list if the upstream rejects other fields.

## Dev debug capture

Set the following in `.env` to capture full request/response bodies:

```dotenv
PROXY_DEBUG=true
# Optional limits
PROXY_DEBUG_MAX_BYTES=262144
PROXY_DEBUG_LOG_BYTES=10485760
```

With `PROXY_DEBUG=true` the proxy writes `proxy-debug.jsonl` (mode `0600`,
git-ignored) containing:

- `client_request` — incoming headers (Authorization masked) and raw body
- `upstream_request` — exact URL, headers (Authorization masked), and body sent upstream
- `stripped_params` — parameters removed by `PROXY_STRIP_PARAMS`
- `upstream_response` — status, response headers, and captured response body

The dashboard shows the newest entries in a collapsible **Debug log** panel and
can clear the file.

Request 1 succeeding and request 2 failing usually means the client replayed
response item fields. Compare the `client_request` / `upstream_request` bodies of
both turns in the debug log to confirm.

> **Warning:** debug bodies include prompts and model output. Keep
> `PROXY_DEBUG=false` in production, never commit `proxy-debug.jsonl`, and
> delete the file after debugging.

## Files

- `index.php` — proxy + dashboard
- `proxy-activity.jsonl` — minimal request metadata (no prompts)
- `proxy-debug.jsonl` — full payloads, only when `PROXY_DEBUG=true`
- `.env` — secrets, never commit

