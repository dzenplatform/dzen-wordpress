<?php
if (wp_get_environment_type() !== 'local' || !function_exists('dzen_fixture_widgets')) {
    throw new RuntimeException('Only an isolated local WordPress is supported');
}
$credentials = new DzenChat\Credentials();
$credentials->saveRegistration(['client_id' => 'chatid-fixture-12345',
    'client_secret' => 'fixture-secret-for-tests-only-123456789', 'site_source_id' => 'source-one'],
    DzenChat\Credentials::siteUrl(), DzenChat\Api::origin());
update_option('dzen_fixture_scenario', 'normal', false);
$api = new DzenChat\Api(new DzenChat\Credentials());
$result = $api->items('/sources');
if (is_wp_error($result)) {
    throw new RuntimeException($result->get_error_message());
}
if (!username_exists('dzen_subscriber')) {
    $id = wp_create_user('dzen_subscriber', 'local-subscriber-test-8868', 'subscriber-fixture@example.org');
    (new WP_User($id))->set_role('subscriber');
}
echo "Isolated API fixture connected.\n";
