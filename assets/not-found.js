/* Offer help only when the selected Dzen Chat widget has loaded on a 404 page. */
(function () {
    'use strict';
    var invitation = document.getElementById('dzen-chat-not-found');
    var launcher = document.getElementById('chat-widget-button');
    if (!invitation || !launcher) return;

    var dismissed = false;
    function sync() {
        if (launcher.getAttribute('aria-expanded') === 'true') dismissed = true;
        invitation.hidden = dismissed;
    }
    function dismiss() {
        dismissed = true;
        sync();
        launcher.focus();
    }
    invitation.querySelector('.dzen-chat-not-found-dismiss').addEventListener('click', dismiss);
    invitation.querySelector('.dzen-chat-not-found-open').addEventListener('click', function () {
        // Use the service's launcher without changing the conversation or sending a message.
        if (launcher.getAttribute('aria-expanded') !== 'true') launcher.click();
        sync();
        launcher.focus();
    });
    invitation.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') dismiss();
    });
    new MutationObserver(sync).observe(launcher, { attributes: true, attributeFilter: ['aria-expanded'] });
    sync();
})();
