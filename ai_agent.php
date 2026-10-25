<?php

/**
 * AI agent — chat with an AI assistant over your own mailbox, its
 * instructions and a log of its work.
 *
 * The plugin talks neither to a database nor to any daemon, only to a data
 * API over HTTPS (specified in config.inc.php.dist). It logs in to the API
 * with the username and password of the Roundcube login (do_login) and gets
 * a user token valid for this mailbox only. The token is kept in the
 * Roundcube session, the password is never stored. The browser calls the
 * chat directly (CORS, streaming); instructions and the log are called by
 * the plugin on the server.
 *
 * Items:
 *   - task "aiagent" in the main menu: chat and conversation list
 *   - Settings → AI → AI agent instructions (mail_get/store_mailbox)
 *   - Settings → AI → AI log (ai_list_tasks, ai_usage_months)
 *
 * @license GPL-3.0-or-later
 */
class ai_agent extends rcube_plugin
{
    // No $task restriction: the plugin has to run on logout too
    // (session_destroy ends the API session); Roundcube renders the menu
    // button only where there is a menu.

    /** task type => [label, API switch] */
    private const TYPES = [
        'incoming' => ['type_incoming', 'ai_enable_incoming'],
        'summary_daily' => ['type_summary_daily', 'ai_enable_summary_daily'],
        'summary_weekly' => ['type_summary_weekly', 'ai_enable_summary_weekly'],
    ];

    /** task types run from the webmail, without a switch: general writing instructions => label */
    private const STYLE_TYPES = [
        'reply' => 'type_reply',
        'create' => 'type_create',
        'spellcheck' => 'type_spellcheck',
    ];

    /** tasks per page in the log */
    private const LOG_LIMIT = 500;

    private $rc;

    #[\Override]
    public function init()
    {
        $this->rc = rcmail::get_instance();
        $this->load_config();
        $this->add_texts('localization/');

        // Actions not bound to our task must be registered before
        // register_task(), otherwise they would belong to it only
        // (rcube_plugin::register_action uses $this->mytask).
        $this->register_action('plugin.ai_agent-token', [$this, 'action_token']);
        if ($this->rc->task == 'settings') {
            $this->add_hook('settings_actions', [$this, 'settings_actions']);
            $this->register_action('plugin.ai_agent', [$this, 'action_settings']);
            $this->register_action('plugin.ai_agent-instructions', [$this, 'action_instructions']);
            $this->register_action('plugin.ai_agent-instructions-save', [$this, 'action_instructions']);
            $this->register_action('plugin.ai_agent-log', [$this, 'action_log']);
        }

        $this->register_task('aiagent');
        $this->register_action('index', [$this, 'action_chat']);
        $this->register_action('plugin.ai_agent-check', [$this, 'action_check']);
        $this->register_action('plugin.ai_agent-draft', [$this, 'action_draft']);

        if ($this->rc->task == 'mail') {
            $this->add_hook('message_compose', [$this, 'message_compose']);
            $this->add_hook('message_compose_body', [$this, 'message_compose_body']);
        }
        $this->add_hook('startup', [$this, 'startup']);
        $this->add_hook('session_destroy', [$this, 'session_destroy']);
    }

    /* Main menu button (at the end, where Roundcube puts it). */
    public function startup($args)
    {
        if (!$this->framed()) {
            $this->add_button([
                'command' => 'aiagent',
                'class' => 'button-aiagent',
                'classsel' => 'button-aiagent button-selected',
                'innerclass' => 'button-inner',
                'label' => 'ai_agent.agent',
                'type' => 'link',
            ], 'taskbar');
        }
        $this->include_stylesheet($this->local_skin_path() . '/ai_agent.css');

        // "AI write" and "AI reply" in the mail toolbar (list and message view)
        if ($this->rc->task == 'mail' && !$this->framed()
            && in_array($this->rc->action, ['', 'index', 'show'], true)
        ) {
            $this->add_texts('localization/', ['ai_write', 'ai_reply', 'ai_write_title', 'ai_reply_title']);
            foreach (['write' => 'ai-write', 'reply' => 'ai-reply'] as $what => $class) {
                $this->add_button([
                    'command' => 'plugin.ai_agent-' . $what,
                    'type' => 'link',
                    'class' => "button {$class} disabled",
                    'classact' => "button {$class}",
                    'label' => 'ai_agent.ai_' . $what,
                    'title' => 'ai_agent.ai_' . $what . '_title',
                    'innerclass' => 'inner',
                ], 'toolbar');
            }
            $this->include_script('mail.js');
        }
        // "AI spell check" in the compose toolbar
        if ($this->rc->task == 'mail' && $this->rc->action == 'compose' && !$this->framed()) {
            $this->add_texts('localization/', ['ai_spellcheck_title']);
            $this->add_button([
                'command' => 'plugin.ai_agent-spellcheck',
                'type' => 'link',
                'class' => 'button ai-spellcheck',
                'classact' => 'button ai-spellcheck',
                'label' => 'ai_agent.ai_spellcheck',
                'title' => 'ai_agent.ai_spellcheck_title',
                'innerclass' => 'inner',
            ], 'toolbar');
            $this->include_script('mail.js');
        }

        return $args;
    }

    /* A page in a frame (_framed)? Roundcube sets output->framed only later, the env right away. */
    private function framed(): bool
    {
        return !empty($this->rc->output->get_env('framed'));
    }

    /* Logging out of Roundcube ends the API session too. */
    public function session_destroy($args)
    {
        if (!empty($_SESSION['ai_agent_token'])) {
            $this->api_call('do_logout', [], true, $_SESSION['ai_agent_token']);
            unset($_SESSION['ai_agent_token']);
        }

        return $args;
    }

    /* A single "AI" item in Settings, subpages are in the list next to it (like Preferences). */
    public function settings_actions($args)
    {
        $args['actions'][] = [
            'action' => 'plugin.ai_agent',
            'class' => 'ai_agent',
            'label' => 'settings_ai',
            'title' => 'settings_ai',
            'domain' => 'ai_agent',
        ];

        return $args;
    }

    /* Settings → AI: list of subpages and the frame they load into. */
    public function action_settings()
    {
        $this->rc->output->set_pagetitle($this->gettext('settings_ai'));
        $this->register_handler('plugin.ai_agent-sections', [$this, 'sections_list']);
        $this->include_script('settings.js');
        $this->rc->output->send('ai_agent.settings');
    }

    public function sections_list($attrib)
    {
        $items = '';
        foreach (['instructions' => 'plugin.ai_agent-instructions', 'log' => 'plugin.ai_agent-log'] as $label => $action) {
            $items .= html::tag('li', ['class' => 'ai_agent-' . $label, 'role' => 'option'],
                html::a(['href' => $this->rc->url(['_action' => $action, '_framed' => 1])], rcube::Q($this->gettext($label))));
        }

        return html::tag('ul', ['id' => $attrib['id'] ?? 'ai-agent-sections', 'class' => $attrib['class'] ?? 'listing',
            'role' => 'listbox'], $items);
    }

    /* ---------------------------------------------------------------------
     *  API
     * ------------------------------------------------------------------- */

    private function api_url(): string
    {
        return rtrim((string) $this->rc->config->get('ai_agent_api_url'), '/') . '/';
    }

    private function chat_url(): string
    {
        $url = (string) $this->rc->config->get('ai_agent_chat_url');

        return $url !== '' ? rtrim($url, '/') . '/' : $this->api_url() . 'v1/';
    }

    /* One API call. Returns [HTTP code, response]; code 0 = API unreachable. */
    private function api_call(string $fn, array $params = [], bool $post = false, string $token = ''): array
    {
        $opts = ['http_errors' => false, 'headers' => ['Accept' => 'application/json']];
        if ($token !== '') {
            $opts['headers']['Authorization'] = 'Bearer ' . $token;
        }
        if ($post) {
            $opts['query'] = ['fn' => $fn];
            $opts['form_params'] = $params;
        } else {
            $opts['query'] = ['fn' => $fn] + $params;
        }

        try {
            $res = $this->rc->get_http_client()->request($post ? 'POST' : 'GET', $this->api_url(), $opts);
        } catch (Exception $e) {
            rcube::raise_error(['code' => 500, 'message' => "ai_agent: API $fn: " . $e->getMessage()], true, false);

            return [0, []];
        }
        $ans = json_decode((string) $res->getBody(), true);

        return [$res->getStatusCode(), is_array($ans) ? $ans : []];
    }

    /* User API token. Without a stored one (or with $renew) logs in with
       the username and password of the Roundcube login. Returns [token, error]. */
    private function token(bool $renew = false): array
    {
        if (!$renew && !empty($_SESSION['ai_agent_token'])) {
            return [$_SESSION['ai_agent_token'], ''];
        }
        unset($_SESSION['ai_agent_token']);

        [$code, $ans] = $this->api_call('do_login', [
            'login' => $this->rc->get_user_name(),
            'password' => $this->rc->get_user_password(),
            'persist' => 0,
        ], true);
        if ($code == 200 && !empty($ans['session'])) {
            $_SESSION['ai_agent_token'] = $ans['session'];

            return [$ans['session'], ''];
        }

        return ['', $this->api_error($code, $ans)];
    }

    /* Call with the user token; on 401 (expired session) logs in again once. */
    private function api(string $fn, array $params = [], bool $post = false): array
    {
        [$token, $err] = $this->token();
        if ($token !== '') {
            [$code, $ans] = $this->api_call($fn, $params, $post, $token);
            if ($code != 401) {
                return [$code, $ans];
            }
            [$token, $err] = $this->token(true);
            if ($token !== '') {
                return $this->api_call($fn, $params, $post, $token);
            }
        }

        return [401, ['error' => $err]];
    }

    private function api_error(int $code, array $ans): string
    {
        if (!empty($ans['error']) && is_string($ans['error'])) {
            return $ans['error'];
        }

        return $code ? $this->gettext(['name' => 'apierror', 'vars' => ['code' => $code]]) : $this->gettext('apidown');
    }

    /* Ajax from the chat after a 401: a new token. */
    public function action_token()
    {
        [$token, $err] = $this->token(true);
        $this->rc->output->command('plugin.ai_agent-token', ['token' => $token, 'error' => $err]);
        $this->rc->output->send();
    }

    /* ---------------------------------------------------------------------
     *  PAGES
     * ------------------------------------------------------------------- */

    /* Mailbox and AI prerequisites. Returns [mailbox, null] or [null, [notice type, text]]. */
    private function mailbox(): array
    {
        [$code, $ans] = $this->api('mail_get_mailbox');
        if ($code != 200 || empty($ans['mailbox'])) {
            return [null, ['error', $this->api_error($code, $ans)]];
        }

        return [$ans['mailbox'], null];
    }

    /* A notice instead of the page content. Settings subpages live in a frame
       (template frame), the chat takes the full screen (template plugin). */
    private function send_notice(string $title, string $type, string $text)
    {
        $class = ['error' => 'boxerror', 'warning' => 'boxwarning'][$type] ?? 'boxinformation';
        if ($this->rc->task == 'settings') {
            $this->send_frame(html::div(['class' => $class], $text));
            return;
        }
        $this->register_handler('plugin.body', function () use ($title, $class, $text) {
            return html::div(['class' => 'boxtitle'], rcube::Q($title))
                . html::div(['class' => 'box formcontainer scroller'],
                    html::div(['class' => 'boxcontent formcontent'], html::div(['class' => $class], $text)));
        });
        $this->rc->output->send('plugin');
    }

    /* A Settings → AI subpage in the frame, same as Preferences sections. */
    private function send_frame(string $content, string $buttons = '')
    {
        $this->register_handler('plugin.body', function () use ($content, $buttons) {
            return html::div('formcontent', $content) . ($buttons !== '' ? html::div('formbuttons', $buttons) : '');
        });
        $this->rc->output->send('ai_agent.frame');
    }

    /* Chat with the assistant and the conversation list. */
    public function action_chat()
    {
        $this->rc->output->set_pagetitle($this->gettext('agent'));

        [$box, $err] = $this->mailbox();
        $ai = $box['ai'] ?? [];
        if ($err) {
            $this->send_notice($this->gettext('agent'), $err[0], rcube::Q($err[1]));
            return;
        }
        if (empty($ai['domain_enabled'])) {
            $this->send_notice($this->gettext('agent'), 'warning', $this->text_notpaid($box));
            return;
        }
        if (empty($ai['enable'])) {
            $this->send_notice($this->gettext('agent'), 'warning', $this->text_notactive($box));
            return;
        }

        // In a window over the mail ("AI write", "AI reply", "AI spell check"): the chat alone, of that type.
        $mode = (string) rcube_utils::get_input_string('_ai_type', rcube_utils::INPUT_GET);
        $ref = null;
        if (!$this->framed() || !in_array($mode, ['create', 'reply', 'spellcheck'], true)) {
            $mode = 'live';
        } elseif ($mode == 'reply') {
            $ref = $this->message_info((string) rcube_utils::get_input_string('_mbox', rcube_utils::INPUT_GET, true),
                (int) rcube_utils::get_input_string('_uid', rcube_utils::INPUT_GET));
            if ($ref['error'] !== '') {
                $this->send_notice($this->gettext('agent'), 'error', rcube::Q($ref['error']));
                return;
            }
        }

        $this->add_texts('localization/', true);
        $this->include_script('js/marked.min.js');
        $this->include_script('js/purify.min.js');
        $this->include_script('ai_agent.js');
        $this->rc->output->set_env('ai_agent', [
            'api' => $this->api_url(),
            'chat' => $this->chat_url(),
            'model' => (string) $this->rc->config->get('ai_agent_model', 'mail-ai'),
            'token' => $_SESSION['ai_agent_token'] ?? '',
            'mode' => $mode,
            'ref' => $ref,
        ]);
        $this->rc->output->send($mode == 'live' ? 'ai_agent.chat' : 'ai_agent.chatframe');
    }

    /* A message by folder (UTF-8 from the agent, or UTF7-IMAP from Roundcube)
       and UID. Returns [mbox (UTF7-IMAP), folder, uid, subject, error]; the
       error goes back to the model as a tool result, hence English. */
    private function message_info(string $folder, int $uid): array
    {
        $storage = $this->rc->get_storage();
        $res = ['mbox' => '', 'folder' => $folder, 'uid' => $uid, 'subject' => '', 'error' => ''];

        $mbox = null;
        foreach (array_unique([rcube_charset::convert($folder, 'UTF-8', 'UTF7-IMAP'), $folder]) as $name) {
            if ($name !== '' && $storage->folder_exists($name)) {
                $mbox = $name;
                break;
            }
        }
        if ($folder === '' || $uid <= 0) {
            $res['error'] = 'folder and uid are required';
        } elseif ($mbox === null) {
            $res['error'] = "folder \"$folder\" does not exist";
        } elseif (!($h = $storage->get_message_headers($uid, $mbox))) {
            $res['error'] = "message $uid does not exist in folder \"$folder\" (moved or deleted?)";
        } else {
            $res['mbox'] = $mbox;
            $res['folder'] = rcube_charset::convert($mbox, 'UTF7-IMAP');
            $res['subject'] = rcube_mime::decode_header((string) $h->subject, $h->charset);
        }

        return $res;
    }

    /* Ajax from the chat before a tool opens a message: does it exist? */
    public function action_check()
    {
        $res = $this->message_info((string) rcube_utils::get_input_string('_mbox', rcube_utils::INPUT_POST, true),
            (int) rcube_utils::get_input_string('_uid', rcube_utils::INPUT_POST));
        $res['id'] = (string) rcube_utils::get_input_string('_id', rcube_utils::INPUT_POST);
        $this->rc->output->command('plugin.ai_agent-checked', $res);
        $this->rc->output->send();
    }

    /* Ajax from the chat: a draft for the compose editor (mail_create_with,
       mail_reply_with). Kept in the session, the hooks below fill it into the
       editor opened by the returned URL. Nothing is sent; no Bcc. */
    public function action_draft()
    {
        $in = static fn ($name) => (string) rcube_utils::get_input_string($name, rcube_utils::INPUT_POST, true);
        $list = static function ($name) use ($in) {
            $v = json_decode($in($name), true);
            return is_array($v) ? implode(', ', array_filter(array_map('trim', array_filter($v, 'is_string')))) : '';
        };
        $res = ['id' => $in('_id'), 'error' => '', 'url' => ''];
        $draft = ['to' => $list('_to'), 'cc' => $list('_cc'), 'subject' => trim($in('_subject')),
            'body' => str_replace("\r\n", "\n", $in('_body'))];
        $url = ['_task' => 'mail', '_action' => 'compose'];

        if ($in('_kind') == 'reply') {
            $msg = $this->message_info($in('_mbox'), (int) $in('_uid'));
            $res['error'] = $msg['error'];
            $url += ['_reply_uid' => $msg['uid'], '_mbox' => $msg['mbox']];
        } elseif ($draft['to'] === '') {
            $res['error'] = 'to is required';
        }
        if ($res['error'] === '') {
            $drafts = array_slice($_SESSION['ai_agent_drafts'] ?? [], -9, null, true);   // the last ten are enough
            $did = bin2hex(random_bytes(8));
            $drafts[$did] = $draft;
            $_SESSION['ai_agent_drafts'] = $drafts;
            $res['url'] = $this->rc->url($url + ['_ai_draft' => $did]);
        }

        $this->rc->output->command('plugin.ai_agent-checked', $res);
        $this->rc->output->send();
    }

    /* Compose opened with _ai_draft: recipients and subject go to the compose
       parameters (they take precedence over the reply defaults), the body is
       kept for message_compose_body under the compose ID. */
    public function message_compose($args)
    {
        $did = (string) ($args['param']['ai_draft'] ?? '');
        $draft = $_SESSION['ai_agent_drafts'][$did] ?? null;
        if ($draft) {
            foreach (['to', 'cc', 'subject'] as $f) {
                if ($draft[$f] !== '') {
                    $args['param'][$f] = $draft[$f];
                }
            }
            $_SESSION['ai_agent_compose'][$args['id']] = $draft['body'];
            unset($_SESSION['ai_agent_drafts'][$did]);
        }

        return $args;
    }

    /* The draft's text into the editor: for a new message the whole body, for
       a reply above or below the quote according to reply_mode. */
    public function message_compose_body($args)
    {
        $cid = (string) rcube_utils::get_input_string('_id', rcube_utils::INPUT_GET);
        if (!isset($_SESSION['ai_agent_compose'][$cid])) {
            return $args;
        }
        $text = $_SESSION['ai_agent_compose'][$cid];
        unset($_SESSION['ai_agent_compose'][$cid]);
        if (trim($text) === '') {
            return $args;
        }

        $add = $args['html'] ? '<div>' . nl2br(rcube::Q($text, 'strict', false)) . '</div><br>' : $text . "\n\n";
        $body = (string) $args['body'];
        if ($args['mode'] == rcmail_sendmail::MODE_REPLY && trim($body) !== '' && (int) $this->rc->config->get('reply_mode') != 1) {
            $args['body'] = $body . ($args['html'] ? '' : "\n") . $add;    // below the quote (bottom posting)
        } else {
            $args['body'] = $add . $body;
        }

        return $args;
    }

    private function text_notpaid(array $box): string
    {
        return html::p([], $this->gettext(['name' => 'notpaid', 'vars' => ['address' => rcube::Q($box['address'] ?? '')]]))
            . html::p([], rcube::Q($this->gettext('notpaid_more')));
    }

    private function text_notactive(array $box): string
    {
        return html::p([], $this->gettext(['name' => 'notactive', 'vars' => ['address' => rcube::Q($box['address'] ?? '')]]))
            . html::p([], rcube::Q($this->gettext('notactive_more')));
    }

    /* Settings → AI agent instructions: switches, task instructions, memory. */
    public function action_instructions()
    {
        $this->rc->output->set_pagetitle($this->gettext('instructions'));

        [$box, $err] = $this->mailbox();
        if ($err) {
            $this->send_notice($this->gettext('instructions'), $err[0], rcube::Q($err[1]));
            return;
        }

        if ($this->rc->action == 'plugin.ai_agent-instructions-save') {
            $box = $this->save_instructions($box);
            $this->rc->overwrite_action('plugin.ai_agent-instructions');
        }

        [$form, $buttons] = $this->form_instructions($box);
        $this->send_frame($form, $buttons);
    }

    private function save_instructions(array $box): array
    {
        $ai = $box['ai'] ?? [];
        $norm = static fn ($s) => trim(str_replace("\r\n", "\n", (string) $s));
        $in = static fn ($name) => (string) rcube_utils::get_input_string($name, rcube_utils::INPUT_POST, true);

        $p = ['fullname' => trim($in('_fullname'))];
        if (!empty($ai['domain_enabled'])) {
            foreach (self::TYPES as $t => [, $sw]) {
                $p[$sw] = $in("_{$sw}") === '1' ? '1' : '0';
                // unchanged default instructions are not stored — empty = the default applies
                $txt = $norm($in("_ai_{$t}_instructions"));
                $p["ai_{$t}_instructions"] = $txt === $norm($ai['instructions'][$t]['default'] ?? '') ? '' : $txt;
            }
            foreach (self::STYLE_TYPES as $t => $_) {
                if (isset($ai['instructions'][$t])) {          // only what the API offers
                    $txt = $norm($in("_ai_{$t}_instructions"));
                    $p["ai_{$t}_instructions"] = $txt === $norm($ai['instructions'][$t]['default'] ?? '') ? '' : $txt;
                }
            }
            $p['ai_memory'] = str_replace("\r\n", "\n", $in('_ai_memory'));
        }

        [$code, $ans] = $this->api('mail_store_mailbox', $p, true);
        if ($code == 200) {
            $this->rc->output->show_message('successfullysaved', 'confirmation');

            return $ans['mailbox'] ?? $box;
        }
        $this->rc->output->show_message(rcube::Q($this->api_error($code, $ans)), 'error');

        return $box;
    }

    /* Returns [form, buttons]. */
    private function form_instructions(array $box): array
    {
        $ai = $box['ai'] ?? [];
        $domAi = !empty($ai['domain_enabled']);
        $mb = static fn ($b) => $b === null ? '—' : number_format($b / 1048576, 1, ',', ' ') . ' MB';

        // Mailbox
        $t = new html_table(['cols' => 2, 'class' => 'propform']);
        $t->add('title', html::label('ai-agent-fullname', rcube::Q($this->gettext('fullname'))));
        $t->add(null, (new html_inputfield(['name' => '_fullname', 'id' => 'ai-agent-fullname', 'maxlength' => 100]))
                ->show((string) ($box['fullname'] ?? ''))
            . html::div('hint', rcube::Q($this->gettext('fullname_hint'))));
        $t->add('title', rcube::Q($this->gettext('login')));
        $t->add(null, rcube::Q((string) ($box['login'] ?? ''))
            . html::div('hint', rcube::Q($this->gettext('password_hint'))));
        $t->add('title', rcube::Q($this->gettext('used')));
        $t->add(null, $mb($box['quota_used_bytes'] ?? null)
            . (!empty($box['quota']) ? ' / ' . $mb($box['quota']) : ''));
        $t->add('title', rcube::Q($this->gettext('messages')));
        $t->add(null, isset($box['quota_used_messages']) ? number_format((int) $box['quota_used_messages'], 0, ',', ' ') : '—');
        $out = html::tag('fieldset', [], html::tag('legend', [], rcube::Q($this->gettext('mailbox'))) . $t->show());

        // AI mail
        $ai_html = '';
        if (!$domAi) {
            $ai_html .= html::div('boxwarning', $this->text_notpaid($box));
        } elseif (empty($ai['enable'])) {
            $ai_html .= html::div('boxwarning', $this->text_notactive($box));
        }
        $ai_html .= html::div('boxinformation', rcube::Q($this->gettext('pipeline')));

        $t = new html_table(['cols' => 2, 'class' => 'propform']);
        $t->add('title', rcube::Q($this->gettext('enable')));
        $t->add(null, (new html_checkbox(['disabled' => true, 'value' => '1']))->show(!empty($ai['enable']) ? '1' : '')
            . html::div('hint', rcube::Q($this->gettext('enable_hint'))));
        foreach (self::TYPES as $type => [$label, $sw]) {
            $id = 'ai-agent-' . $type;
            $val = (string) ($ai['instructions'][$type]['value'] ?? '') ?: (string) ($ai['instructions'][$type]['default'] ?? '');
            $t->add('title', html::label($id, rcube::Q($this->gettext($label))));
            $t->add(null, (new html_checkbox(['name' => "_{$sw}", 'id' => $id, 'value' => '1', 'disabled' => !$domAi]))
                    ->show(!empty($ai[substr($sw, 3)]) ? '1' : '')
                . (new html_textarea(['name' => "_ai_{$type}_instructions", 'rows' => 5, 'disabled' => !$domAi,
                    'placeholder' => $this->gettext('default_task')]))->show($val)
                . $this->schedule_hint($ai, $type));
        }
        foreach (self::STYLE_TYPES as $type => $label) {
            if (!isset($ai['instructions'][$type])) {
                continue;
            }
            $id = 'ai-agent-' . $type;
            $val = (string) ($ai['instructions'][$type]['value'] ?? '') ?: (string) ($ai['instructions'][$type]['default'] ?? '');
            $t->add('title', html::label($id, rcube::Q($this->gettext($label))));
            $t->add(null, (new html_textarea(['name' => "_ai_{$type}_instructions", 'id' => $id, 'rows' => 3, 'disabled' => !$domAi,
                    'placeholder' => $this->gettext('default_task')]))->show($val)
                . html::div('hint', rcube::Q($this->gettext($label . '_hint'))));
        }
        $t->add('title', '');
        $t->add(null, html::div('hint', rcube::Q($this->gettext('default_hint'))));
        $t->add('title', html::label('ai-agent-memory', rcube::Q($this->gettext('memory'))));
        $t->add(null, (new html_textarea(['name' => '_ai_memory', 'id' => 'ai-agent-memory', 'rows' => 10, 'disabled' => !$domAi,
                'class' => 'monospace', 'placeholder' => $this->gettext('memory_empty')]))->show((string) ($ai['memory'] ?? ''))
            . html::div('hint', rcube::Q($this->gettext('memory_hint'))));
        $out .= html::tag('fieldset', [], html::tag('legend', [], rcube::Q($this->gettext('ai'))) . $ai_html . $t->show());

        $form = $this->rc->output->form_tag([
            'id' => 'ai-agent-form',
            'method' => 'post',
            'action' => $this->rc->url(['_action' => 'plugin.ai_agent-instructions-save', '_framed' => 1]),
        ], $out);
        // the button is outside the form (frame footer), hence the form= binding
        $button = html::tag('button', ['type' => 'submit', 'form' => 'ai-agent-form', 'class' => 'btn btn-primary submit'],
            rcube::Q($this->rc->gettext('save')));

        return [$form, $button];
    }

    /* When a summary last ran and runs next; empty for other types. */
    private function schedule_hint(array $ai, string $type): string
    {
        if (!array_key_exists("{$type}_next_run", $ai)) {
            return '';
        }
        // as given by the API, without time zone conversion (like the log):
        // the API sends the server's local time without a zone
        $fmt = function ($d) {
            $ts = strtotime((string) $d);
            return rcube::Q($this->rc->gettext(strtolower(date('D', $ts))) . ' ' . date('j. n. Y H:i', $ts));
        };
        $parts = [];
        if (!empty($ai['enable_' . $type])) {
            $parts[] = rcube::Q($this->gettext('next_run')) . ': '
                . ($ai["{$type}_next_run"] ? $fmt($ai["{$type}_next_run"]) : rcube::Q($this->gettext('next_run_soon')));
        }
        if (!empty($ai["{$type}_last_run"])) {
            $parts[] = rcube::Q($this->gettext('last_run')) . ': ' . $fmt($ai["{$type}_last_run"]);
        }
        return $parts ? html::div('hint', implode(' · ', $parts)) : '';
    }

    /* Settings → AI log: tasks of this mailbox by month, token usage. */
    public function action_log()
    {
        $this->rc->output->set_pagetitle($this->gettext('log'));

        $page = max(0, (int) rcube_utils::get_input_string('_p', rcube_utils::INPUT_GET));
        [$code, $tasks] = $this->api('ai_list_tasks', ['limit' => self::LOG_LIMIT, 'offset' => $page * self::LOG_LIMIT]);
        if ($code == 200) {
            [$code, $usage] = $this->api('ai_usage_months', ['months' => 24]);
        }
        if ($code != 200) {
            // the error is in the response of the call that failed
            $this->send_notice($this->gettext('log'), 'error', rcube::Q($this->api_error($code, $usage ?? $tasks)));
            return;
        }

        $this->send_frame($this->log_body($tasks, $usage, $page));
    }

    private function log_body(array $tasks, array $usage, int $page): string
    {
        // month => tasks; months with usage only (all tasks deleted) too
        $byMonth = [];
        foreach ($tasks['tasks'] ?? [] as $t) {
            $byMonth[substr((string) $t['created'], 0, 7)][] = $t;
        }
        $sums = array_column($usage['months'] ?? [], null, 'month');
        if ($page === 0) {
            foreach ($sums as $m => $_) {
                $byMonth[$m] ??= [];
            }
        }
        krsort($byMonth);

        $num = static fn ($n) => $n === null ? '—' : number_format((int) $n, 0, ',', ' ');
        $states = ['error' => ['state_error', 'danger'], 'skipped' => ['state_skipped', 'secondary'],
            'new' => ['state_new', 'info'], 'running' => ['state_running', 'info']];   // done = plain text
        $total = (int) ($tasks['total'] ?? 0);

        $out = html::div('boxinformation', rcube::Q($this->gettext(['name' => 'log_intro', 'vars' => ['n' => $this->count_tasks($total)]])));
        if (!$byMonth) {
            $out .= html::p([], rcube::Q($this->gettext('log_empty')));
        }

        foreach ($byMonth as $m => $list) {
            [$y, $mm] = array_map('intval', explode('-', $m) + [1 => 0]);
            $month = $mm >= 1 && $mm <= 12 ? $this->rc->gettext('long' . strtolower(date('M', mktime(0, 0, 0, $mm, 1)))) : $m;

            $t = new html_table(['cols' => 7, 'class' => 'table table-sm table-hover ai-agent-log']);
            foreach (['col_when', 'col_type', 'col_state', 'col_folder', 'col_in', 'col_out', 'col_result'] as $i => $h) {
                $t->add_header($i >= 4 && $i <= 5 ? 'num' : null, rcube::Q($this->gettext($h)));
            }
            if (!$list) {
                $t->add(['colspan' => 7, 'class' => 'hint'], rcube::Q($this->gettext('log_deleted')));
            }
            foreach ($list as $task) {
                $st = $states[$task['state']] ?? null;
                $t->add('nowrap', rcube::Q(date('j. n. H:i', strtotime((string) $task['created']))));
                $t->add('nowrap', rcube::Q($this->gettext('logtype_' . $task['type'])));
                $t->add(null, $st ? html::span('badge badge-' . $st[1], rcube::Q($this->gettext($st[0]))) : rcube::Q($this->gettext('state_done')));
                $t->add('small', rcube::Q((string) $task['mailbox']));
                $t->add('num', $num($task['tokens_in']));
                $t->add('num', $num($task['tokens_out']));
                $t->add('result', rcube::Q((string) ($task['error'] ?: $task['result'])));
            }

            $foot = '';
            if ($s = $sums[$m] ?? null) {
                $parts = [];
                foreach (['incoming' => 'sum_incoming', 'summary' => 'sum_summary', 'live' => 'sum_live'] as $k => $lbl) {
                    if (!empty($s[$k]['tasks'])) {
                        $parts[] = $this->gettext($lbl) . ' ' . $s[$k]['tasks'] . '× ' . $num($s[$k]['tokens_in']) . ' / ' . $num($s[$k]['tokens_out']);
                    }
                }
                $foot = html::p('hint', rcube::Q($this->gettext(['name' => 'log_sum', 'vars' => [
                    'n' => $this->count_tasks((int) $s['total']['tasks']),
                    'in' => $num($s['total']['tokens_in']),
                    'out' => $num($s['total']['tokens_out']),
                ]])) . ($parts ? ' — ' . rcube::Q(implode(', ', $parts)) : ''));
            }

            $out .= html::tag('fieldset', [],
                html::tag('legend', [], rcube::Q("$month $y") . ' ' . html::span('badge badge-secondary', (string) count($list)))
                . html::div('table-responsive', $t->show()) . $foot);
        }

        $nav = '';
        if ($page > 0) {
            $nav .= html::a(['class' => 'button', 'href' => $this->rc->url(['_action' => 'plugin.ai_agent-log', '_p' => $page - 1, '_framed' => 1])],
                rcube::Q($this->gettext('newer')));
        }
        if ($total > ($page + 1) * self::LOG_LIMIT) {
            $nav .= ' ' . html::a(['class' => 'button', 'href' => $this->rc->url(['_action' => 'plugin.ai_agent-log', '_p' => $page + 1, '_framed' => 1])],
                rcube::Q($this->gettext('older')));
        }
        if ($nav) {
            $out .= html::p([], $nav);
        }

        return $out;
    }

    /* "1 task / 3 tasks / 5 tasks" by the language's plural rules: labels
       tasks_1, tasks_2 (2–4) and tasks_5 (the rest) — Czech needs all three. */
    private function count_tasks(int $n): string
    {
        $form = $n == 1 ? 'tasks_1' : ($n >= 2 && $n <= 4 ? 'tasks_2' : 'tasks_5');

        return $this->gettext(['name' => $form, 'vars' => ['n' => number_format($n, 0, ',', ' ')]]);
    }
}
