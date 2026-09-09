// Local contract fixture, deliberately labelled. No AI calls or chat persistence.
(function (config) {
    // Match the real embed's required mount point; never create it in the fixture.
    var container = document.getElementById('chat-chat');
    if (!container) {
        throw new Error('Chat: Container #chat-chat not found.');
    }
    if (container.querySelector('[data-dzen-fixture-launcher]')) return;

    var root = document.createElement('div');
    root.style.cssText = 'position:fixed;right:24px;bottom:24px;z-index:99999;font:15px/1.5 system-ui;color:#173e43;';
    var panel = document.createElement('section');
    panel.id = 'dzen-fixture-panel';
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-label', 'Dzen Chat — локальный тест');
    panel.style.cssText = 'width:min(350px,calc(100vw - 48px));box-sizing:border-box;background:#fff;border:1px solid #a5cebf;border-radius:16px;padding:24px;margin-bottom:12px;box-shadow:0 12px 40px #173e432b;';
    panel.hidden = true;
    var title = document.createElement('strong');
    title.textContent = config.name;
    panel.appendChild(title);
    config.welcome.forEach(function (text) {
        var welcome = document.createElement('p');
        welcome.textContent = text;
        panel.appendChild(welcome);
    });
    var note = document.createElement('p');
    note.textContent = 'Локальный тест интерфейса. Реальный Dzen Chat не подключён. Здесь можно проверить показ и настройки виджета; ответы ИИ недоступны.';
    note.style.cssText = 'font-size:13px;color:#52676a;';
    panel.appendChild(note);
    var launcher = document.createElement('button');
    launcher.type = 'button';
    launcher.setAttribute('data-dzen-fixture-launcher', '');
    launcher.setAttribute('aria-controls', panel.id);
    launcher.setAttribute('aria-expanded', 'false');
    launcher.textContent = 'Dzen Chat · тест';
    launcher.style.cssText = 'display:block;margin-left:auto;background:#b4f078;color:#173e43;border:1px solid #a5cebf;border-radius:32px;padding:16px 22px;font:600 15px system-ui;cursor:pointer;box-shadow:0 8px 24px #173e4333;';
    function close() {
        panel.hidden = true;
        launcher.setAttribute('aria-expanded', 'false');
        launcher.focus();
    }
    launcher.addEventListener('click', function () {
        panel.hidden = !panel.hidden;
        launcher.setAttribute('aria-expanded', String(!panel.hidden));
    });
    root.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !panel.hidden) close();
    });
    root.append(panel, launcher);
    container.appendChild(root);
})(/* DZEN_FIXTURE_CONFIG */);
