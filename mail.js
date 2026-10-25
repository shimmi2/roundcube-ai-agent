/* =====================================================================
 *  mail.js — "AI write" and "AI reply" in the mail toolbar, "AI spell
 *  check" in the compose toolbar.
 *
 *  All three open the chat (task aiagent, framed) in a window over the mail.
 *  The user describes what and how to write; the conversation is of type
 *  create or reply, the agent prepares a draft with a tool and the chat
 *  then switches the whole page to the compose editor. Nothing is sent.
 *  The conversation stays in the chat history. The spell check sends the
 *  editor's content by itself and puts the corrected text back into the
 *  editor (parent.rcmail.editor); it is not in the chat list.
 * ===================================================================== */
window.rcmail && rcmail.addEventListener('init', function () {
    'use strict';

    if (rcmail.task != 'mail') {
        return;
    }

    function dialog(type, q) {
        var frame = $('<iframe>').attr({
            src: rcmail.url('aiagent/index', $.extend({ _framed: 1, _ai_type: type }, q || {})),
            frameborder: 0
        });
        var title = { reply: 'ai_reply_title', create: 'ai_write_title', spellcheck: 'ai_spellcheck_title' }[type];
        rcmail.show_popup_dialog(frame, rcmail.gettext(title, 'ai_agent'), [],
            { width: 720, height: 620, dialogClass: 'ai-agent-dialog' });
    }

    if (rcmail.env.action == 'compose') {
        rcmail.register_command('plugin.ai_agent-spellcheck', function () { dialog('spellcheck'); }, true);
        return;
    }

    rcmail.register_command('plugin.ai_agent-write', function () { dialog('create'); }, true);
    rcmail.register_command('plugin.ai_agent-reply', function () {
        var uid = rcmail.get_single_uid();
        if (uid) dialog('reply', { _mbox: rcmail.get_message_mailbox(uid), _uid: String(uid).split('-')[0] });
    }, !!rcmail.env.uid);

    // in the list: only with one selected message
    if (rcmail.message_list) {
        rcmail.message_list.addEventListener('select', function (list) {
            rcmail.enable_command('plugin.ai_agent-reply', list.get_single_selection() !== null);
        });
    }
});
