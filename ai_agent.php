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

        $this->add_hook('startup', [$this, 'startup']);
        $this->add_hook('session_destroy', [$this, 'session_destroy']);
    }

    /* Main menu button (at the end, where Roundcube puts it). */
    public function startup($args)
    {
        if (!$this->rc->output->framed) {
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

        return $args;
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

        $this->add_texts('localization/', true);
        $this->include_script('js/marked.min.js');
        $this->include_script('js/purify.min.js');
        $this->include_script('ai_agent.js');
        $this->rc->output->set_env('ai_agent', [
            'api' => $this->api_url(),
            'chat' => $this->chat_url(),
            'model' => (string) $this->rc->config->get('ai_agent_model', 'mail-ai'),
            'token' => $_SESSION['ai_agent_token'] ?? '',
        ]);
        $this->rc->output->send('ai_agent.chat');
    }

    /* Ajax from the chat before a tool opens a message: does it exist? The
       agent names folders in UTF-8, Roundcube in UTF7-IMAP; both are tried.
       The error goes back to the model as the tool result, hence English. */
    public function action_check()
    {
        $id = (string) rcube_utils::get_input_string('_id', rcube_utils::INPUT_POST);
        $folder = (string) rcube_utils::get_input_string('_mbox', rcube_utils::INPUT_POST, true);
        $uid = (int) rcube_utils::get_input_string('_uid', rcube_utils::INPUT_POST);
        $storage = $this->rc->get_storage();
        $res = ['id' => $id, 'error' => '', 'mbox' => '', 'subject' => ''];

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
            $res['subject'] = rcube_mime::decode_header((string) $h->subject, $h->charset);
        }

        $this->rc->output->command('plugin.ai_agent-checked', $res);
        $this->rc->output->send();
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
                    'placeholder' => $this->gettext('default_task')]))->show($val));
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
