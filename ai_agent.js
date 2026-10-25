/* =====================================================================
 *  ai_agent.js — chat with the AI assistant over your own mailbox.
 *
 *  The window skeleton is in the template chat.html. The browser talks
 *  to the data API directly (CORS):
 *    - POST <chat>chat/completions              OpenAI format, SSE stream
 *    - GET  <api>?fn=ai_list_tasks&type=live     conversation list
 *    - GET  <api>?fn=ai_get_conversation&at_id=… conversation history
 *  The token (rcmail.env.ai_agent.token) is the user key of the mailbox,
 *  sent in a header. On 401 it asks the plugin for a new one
 *  (plugin.ai_agent-token).
 *
 *  API stream: for several minutes only ": ping" arrives (the agent is
 *  working, reading mail), the answer comes at the end in chunks. Hence
 *  the indicator with elapsed time. The agent's answer is Markdown
 *  containing text of foreign mail — always through DOMPurify.
 *
 *  Tools (TOOLS below) are executed here, in the browser: the request
 *  carries their definitions in "tools", the backend offers them to the
 *  model but does not run them, and a call comes back as tool_calls. The
 *  plugin runs it and sends the result ("OK" or the error) as a "tool"
 *  message in the next request; the model then answers or calls again.
 * ===================================================================== */
window.rcmail && rcmail.addEventListener('init', function () {
    'use strict';

    if (rcmail.task != 'aiagent' || !rcmail.env.ai_agent) {
        return;
    }

    var cfg = rcmail.env.ai_agent,
        api = cfg.api,
        chat = cfg.chat;
    var byId = function (id) { return document.getElementById(id); };
    var box = byId('aiagent-messages'), form = byId('aiagent-form'), input = byId('aiagent-input'),
        send = byId('aiagent-send'), status = byId('aiagent-status'), list = byId('aiagent-list'), title = byId('aiagent-title');
    var S = { conv: '', turns: [], busy: false, ctrl: null, timer: null, ro: false, stop: false, rounds: 0 };
    var MAX_ROUNDS = 5;     // tool rounds per question, then stop (protection against a loop)

    var L = function (name, vars) {
        var s = rcmail.gettext(name, 'ai_agent');
        if (vars) {
            Object.keys(vars).forEach(function (k) { s = s.replace('$' + k, vars[k]); });
        }
        return s;
    };

    function el(tag, cls, text) {
        var e = document.createElement(tag);
        if (cls) e.className = cls;
        if (text != null) e.textContent = text;
        return e;
    }

    function md(text) {
        var html = window.marked ? marked.parse(String(text), { gfm: true, breaks: true }) : null;
        if (html === null || !window.DOMPurify) {           // without the libraries at least plain text
            var d = el('div'); d.textContent = text; return d.innerHTML;
        }
        return DOMPurify.sanitize(html);
    }

    /* Links from the answer open in a new window (and without opener). */
    if (window.DOMPurify) {
        DOMPurify.addHook('afterSanitizeAttributes', function (node) {
            if (node.tagName === 'A') { node.setAttribute('target', '_blank'); node.setAttribute('rel', 'noopener noreferrer'); }
        });
    }

    /* Tokens in short: 12345 -> 12k, 850 -> 850. */
    function kTok(n) { n = +n || 0; return n >= 1000 ? Math.round(n / 1000) + 'k' : String(n); }

    function fmtTime(s) {
        var d = s ? new Date(String(s).replace(' ', 'T')) : new Date();
        return isNaN(d) ? '' : d.toLocaleString(rcmail.env.locale ? String(rcmail.env.locale).replace('_', '-') : undefined,
            { day: 'numeric', month: 'numeric', hour: '2-digit', minute: '2-digit' });
    }

    /* ---- token and API calls ------------------------------------- */
    var waiting = [];
    rcmail.addEventListener('plugin.ai_agent-token', function (d) {
        cfg.token = (d && d.token) || '';
        var w = waiting; waiting = [];
        w.forEach(function (f) { f(d || {}); });
    });

    /* A new token from the plugin (it logs in to the API with the Roundcube username and password). */
    function renew() {
        return new Promise(function (resolve) {
            waiting.push(resolve);
            if (waiting.length === 1) rcmail.http_post('plugin.ai_agent-token', {});
        });
    }

    /* fetch with the token; on 401 (expired API session) once more with a new one. */
    function call(url, opts) {
        opts = opts || {};
        var go = function () {
            var o = Object.assign({}, opts);
            o.headers = Object.assign({}, opts.headers, { Authorization: 'Bearer ' + cfg.token });
            return fetch(url, o);
        };
        return go().then(function (r) {
            if (r.status !== 401) return r;
            return renew().then(function () { return cfg.token ? go() : r; });
        });
    }

    /* ---- tools ---------------------------------------------------- */
    /* name => definition for the model, run(args) → Promise of "OK" or
       "ERROR: …", label(args) for the action line in the chat; reopen =
       the line is a link that runs the tool again. */
    var TOOLS = {
        mail_open_gui: {
            description: 'Opens a message for the user in the webmail, in a window over the chat. '
                + 'Returns OK, or the error.',
            parameters: {
                type: 'object',
                properties: {
                    folder: { type: 'string', description: 'Folder of the message, e.g. INBOX.' },
                    uid: { type: 'integer', description: 'UID of the message in the folder.' }
                },
                required: ['folder', 'uid'],
                additionalProperties: false
            },
            run: openMessage,
            label: function (a) { return L('tool_open', { msg: a.folder + ' / ' + a.uid }); },
            reopen: true
        }
    };

    function toolDefs() {
        return Object.keys(TOOLS).map(function (n) {
            return { type: 'function', function: { name: n, description: TOOLS[n].description, parameters: TOOLS[n].parameters } };
        });
    }

    function callArgs(c) {
        try { var a = JSON.parse(c.function.arguments || '{}'); return a && typeof a === 'object' ? a : {}; } catch (e) { return null; }
    }

    function runTool(c) {
        var t = TOOLS[c.function.name], a = callArgs(c);
        if (!t) return Promise.resolve('ERROR: unknown tool ' + c.function.name);
        if (a === null) return Promise.resolve('ERROR: arguments are not valid JSON');
        return t.run(a).catch(function (e) { return 'ERROR: ' + ((e && e.message) || e); });
    }

    /* Does the message exist? Asks the plugin (Roundcube's IMAP). */
    var checks = {};
    rcmail.addEventListener('plugin.ai_agent-checked', function (d) {
        var f = d && checks[d.id];
        if (f) { delete checks[d.id]; f(d); }
    });
    function checkMessage(folder, uid) {
        return new Promise(function (resolve) {
            var id = 'c' + Date.now() + Math.random().toString(36).slice(2, 6);
            checks[id] = resolve;
            setTimeout(function () {
                if (checks[id]) { delete checks[id]; resolve({ error: 'webmail did not answer the message check' }); }
            }, 20000);
            rcmail.http_post('plugin.ai_agent-check', { _id: id, _mbox: folder, _uid: uid });
        });
    }

    function openMessage(a) {
        var uid = parseInt(a.uid, 10), folder = String(a.folder || '');
        if (!folder || !(uid > 0)) return Promise.resolve('ERROR: folder and uid are required');
        return checkMessage(folder, uid).then(function (d) {
            if (d.error) return 'ERROR: ' + d.error;
            showMessage(d.mbox, uid, d.subject);
            return 'OK';
        });
    }

    /* The message in a window over the chat — the conversation goes on.
       "Open in Mail" goes to the folder list with the message selected (index
       with _uid); Roundcube selects it only when it is on the first page. */
    function showMessage(mbox, uid, subject) {
        var q = { _mbox: mbox, _uid: uid };
        var frame = $('<iframe>').attr({ src: rcmail.url('mail/preview', $.extend({ _framed: 1 }, q)), frameborder: 0 });
        var inMail = rcmail.env.comm_path.replace(/_task=[a-z0-9_-]+/, '_task=mail') + '&' + $.param(q);
        rcmail.show_popup_dialog(frame, subject || L('message'), [
            { text: L('open_in_mail'), 'class': 'mainaction', click: function () { location.href = inMail; } },
            { text: rcmail.gettext('close'), click: function () { $(this).dialog('close'); } }
        ], { width: 900, height: 700 });
    }

    /* A line in the chat for a tool call and its result. */
    function action(c, res) {
        var t = TOOLS[c.function.name], a = callArgs(c) || {}, failed = res && res !== 'OK';
        var d = el('div', 'ai-agent-action' + (failed ? ' failed' : '')), text = t ? t.label(a) : c.function.name;
        if (t && t.reopen && res === 'OK') {
            var link = el('a', '', text);
            link.href = '#';
            link.addEventListener('click', function (ev) { ev.preventDefault(); t.run(a); });
            d.appendChild(link);
        } else {
            d.appendChild(el('span', '', text));
        }
        if (failed) d.appendChild(el('span', 'error', ' — ' + res));
        box.appendChild(d);
        box.scrollTop = box.scrollHeight;
    }

    /* ---- rendering ------------------------------------------------ */
    function bubble(role, content, when) {
        var me = role === 'user';
        var m = el('div', 'ai-agent-msg' + (me ? ' user' : ''));
        m.appendChild(el('div', 'meta', (me ? L('you') : L('assistant')) + (when ? ' · ' + when : '')));
        var t = el('div', 'text');
        if (me) t.textContent = content; else { t.classList.add('ai-agent-md'); t.innerHTML = md(content); }
        m.appendChild(t);
        box.appendChild(m);
        box.scrollTop = box.scrollHeight;
        return t;
    }

    function renderAll(note) {
        box.innerHTML = '';
        if (!S.turns.length) box.appendChild(el('div', 'ai-agent-intro', note || L('intro')));
        var results = {};
        S.turns.forEach(function (t) { if (t.role === 'tool') results[t.tool_call_id] = t.content; });
        S.turns.forEach(function (t) {
            if (t.role === 'tool') return;
            var text = String(t.content || '').trim();
            if (text) bubble(t.role, text, '');
            (t.tool_calls || []).forEach(function (c) { action(c, results[c.id] || ''); });
        });
    }

    function setTitle(text) {
        title.textContent = text;
    }

    /* Older conversations (without stored history) are read-only — the API would refuse to continue them. */
    function setRo(on) {
        S.ro = on;
        input.disabled = on;
        send.disabled = on;
        input.placeholder = on ? L('placeholder_ro') : L('placeholder');
    }

    function setSendButton(busy) {
        send.textContent = busy ? L('stop') : L('send');
        send.title = send.textContent;
        send.classList.toggle('btn-danger', busy);
        send.classList.toggle('btn-primary', !busy);
    }

    function setBusy(on, text) {
        S.busy = on;
        input.disabled = on || S.ro;
        setSendButton(on);
        rcmail.enable_command('plugin.ai_agent-new', !on);
        clearInterval(S.timer);
        status.textContent = text || '';
        if (on) {
            var t0 = Date.now();
            S.timer = setInterval(function () {
                var s = Math.round((Date.now() - t0) / 1000);
                status.textContent = L('working') + ' ' + (s >= 60 ? Math.floor(s / 60) + ' ' + L('min') + ' ' : '') + (s % 60) + ' s';
            }, 1000);
        }
    }

    /* ---- conversation list ---------------------------------------- */
    function loadList() {
        call(api + '?fn=ai_list_tasks&type=live&limit=50')
            .then(function (r) { return r.json(); })
            .then(function (d) {
                list.innerHTML = '';
                var tasks = (d && d.tasks) || [];
                if (!tasks.length) list.appendChild(el('li', 'empty', L('list_empty')));
                tasks.forEach(function (t) {
                    var li = el('li', String(t.at_id) === S.conv ? 'selected' : '');
                    li.setAttribute('role', 'option');
                    var a = el('a');
                    a.href = '#';
                    a.appendChild(el('span', 'title', t.title || L('untitled')));
                    /* last activity, not creation — a conversation can be continued days later */
                    var sm = el('span', 'info', fmtTime(t.finished || t.started || t.created));
                    if (t.tokens_in || t.tokens_out) {
                        var tk = el('span', '', ' (' + kTok(t.tokens_in) + '/' + kTok(t.tokens_out) + ')');
                        tk.title = L('tokens_title');
                        sm.appendChild(tk);
                    }
                    if (t.state === 'error') sm.appendChild(el('span', 'badge badge-danger', L('state_error')));
                    if (t.state === 'running') sm.appendChild(el('span', 'badge badge-info', L('state_running')));
                    a.appendChild(sm);
                    a.addEventListener('click', function (ev) {
                        ev.preventDefault();
                        if (!S.busy) open(String(t.at_id));
                    });
                    li.appendChild(a);
                    list.appendChild(li);
                });
            })
            .catch(function () {
                list.innerHTML = '';
                list.appendChild(el('li', 'empty', L('list_error')));
            });
    }

    function open(id) {
        status.textContent = L('loading_conv');
        call(api + '?fn=ai_get_conversation&at_id=' + encodeURIComponent(id))
            .then(function (r) { return r.json().then(function (d) { return [r.status, d]; }); })
            .then(function (x) {
                if (x[0] !== 200) { status.textContent = (x[1] && x[1].error) || L('conv_error'); return; }
                var c = x[1].conversation;
                S.conv = String(c.at_id); S.turns = c.turns || [];
                setTitle(S.turns.length ? String(S.turns[0].content).slice(0, 60) : fmtTime(c.created));
                setRo(!S.turns.length);
                renderAll(S.turns.length ? '' : L('readonly_note'));
                status.textContent = c.state === 'running' ? L('running_note') : '';
                loadList();
                // on a small screen go from the side list back to the conversation
                window.jQuery && jQuery('#layout-sidebar a.back-content-button:visible').click();
            })
            .catch(function () { status.textContent = L('conv_error'); });
    }

    function reset() {
        if (S.busy) return;
        S.conv = ''; S.turns = [];
        setRo(false);
        setTitle(L('newconv_title'));
        renderAll(); status.textContent = ''; input.focus();
        loadList();
    }

    /* ---- sending and the stream ----------------------------------- */
    function fail(msg, keepTurn) {
        // an unanswered question does not belong to the history (tool results stay — the server fills in the rest)
        if (!keepTurn && S.turns.length && S.turns[S.turns.length - 1].role === 'user') S.turns.pop();
        setBusy(false, '');
        box.appendChild(el('div', 'boxerror ai-agent-error', msg));
        box.scrollTop = box.scrollHeight;
    }

    function ask(text) {
        S.turns.push({ role: 'user', content: text });
        if (S.turns.length === 1) { box.innerHTML = ''; setTitle(text.slice(0, 60)); }
        bubble('user', text, fmtTime());
        setBusy(true, L('working'));
        S.stop = false;
        S.rounds = 0;
        request();
    }

    /* One request to the chat; after tool calls it runs the tools and sends the next one. */
    function request() {
        S.ctrl = new AbortController();
        var h = { 'Content-Type': 'application/json' };
        if (S.conv) h['X-Conversation-Id'] = S.conv;
        var answer = '', target = null, calls = [];

        call(chat + 'chat/completions', {
            method: 'POST', headers: h, signal: S.ctrl.signal,
            body: JSON.stringify({ model: cfg.model, stream: true, messages: S.turns, tools: toolDefs() })
        }).then(function (r) {
            var cid = r.headers.get('X-Conversation-Id');
            if (cid) S.conv = cid;
            if (!r.ok) {
                return r.json().catch(function () { return {}; }).then(function (d) {
                    var m = (d.error && (d.error.message || d.error)) || L('http_error', { code: r.status });
                    if (r.status === 429) m += ' ' + L('retry_after', { s: r.headers.get('Retry-After') || 30 });
                    fail(m);
                });
            }
            var reader = r.body.getReader(), dec = new TextDecoder(), buf = '';
            var pump = function () {
                return reader.read().then(function (x) {
                    if (x.done) return finish();
                    buf += dec.decode(x.value, { stream: true });
                    var lines = buf.split('\n'); buf = lines.pop();
                    lines.forEach(line);
                    return pump();
                });
            };
            return pump();
        }).catch(function (e) {
            if (e && e.name === 'AbortError') fail(L('stopped'));
            else fail(L('conn_lost'));
        });

        function line(l) {
            if (l.indexOf('data: ') !== 0) return;          // ": ping" and empty lines
            var p = l.slice(6);
            if (p === '[DONE]') return;
            var d; try { d = JSON.parse(p); } catch (e) { return; }
            if (d.error) { fail(d.error.message || L('no_answer')); answer = null; return; }
            if (d.conversation_id) S.conv = String(d.conversation_id);
            var delta = (d.choices && d.choices[0] && d.choices[0].delta) || {};
            // tool calls may come in pieces (OpenAI): by index, arguments appended
            (delta.tool_calls || []).forEach(function (tc) {
                var t = calls[tc.index || 0] || (calls[tc.index || 0] = { id: '', type: 'function', function: { name: '', arguments: '' } });
                if (tc.id) t.id = tc.id;
                if (tc.function && tc.function.name) t.function.name = tc.function.name;
                if (tc.function && tc.function.arguments) t.function.arguments += tc.function.arguments;
            });
            if (delta.content && answer !== null) {
                answer += delta.content;
                if (!target && !answer.trim()) return;      // some models start with empty lines
                if (!target) target = bubble('assistant', '', fmtTime());
                target.innerHTML = md(answer.trim());
                box.scrollTop = box.scrollHeight;
            }
        }
        function finish() {
            if (answer === null) return;
            answer = answer.trim();
            calls = calls.filter(Boolean);
            if (calls.length) { runCalls(answer, calls); return; }
            if (answer === '') { fail(L('empty_answer')); return; }
            S.turns.push({ role: 'assistant', content: answer });
            setBusy(false, '');
            loadList();
            input.focus();
        }
    }

    /* Tool calls of one answer: run them one by one, then ask the model again. */
    function runCalls(answer, calls) {
        S.turns.push({ role: 'assistant', content: answer, tool_calls: calls });
        if (++S.rounds > MAX_ROUNDS) { fail(L('tool_rounds'), true); return; }
        var p = Promise.resolve();
        calls.forEach(function (c) {
            p = p.then(function () { return runTool(c); }).then(function (res) {
                S.turns.push({ role: 'tool', tool_call_id: c.id, content: res });
                action(c, res);
            });
        });
        p.then(function () {
            if (S.stop) fail(L('stopped'), true);
            else request();
        });
    }

    form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        if (S.busy) { S.stop = true; if (S.ctrl) S.ctrl.abort(); return; }
        if (S.ro) return;
        var text = input.value.trim();
        if (!text) return;
        input.value = '';
        ask(text);
    });
    input.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter' && !ev.shiftKey) { ev.preventDefault(); form.requestSubmit(); }
    });
    rcmail.register_command('plugin.ai_agent-new', reset, true);

    setSendButton(false);
    setRo(false);
    renderAll();
    loadList();
    input.focus();
});
