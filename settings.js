/* Settings → AI: a subpage from the list loads into the frame (like Preferences sections). */
window.rcmail && rcmail.addEventListener('init', function () {
    var links = $('#ai-agent-sections a');

    links.on('click', function (ev) {
        ev.preventDefault();
        links.closest('li').removeClass('selected');
        $(this).closest('li').addClass('selected');
        rcmail.show_contentframe(true);
        rcmail.location_href(this.href, rcmail.get_frame_window(rcmail.env.contentframe), true);
    });
});
