<?php
if (wp_get_environment_type() !== 'local') {
    throw new RuntimeException('Only an isolated local WordPress is supported');
}
(new DzenChat\Credentials())->save(['client_id' => 'chatid-fixture',
    'client_secret' => 'fixture-secret-for-tests-only-123456789', 'integration_id' => 'integration-fixture',
    'site_url' => trailingslashit(home_url()), 'project' => ['id' => 'project-fixture']]);
update_option('dzen_fixture_scenario', 'normal', false);
update_option('dzen_fixture_visibility', ['hidden' => false, 'version' => 1], false);
$api = new DzenChat\Api(new DzenChat\Credentials());
$result = $api->status();
if (is_wp_error($result)) {
    throw new RuntimeException($result->get_error_message());
}
if (!username_exists('dzen_subscriber')) {
    $id = wp_create_user('dzen_subscriber', 'local-subscriber-test-8868', 'subscriber-fixture@example.org');
    (new WP_User($id))->set_role('subscriber');
}
echo "Isolated API fixture connected.\n";
