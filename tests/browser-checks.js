async (page) => {
  const base = 'http://127.0.0.1:8868';
  const history = base + '/wp-admin/admin.php?page=dzen-chat-history';
  const passed = [];
  const check = (value, label) => { if (!value) throw new Error(label); passed.push(label); };
  // Run after signing into the isolated fixture as dzen_test.
  await page.goto(history + '&visibility=all');
  if (await page.getByRole('button', {name: 'Вернуть в список', exact: true}).isVisible()) {
    await page.getByRole('button', {name: 'Вернуть в список', exact: true}).click();
  }
  await page.goto(history);
  await page.getByRole('button', {name: 'Скрыть', exact: true}).click();
  check((await page.locator('.dzen-chat').innerText()).includes('Чат скрыт'), 'hide acknowledged by server');
  check(await page.getByRole('link', {name: 'Вопрос о доставке', exact: true}).count() === 0, 'hidden chat removed from visible list');
  await page.getByRole('combobox', {name: /^Видимость/}).selectOption('hidden');
  await page.getByRole('searchbox', {name: 'Поиск', exact: true}).fill('доставке');
  await page.getByRole('combobox', {name: /^Виджет/}).selectOption('widget-one');
  await page.getByRole('button', {name: 'Применить', exact: true}).click();
  check(await page.getByRole('link', {name: 'Вопрос о доставке', exact: true}).count() === 1, 'hidden search and widget filter');
  await page.getByRole('link', {name: 'Вопрос о доставке', exact: true}).click();
  check(await page.locator('.dzen-message').count() === 2, 'hidden chat messages remain readable');
  check(await page.locator('.dzen-chat button').filter({hasText: /Удалить/}).count() === 0, 'no chat delete control');
  await page.getByRole('link', {name: 'Показать в WordPress', exact: true}).first().click();
  check(await page.evaluate(() => new URL(location.href).searchParams.get('message_id')) === 'msg-answer', 'source URL uses a non-reserved message parameter');
  await page.reload();
  check(await page.locator('.dzen-source').count() === 1, 'source survives WordPress canonical URL cleanup and reload');
  check((await page.locator('.dzen-text').innerText()).includes('Изменить адрес можно'), 'source excerpt rendered inside WordPress');
  check(await page.evaluate(() => typeof window.sourceXss === 'undefined'), 'source script never executed');
  check(await page.locator('.dzen-chat a[href^="javascript:"]').count() === 0, 'unsafe external links absent');
  const external = page.getByRole('link', {name: 'Открыть оригинал ↗', exact: true});
  check(await external.getAttribute('href') === 'https://example.org/shipping', 'original source URL available');
  check(await external.getAttribute('target') === '_blank' && (await external.getAttribute('rel')).includes('noopener'), 'external source opens safely in another tab');
  check(!(await page.content()).includes('fixture-secret-for-tests-only'), 'API secret absent from HTML');
  await page.screenshot({path: 'output/playwright/source-in-wordpress.png', fullPage: true});
  const csrf = await page.request.post(base + '/wp-admin/admin-post.php', {form: {
    action: 'dzen_chat_visibility', chat_id: 'chat-one', hidden: '0', expected_version: '1'
  }});
  check(csrf.status() === 403, 'missing CSRF nonce rejected');
  await page.goto(history + '&chat=chat-one&message_id=msg-answer&reference_id=missing-ref');
  check((await page.locator('.dzen-source').innerText()).includes('Источник удалён'), 'missing source has an explicit state');
  check(await page.locator('.dzen-source .dzen-external').count() === 0, 'missing source has no external link');
  await page.getByRole('button', {name: 'Вернуть в список', exact: true}).click();
  check((await page.locator('.dzen-chat').innerText()).includes('Чат возвращён'), 'restore acknowledged by server');
  await page.goto(history);
  check(await page.getByRole('link', {name: 'Вопрос о доставке', exact: true}).count() === 1, 'restored chat visible');
  await page.screenshot({path: 'output/playwright/chat-history.png', fullPage: true});
  await page.goto(history + '&date_from=2027-01-01');
  check(await page.getByRole('link', {name: 'Вопрос о доставке', exact: true}).count() === 0, 'date filter applied');
  const context = await page.context().browser().newContext();
  try {
    const subscriber = await context.newPage();
    await subscriber.goto(base + '/wp-login.php');
    await subscriber.getByRole('textbox', {name: 'Username or Email Address', exact: true}).fill('dzen_subscriber');
    await subscriber.getByRole('textbox', {name: 'Password', exact: true}).fill('local-subscriber-test-8868');
    await subscriber.getByRole('button', {name: 'Log In', exact: true}).click();
    const denied = await subscriber.goto(history);
    check(denied.status() === 403, 'subscriber denied conversation access');
    const deniedWrite = await subscriber.request.post(base + '/wp-admin/admin-post.php', {form: {
      action: 'dzen_chat_visibility', chat_id: 'chat-one', hidden: '1', expected_version: '1'
    }});
    check(deniedWrite.status() === 403, 'subscriber denied visibility update');
  } finally { await context.close(); }
  await page.goto(history);
  return {passed: passed.length, checks: passed};
}
