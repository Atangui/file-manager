<?php
/**
 * In-process REST tests. Usage: php tests/rest-smoke.php /path/to/wordpress
 *
 * Runs against a disposable WordPress install with Filora active.
 *
 * @package Filora
 */

// phpcs:ignoreFile

$_SERVER['HTTP_HOST']   = 'localhost:8080';
$_SERVER['REQUEST_URI'] = '/';
require $argv[1] . '/wp-load.php';

$failures = 0;
$passes   = 0;
function ok( $cond, $label ) {
	global $failures, $passes;
	if ( $cond ) {
		$passes++;
		echo "  \033[32m✓\033[0m {$label}\n";
	} else {
		$failures++;
		echo "  \033[31m✗ {$label}\033[0m\n";
	}
}
function req( $method, $route, $params = array(), $json = null ) {
	$r = new WP_REST_Request( $method, '/filora/v1' . $route );
	if ( 'GET' === $method ) {
		$r->set_query_params( $params );
	} else {
		$r->set_body_params( $params );
	}
	if ( null !== $json ) {
		$r->set_header( 'Content-Type', 'application/json' );
		$r->set_body( wp_json_encode( $json ) );
	}
	$res = rest_do_request( $r );
	return array( $res->get_status(), $res->get_data() );
}
function as_user( $id ) {
	wp_set_current_user( $id );
	Filora\Access::flush();
}
function section( $t ) {
	echo "\n\033[1m{$t}\033[0m\n";
}

do_action( 'rest_api_init' );
$admin = get_user_by( 'login', 'admin' );
as_user( $admin->ID );

// Reset settings and fixtures from previous runs.
Filora\Settings::update( Filora\Settings::defaults() );
Filora\Access::flush();
$vault_dir = Filora\Storage::vault();
foreach ( array( $vault_dir . '/contract.txt', $vault_dir . '/contract-v2.txt', wp_upload_dir()['basedir'] . '/clients' ) as $fixture ) {
	Filora\Fs::delete_path( $fixture );
}
$old_editor = get_user_by( 'login', 'editor1' );
if ( $old_editor ) {
	$GLOBALS['wpdb']->delete( Filora\Installer::table( 'shares' ), array( 'created_by' => $old_editor->ID ) );
}

section( 'Listing & traversal' );
list( $s, $d ) = req( 'GET', '/fs/list', array( 'path' => '/' ) );
ok( 200 === $s, 'list root 200' );
$names = wp_list_pluck( $d['entries'], 'name' );
ok( in_array( 'wp-config.php', $names, true ), 'admin sees wp-config.php (full mode)' );
ok( ! array_filter( $names, function ( $n ) { return 0 === strpos( $n, 'filora-private-' ); } ), 'private storage is hidden' );
foreach ( array( '../', '/../..', '..%2f', "a\0b", '/wp-content/../../', '/../etc/passwd' ) as $bad ) {
	list( $s ) = req( 'GET', '/fs/list', array( 'path' => $bad ) );
	ok( $s >= 400, "traversal rejected: " . addcslashes( $bad, "\0" ) . " ({$s})" );
}

section( 'CRUD' );
$base = '/wp-content/uploads';
req( 'POST', '/fs/delete', array( 'paths' => array( $base . '/flr-test' ), 'permanent' => 1 ) );
list( $s, $d ) = req( 'POST', '/fs/mkdir', array( 'path' => $base, 'name' => 'flr-test' ) );
ok( 200 === $s && 'dir' === $d['type'], 'mkdir' );
list( $s, $d ) = req( 'POST', '/fs/mkfile', array( 'path' => $base . '/flr-test', 'name' => 'hello.txt', 'content' => 'Hello' ) );
ok( 200 === $s, 'mkfile' );
list( $s, $d ) = req( 'GET', '/fs/read', array( 'path' => $base . '/flr-test/hello.txt' ) );
ok( 200 === $s && 'Hello' === $d['content'], 'read' );
$hash = $d['hash'];
list( $s, $d ) = req( 'POST', '/fs/write', array( 'path' => $base . '/flr-test/hello.txt', 'content' => 'Hello world', 'hash' => $hash ) );
ok( 200 === $s, 'write with hash' );
list( $s, $d ) = req( 'POST', '/fs/write', array( 'path' => $base . '/flr-test/hello.txt', 'content' => 'stale', 'hash' => $hash ) );
ok( 409 === $s, 'conflict detected on stale hash' );
list( $s, $d ) = req( 'GET', '/fs/versions', array( 'path' => $base . '/flr-test/hello.txt' ) );
ok( 200 === $s && count( $d ) >= 1, 'version snapshot created' );
list( $s, $d ) = req( 'POST', '/fs/mkfile', array( 'path' => $base . '/flr-test', 'name' => 'bad.php' ) );
ok( 200 === $s, 'admin may create php' );
list( $s, $d ) = req( 'POST', '/fs/write', array( 'path' => $base . '/flr-test/bad.php', 'content' => "<?php echo 'x' \n function(" ) );
ok( 422 === $s && 'filora_syntax_error' === $d['code'], 'PHP lint blocks broken code' );
list( $s, $d ) = req( 'POST', '/fs/rename', array( 'path' => $base . '/flr-test/hello.txt', 'name' => 'hi.txt' ) );
ok( 200 === $s && 'hi.txt' === $d['name'], 'rename' );
list( $s, $d ) = req( 'POST', '/fs/rename', array( 'path' => $base . '/flr-test/hi.txt', 'name' => '../escape.txt' ) );
ok( 400 === $s, 'rename with slash rejected' );
list( $s, $d ) = req( 'POST', '/fs/mkdir', array( 'path' => $base . '/flr-test', 'name' => 'sub' ) );
list( $s, $d ) = req( 'POST', '/fs/transfer', array( 'paths' => array( $base . '/flr-test/hi.txt' ), 'dest' => $base . '/flr-test/sub', 'mode' => 'copy' ) );
ok( 200 === $s && 1 === count( $d['done'] ), 'copy' );
list( $s, $d ) = req( 'POST', '/fs/transfer', array( 'paths' => array( $base . '/flr-test' ), 'dest' => $base . '/flr-test/sub', 'mode' => 'move' ) );
ok( 200 === $s && 1 === count( $d['errors'] ), 'move into itself refused' );
list( $s, $d ) = req( 'POST', '/fs/duplicate', array( 'paths' => array( $base . '/flr-test/hi.txt' ) ) );
ok( 200 === $s && false !== strpos( $d['done'][0]['to'], '(copy)' ), 'duplicate' );
list( $s, $d ) = req( 'GET', '/fs/search', array( 'path' => $base . '/flr-test', 'q' => 'world', 'content' => 1 ) );
ok( 200 === $s && count( $d['results'] ) >= 1, 'content search' );
list( $s, $d ) = req( 'POST', '/fs/delete', array( 'paths' => array( $base . '/flr-test/sub' ) ) );
ok( 200 === $s && 1 === count( $d['trash'] ), 'delete to trash' );
list( $s, $d ) = req( 'GET', '/trash' );
ok( 200 === $s && count( $d['items'] ) >= 1, 'trash list' );
list( $s, $d ) = req( 'POST', '/trash/restore', array( 'ids' => array( $d['items'][0]['id'] ) ) );
ok( 200 === $s && 1 === count( $d['done'] ), 'restore from trash' );
list( $s, $d ) = req( 'POST', '/fs/delete', array( 'paths' => array( '/wp-admin' ) ) );
ok( 200 === $s && 1 === count( $d['errors'] ), 'system folder cannot be deleted' );
list( $s, $d ) = req( 'POST', '/fs/delete', array( 'paths' => array( '/wp-content/plugins/filora' ) ) );
ok( 200 === $s && ( 1 === count( $d['errors'] ) ), 'plugin itself is protected (or outside root)' );
list( $s, $d ) = req( 'POST', '/fs/archive', array( 'paths' => array( $base . '/flr-test' ), 'name' => 'bundle' ) );
ok( 200 === $s && 'bundle.zip' === $d['name'], 'archive' );
list( $s, $d ) = req( 'POST', '/fs/extract', array( 'path' => $base . '/bundle.zip', 'folder' => 1 ) );
ok( 200 === $s && $d['files'] >= 2, 'extract into folder' );

section( 'Zip slip' );
$zip = new ZipArchive();
$evil = wp_upload_dir()['basedir'] . '/flr-test/evil.zip';
$zip->open( $evil, ZipArchive::CREATE | ZipArchive::OVERWRITE );
$zip->addFromString( '../../escaped.txt', 'x' );
$zip->addFromString( 'ok.txt', 'fine' );
$zip->close();
list( $s, $d ) = req( 'POST', '/fs/extract', array( 'path' => $base . '/flr-test/evil.zip' ) );
ok( 200 === $s && 1 === count( $d['skipped'] ) && ! file_exists( WP_CONTENT_DIR . '/escaped.txt' ), 'zip slip entry skipped' );

section( 'Shares' );
$vault = '/' . Filora\Paths::relative( Filora\Paths::real( Filora\Storage::vault() ), Filora\Paths::abspath() );
req( 'POST', '/fs/mkfile', array( 'path' => $vault, 'name' => 'contract.txt', 'content' => 'secret contract' ) );
list( $s, $d ) = req( 'POST', '/shares', array(), array( 'path' => $vault . '/contract.txt', 'password' => 'pass1234', 'expires' => '7d' ) );
ok( 200 === $s && ! empty( $d['url'] ) && $d['hasPassword'], 'create share with password' );
$share_url = $d['url'];
$share_id  = $d['id'];
ok( ! $d['exposed'], 'vault file reported as not web-exposed' );
list( $s, $d ) = req( 'POST', '/shares', array(), array( 'path' => '/' ) );
ok( 403 === $s, 'cannot share the WordPress root' );
list( $s, $d ) = req( 'POST', '/shares', array(), array( 'path' => '/wp-config.php' ) );
ok( 403 === $s, 'cannot share wp-config.php' );
list( $s, $d ) = req( 'POST', '/shares', array(), array( 'path' => '/wp-content' ) );
ok( 403 === $s, 'cannot share wp-content' );
list( $s, $d ) = req( 'POST', '/shares', array(), array( 'path' => $vault . '/contract.txt', 'mode' => 'upload' ) );
ok( 400 === $s, 'upload mode refused on a file' );
list( $s, $d ) = req( 'GET', '/shares' );
ok( 200 === $s && count( $d ) >= 1, 'list shares' );
preg_match( '#/share/([A-Za-z0-9_-]+)/?$#', $share_url, $m );
$row = Filora\Shares::find_by_token( $m[1] );
ok( $row && (int) $row['id'] === (int) $share_id, 'token lookup by hash' );
ok( null === Filora\Shares::find_by_token( $m[1] . 'x' ), 'wrong token not found' );
list( $s, $d ) = req( 'POST', '/fs/rename', array( 'path' => $vault . '/contract.txt', 'name' => 'contract-v2.txt' ) );
$row = Filora\Shares::get( $share_id );
ok( false !== strpos( $row['path'], 'contract-v2.txt' ), 'share follows rename' );
list( $s, $d ) = req( 'POST', '/shares/' . $share_id, array(), array( 'status' => 'disabled' ) );
ok( 200 === $s && 'disabled' === $d['status'], 'disable share' );
list( $s, $d ) = req( 'POST', '/shares/' . $share_id . '/regenerate' );
ok( 200 === $s && $d['url'] !== $share_url, 'regenerate token' );

section( 'Non-admin role' );
$editor = get_user_by( 'login', 'editor1' );
if ( ! $editor ) {
	$eid    = wp_create_user( 'editor1', 'password', 'editor1@example.com' );
	$editor = get_user_by( 'id', $eid );
	$editor->set_role( 'editor' );
}
as_user( $editor->ID );
list( $s ) = req( 'GET', '/fs/list', array( 'path' => '/' ) );
ok( 403 === $s, 'editor without profile is denied' );
as_user( $admin->ID );
$settings                    = Filora\Settings::get();
$settings['roles']['editor'] = array_merge( Filora\Settings::role_defaults(), array( 'enabled' => true, 'root' => '{UPLOADS}/clients/{user_login}', 'ops' => array( 'download', 'upload', 'mkdir', 'mkfile', 'rename', 'move', 'copy', 'delete', 'edit', 'share', 'extract', 'archive' ), 'upload_exts' => 'jpg,png,txt,zip,pdf' ) );
Filora\Settings::update( $settings );
as_user( $editor->ID );
list( $s, $d ) = req( 'GET', '/fs/list', array( 'path' => '/' ) );
ok( 200 === $s, 'editor gets their own folder' );
ok( is_dir( wp_upload_dir()['basedir'] . '/clients/editor1' ), 'user home auto-created' );
list( $s, $d ) = req( 'POST', '/fs/mkfile', array( 'path' => '/', 'name' => 'shell.php' ) );
ok( 403 === $s, 'editor cannot create .php' );
list( $s, $d ) = req( 'POST', '/fs/mkfile', array( 'path' => '/', 'name' => 'shell.php.jpg' ) );
ok( 403 === $s, 'editor cannot create double extension .php.jpg' );
list( $s, $d ) = req( 'POST', '/fs/mkfile', array( 'path' => '/', 'name' => '.htaccess' ) );
ok( 403 === $s, 'editor cannot create .htaccess' );
list( $s, $d ) = req( 'POST', '/fs/mkfile', array( 'path' => '/', 'name' => 'x.svg' ) );
ok( 403 === $s, 'editor cannot create .svg (XSS)' );
list( $s, $d ) = req( 'POST', '/fs/mkfile', array( 'path' => '/', 'name' => 'notes.txt', 'content' => 'hi' ) );
ok( 200 === $s, 'editor creates notes.txt' );
list( $s, $d ) = req( 'POST', '/fs/rename', array( 'path' => '/notes.txt', 'name' => 'notes.phtml' ) );
ok( 403 === $s, 'editor cannot rename to .phtml' );
list( $s, $d ) = req( 'GET', '/fs/list', array( 'path' => '/../../' ) );
ok( $s >= 400, 'editor cannot escape root' );
// Evil zip with php inside.
$zip  = new ZipArchive();
$ezip = wp_upload_dir()['basedir'] . '/clients/editor1/pack.zip';
$zip->open( $ezip, ZipArchive::CREATE | ZipArchive::OVERWRITE );
$zip->addFromString( 'a.php', '<?php phpinfo();' );
$zip->addFromString( 'b.txt', 'ok' );
$zip->addFromString( '.htaccess', 'x' );
$zip->close();
list( $s, $d ) = req( 'POST', '/fs/extract', array( 'path' => '/pack.zip' ) );
ok( 200 === $s && 1 === $d['files'] && 2 === count( $d['skipped'] ), 'extract skips php and .htaccess for editor' );
ok( ! file_exists( wp_upload_dir()['basedir'] . '/clients/editor1/a.php' ), 'a.php not extracted' );
list( $s, $d ) = req( 'GET', '/fs/read', array( 'path' => '/../../../wp-config.php' ) );
ok( $s >= 400, 'editor cannot read wp-config via traversal' );
list( $s, $d ) = req( 'GET', '/settings' );
ok( 403 === $s, 'editor cannot read settings' );
list( $s, $d ) = req( 'GET', '/tools/server' );
ok( 403 === $s, 'editor cannot use admin tools' );
// Symlink escape.
@symlink( ABSPATH . 'wp-config.php', wp_upload_dir()['basedir'] . '/clients/editor1/link.php' );
list( $s, $d ) = req( 'GET', '/fs/list', array( 'path' => '/' ) );
ok( ! in_array( 'link.php', wp_list_pluck( $d['entries'], 'name' ), true ), 'symlink escaping root is hidden' );
list( $s, $d ) = req( 'GET', '/fs/read', array( 'path' => '/link.php' ) );
ok( 404 === $s, 'symlink escaping root cannot be read' );
@unlink( wp_upload_dir()['basedir'] . '/clients/editor1/link.php' );
list( $s, $d ) = req( 'POST', '/shares', array(), array( 'path' => '/notes.txt' ) );
ok( 200 === $s, 'editor can share own file' );
list( $s, $d ) = req( 'GET', '/shares' );
ok( 200 === $s && 1 === count( $d ), 'editor only sees own shares' );

section( 'Sudo mode' );
as_user( $admin->ID );
$settings                     = Filora\Settings::get();
$settings['security']['sudo'] = true;
Filora\Settings::update( $settings );
delete_user_meta( $admin->ID, 'filora_sudo' );
list( $s, $d ) = req( 'POST', '/fs/mkdir', array( 'path' => $base, 'name' => 'sudo-test' ) );
ok( 403 === $s && 'filora_sudo_required' === $d['code'], 'write requires sudo' );
list( $s, $d ) = req( 'POST', '/sudo', array( 'password' => 'nope' ) );
ok( 403 === $s, 'wrong sudo password refused' );
list( $s, $d ) = req( 'POST', '/sudo', array( 'password' => 'password' ) );
ok( 200 === $s, 'sudo granted' );
$settings['security']['sudo'] = false;
Filora\Settings::update( $settings );

section( 'Activity' );
list( $s, $d ) = req( 'GET', '/activity' );
ok( 200 === $s && $d['total'] > 5, 'activity log has entries (' . $d['total'] . ')' );

// Cleanup.
req( 'POST', '/fs/delete', array( 'paths' => array( $base . '/flr-test', $base . '/bundle.zip', $base . '/bundle' ), 'permanent' => 1 ) );

echo "\n{$passes} passed, {$failures} failed\n";
exit( $failures ? 1 : 0 );
