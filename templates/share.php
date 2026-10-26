<?php
/**
 * Public share page (standalone: no theme code, strict CSP, inline assets).
 *
 * Designed for non-technical visitors (clients, partners, sales teams): plain words,
 * visual folders and documents, no paths, permissions or raw dates.
 *
 * Available: $router (ShareRouter), $share (row|null), $options, $nonce, $ctx, $view and view vars.
 *
 * @package Filora
 */

namespace Filora;

defined( 'ABSPATH' ) || exit;

$sharing   = Settings::get( 'sharing' );
$site_name = get_bloginfo( 'name' );
$accent    = preg_match( '/^#[0-9a-fA-F]{6}$/', (string) $sharing['accent'] ) ? $sharing['accent'] : '#4f46e5';
$logo      = $sharing['logo'] ? $sharing['logo'] : get_site_icon_url( 128 );
$is_rtl    = is_rtl();
$asset     = static function ( $file ) {
	$dist = FILORA_DIR . 'assets/dist/' . $file;
	return is_readable( $dist ) ? (string) file_get_contents( $dist ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
};
$fsize     = static function ( $bytes ) {
	return size_format( (int) $bytes, (int) $bytes < KB_IN_BYTES ? 0 : 1 );
};
$icon      = static function ( $id, $class = '' ) {
	return '<svg class="fs-ico ' . esc_attr( $class ) . '" aria-hidden="true"><use href="#i-' . esc_attr( $id ) . '"/></svg>';
};
// Plain-language type names (no extensions or MIME types).
$type_label = static function ( $cat ) {
	$labels = array(
		'image'   => __( 'Image', 'filora' ),
		'video'   => __( 'Video', 'filora' ),
		'audio'   => __( 'Audio', 'filora' ),
		'pdf'     => __( 'PDF document', 'filora' ),
		'doc'     => __( 'Document', 'filora' ),
		'sheet'   => __( 'Spreadsheet', 'filora' ),
		'slides'  => __( 'Presentation', 'filora' ),
		'archive' => __( 'Compressed folder', 'filora' ),
		'font'    => __( 'Font', 'filora' ),
		'code'    => __( 'Text file', 'filora' ),
	);
	return isset( $labels[ $cat ] ) ? $labels[ $cat ] : __( 'File', 'filora' );
};
// File names without their extension (the type is shown separately).
$label = static function ( $name, $is_dir = false ) {
	if ( $is_dir ) {
		return $name;
	}
	$base = pathinfo( $name, PATHINFO_FILENAME );
	return '' !== $base ? $base : $name;
};
$summarize = static function ( $dirs, $files, $images ) {
	$parts = array();
	if ( $dirs ) {
		/* translators: %s: number of folders */
		$parts[] = sprintf( _n( '%s folder', '%s folders', $dirs, 'filora' ), number_format_i18n( $dirs ) );
	}
	if ( $files && $files === $images ) {
		/* translators: %s: number of photos */
		$parts[] = sprintf( _n( '%s photo', '%s photos', $files, 'filora' ), number_format_i18n( $files ) );
	} elseif ( $files ) {
		/* translators: %s: number of files */
		$parts[] = sprintf( _n( '%s file', '%s files', $files, 'filora' ), number_format_i18n( $files ) );
	}
	return $parts ? implode( ' · ', $parts ) : __( 'Empty for now', 'filora' );
};
// Document illustration: a page with the format written on it.
$doc_art = static function ( $cat, $ext, $class = '' ) use ( $icon ) {
	$badge = strtoupper( substr( (string) $ext, 0, 4 ) );
	$inner = '';
	if ( 'video' === $cat || 'audio' === $cat ) {
		$inner = '<span class="fs-doc-play">' . $icon( 'video' === $cat ? 'play' : 'music' ) . '</span>';
	} elseif ( 'image' === $cat ) {
		$inner = '<span class="fs-doc-play">' . $icon( 'image' ) . '</span>';
	} else {
		$inner = '<span class="fs-doc-lines"></span>';
	}
	return '<span class="fs-doc fs-doc--' . esc_attr( $cat ) . ' ' . esc_attr( $class ) . '">' . $inner . ( '' !== $badge ? '<b class="fs-doc-badge">' . esc_html( $badge ) . '</b>' : '' ) . '</span>';
};
$initial = static function ( $name ) {
	$name = trim( (string) $name );
	if ( '' === $name ) {
		return '?';
	}
	$first = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 1 ) : substr( $name, 0, 1 );
	return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $first ) : strtoupper( $first );
};

$title = __( 'Shared files', 'filora' );
if ( 'page' === $view && $share ) {
	$title = $router->display_name();
} elseif ( 'gate' === $view ) {
	$title = __( 'Protected share', 'filora' );
}

$messages = array(
	'not_found'    => array( 'link', __( 'Link not found', 'filora' ), __( 'This link is invalid. Check that you copied it entirely, or ask for a new one.', 'filora' ) ),
	'expired'      => array( 'clock', __( 'This link has expired', 'filora' ), __( 'Ask the person who shared it with you for a new link.', 'filora' ) ),
	'disabled'     => array( 'lock', __( 'This link has been disabled', 'filora' ), __( 'Ask the person who shared it with you for a new link.', 'filora' ) ),
	'exhausted'    => array( 'download', __( 'Download limit reached', 'filora' ), __( 'This link has already been used the maximum number of times.', 'filora' ) ),
	'missing'      => array( 'file', __( 'Content no longer available', 'filora' ), __( 'The shared files have been moved or deleted.', 'filora' ) ),
	'orphaned'     => array( 'file', __( 'Content no longer available', 'filora' ), __( 'This link is not active anymore.', 'filora' ) ),
	'unavailable'  => array( 'lock', __( 'Sharing is disabled', 'filora' ), __( 'Shared links are currently disabled on this site.', 'filora' ) ),
	'item_missing' => array( 'file', __( 'File not found', 'filora' ), __( 'This item is not (or no longer) part of the shared folder.', 'filora' ) ),
	'ip_denied'    => array( 'shield', __( 'Access denied', 'filora' ), __( 'This link cannot be opened from your network.', 'filora' ) ),
	'rate_limited' => array( 'clock', __( 'Too many requests', 'filora' ), __( 'Please wait a few minutes and try again.', 'filora' ) ),
	'zip_error'    => array( 'archive', __( 'Archive unavailable', 'filora' ), __( 'The archive could not be created.', 'filora' ) ),
);

$data = array();
if ( 'page' === $view && $share ) {
	$gallery = array();
	foreach ( $items as $item ) {
		if ( ! $item['dir'] ) {
			$gallery[] = array(
				'name'  => $item['name'],
				'label' => $label( $item['name'] ),
				'type'  => $type_label( $item['cat'] ),
				'rel'   => $item['rel'],
				'cat'   => $item['cat'],
				'ext'   => $item['ext'],
				'size'  => $item['size'],
				'sizeH' => $fsize( $item['size'] ),
			);
		}
	}
	$data = array(
		'base'        => $router->link(),
		'ticket'      => $ctx['ticket'],
		'canDownload' => $ctx['can_download'],
		'canPreview'  => $ctx['can_preview'],
		'canUpload'   => $ctx['can_upload'],
		'mode'        => $share['mode'],
		'dir'         => 'dir' === $share['type'] ? $router->rel( $dir ) : '',
		'open'        => isset( $open ) ? $open : '',
		'files'       => $gallery,
		'chunk'       => Uploads::chunk_size(),
		'maxUpload'   => (int) $options['upload_max'] * MB_IN_BYTES,
		'exts'        => (string) $options['upload_exts'],
		'askName'     => ! empty( $options['ask_name'] ),
		'i18n'        => array(
			'download'   => __( 'Download', 'filora' ),
			'close'      => __( 'Close', 'filora' ),
			'previous'   => __( 'Previous', 'filora' ),
			'next'       => __( 'Next', 'filora' ),
			'noPreview'  => __( 'There is no preview for this type of file. Download it to open it on your device.', 'filora' ),
			/* translators: 1: position, 2: total */
			'position'   => __( '%1$s of %2$s', 'filora' ),
			'uploading'  => __( 'Uploading…', 'filora' ),
			'done'       => __( 'Done', 'filora' ),
			'failed'     => __( 'Failed', 'filora' ),
			'retry'      => __( 'Retry', 'filora' ),
			'tooLarge'   => __( 'Too large', 'filora' ),
			'badType'    => __( 'File type not allowed', 'filora' ),
			'allDone'    => __( 'All files were sent. Thank you!', 'filora' ),
			'nameNeeded' => __( 'Please enter your name first.', 'filora' ),
			'loading'    => __( 'Loading…', 'filora' ),
			'noResults'  => __( 'No matching files.', 'filora' ),
		),
	);
}
?><!doctype html>
<html lang="<?php echo esc_attr( str_replace( '_', '-', I18n::locale() ) ); ?>" dir="<?php echo $is_rtl ? 'rtl' : 'ltr'; ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow, noarchive">
<meta name="referrer" content="no-referrer">
<meta name="color-scheme" content="light dark">
<title><?php echo esc_html( $title . ' — ' . $site_name ); ?></title>
<?php if ( $logo ) : ?>
<link rel="icon" href="<?php echo esc_url( $logo ); ?>">
<?php endif; ?>
<style>:root{--fs-accent:<?php echo esc_html( $accent ); ?>}<?php echo $asset( 'share.css' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></style>
</head>
<body class="fs fs--<?php echo esc_attr( $view ); ?>">
<svg width="0" height="0" style="position:absolute" aria-hidden="true">
	<defs>
		<symbol id="i-folder" viewBox="0 0 24 24"><path d="M3 7.5A2.5 2.5 0 0 1 5.5 5h3.6a2 2 0 0 1 1.4.6l1.3 1.3a2 2 0 0 0 1.4.6h5.3A2.5 2.5 0 0 1 21 10v7.5a2.5 2.5 0 0 1-2.5 2.5h-13A2.5 2.5 0 0 1 3 17.5z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></symbol>
		<symbol id="i-file" viewBox="0 0 24 24"><path d="M14 3.5H7.5a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2h9a2 2 0 0 0 2-2V8zM14 3.5V8h4.5" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></symbol>
		<symbol id="i-download" viewBox="0 0 24 24"><path d="M12 4v11m0 0-4.5-4.5M12 15l4.5-4.5M5 19.5h14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></symbol>
		<symbol id="i-upload" viewBox="0 0 24 24"><path d="M12 16V5m0 0L7.5 9.5M12 5l4.5 4.5M5 19.5h14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></symbol>
		<symbol id="i-lock" viewBox="0 0 24 24"><rect x="4.5" y="10.5" width="15" height="10" rx="2.5" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M8 10.5V8a4 4 0 0 1 8 0v2.5" fill="none" stroke="currentColor" stroke-width="1.8"/></symbol>
		<symbol id="i-mail" viewBox="0 0 24 24"><rect x="3.5" y="5.5" width="17" height="13" rx="2.5" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="m4.5 7 7.5 6 7.5-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></symbol>
		<symbol id="i-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.5" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M12 7.5V12l3 2" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></symbol>
		<symbol id="i-link" viewBox="0 0 24 24"><path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></symbol>
		<symbol id="i-shield" viewBox="0 0 24 24"><path d="M12 3.5 5 6v5.5c0 4.2 2.9 7.7 7 9 4.1-1.3 7-4.8 7-9V6z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="m9 12 2 2 4-4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></symbol>
		<symbol id="i-archive" viewBox="0 0 24 24"><rect x="3.5" y="4.5" width="17" height="5" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M5 9.5v8A2.5 2.5 0 0 0 7.5 20h9a2.5 2.5 0 0 0 2.5-2.5v-8M10 13h4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></symbol>
		<symbol id="i-grid" viewBox="0 0 24 24"><rect x="4" y="4" width="6.5" height="6.5" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.8"/><rect x="13.5" y="4" width="6.5" height="6.5" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.8"/><rect x="4" y="13.5" width="6.5" height="6.5" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.8"/><rect x="13.5" y="13.5" width="6.5" height="6.5" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.8"/></symbol>
		<symbol id="i-list" viewBox="0 0 24 24"><path d="M9 6.5h11M9 12h11M9 17.5h11M4.5 6.5h.01M4.5 12h.01M4.5 17.5h.01" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></symbol>
		<symbol id="i-search" viewBox="0 0 24 24"><circle cx="11" cy="11" r="6.5" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="m16 16 4 4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></symbol>
		<symbol id="i-chevron" viewBox="0 0 24 24"><path d="m9.5 6 6 6-6 6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></symbol>
		<symbol id="i-back" viewBox="0 0 24 24"><path d="M19 12H5m0 0 6-6m-6 6 6 6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></symbol>
		<symbol id="i-x" viewBox="0 0 24 24"><path d="m6.5 6.5 11 11m0-11-11 11" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></symbol>
		<symbol id="i-check" viewBox="0 0 24 24"><path d="m5 12.5 4.5 4.5L19 7.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></symbol>
		<symbol id="i-logout" viewBox="0 0 24 24"><path d="M14.5 5.5h2a2.5 2.5 0 0 1 2.5 2.5v8a2.5 2.5 0 0 1-2.5 2.5h-2M10 16l-4-4 4-4M6 12h9" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></symbol>
		<symbol id="i-eye" viewBox="0 0 24 24"><path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><circle cx="12" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="1.7"/></symbol>
		<symbol id="i-play" viewBox="0 0 24 24"><path d="M8 5.5v13l10.5-6.5z" fill="currentColor"/></symbol>
		<symbol id="i-music" viewBox="0 0 24 24"><path d="M9 18V6l10-2v12" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><circle cx="6.5" cy="18" r="2.5" fill="currentColor"/><circle cx="16.5" cy="16" r="2.5" fill="currentColor"/></symbol>
		<symbol id="i-image" viewBox="0 0 24 24"><rect x="3.5" y="4.5" width="17" height="15" rx="2.5" fill="none" stroke="currentColor" stroke-width="1.7"/><circle cx="9" cy="10" r="1.8" fill="currentColor"/><path d="m4 17.5 4.5-4.5 3.5 3.5 2.5-2.5 5 5" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></symbol>
		<symbol id="i-quote" viewBox="0 0 24 24"><path d="M10 7.5H6.5A1.5 1.5 0 0 0 5 9v3.5A1.5 1.5 0 0 0 6.5 14H9v.5C9 16 8 17 6.5 17.5M19 7.5h-3.5A1.5 1.5 0 0 0 14 9v3.5a1.5 1.5 0 0 0 1.5 1.5H18v.5c0 1.5-1 2.5-2.5 3" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></symbol>
		<symbol id="i-plus" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/></symbol>
	</defs>
</svg>
<div class="fs-glow" aria-hidden="true"></div>

<header class="fs-top">
	<span class="fs-brand">
		<?php if ( $logo ) : ?>
			<img src="<?php echo esc_url( $logo ); ?>" alt="" width="32" height="32">
		<?php else : ?>
			<span class="fs-brand-mark"><?php echo esc_html( $initial( $site_name ) ); ?></span>
		<?php endif; ?>
		<span><?php echo esc_html( $site_name ); ?></span>
	</span>
	<?php if ( 'page' === $view && $ctx['gated'] ) : ?>
		<a class="fs-btn fs-btn--ghost fs-btn--sm" href="<?php echo esc_url( $router->link( array( 'a' => 'logout' ) ) ); ?>" title="<?php esc_attr_e( 'Close the session on this device', 'filora' ); ?>"><?php echo $icon( 'logout' ); // phpcs:ignore ?><span><?php esc_html_e( 'Sign out', 'filora' ); ?></span></a>
	<?php endif; ?>
</header>

<main class="fs-main">
<?php if ( 'message' === $view ) : ?>
	<?php $m = isset( $messages[ $kind ] ) ? $messages[ $kind ] : $messages['not_found']; ?>
	<section class="fs-panel fs-center">
		<div class="fs-badge-icon fs-badge-icon--<?php echo esc_attr( $kind ); ?>"><?php echo $icon( $m[0] ); // phpcs:ignore ?></div>
		<h1><?php echo esc_html( $m[1] ); ?></h1>
		<p class="fs-muted"><?php echo esc_html( '' !== $text ? $text : $m[2] ); ?></p>
	</section>

<?php elseif ( 'gate' === $view ) : ?>
	<section class="fs-panel fs-center fs-gate">
		<div class="fs-badge-icon"><?php echo $icon( 'password' === $gate ? 'lock' : 'mail' ); // phpcs:ignore ?></div>
		<?php if ( 'password' === $gate ) : ?>
			<h1><?php esc_html_e( 'This share is protected', 'filora' ); ?></h1>
			<p class="fs-muted"><?php esc_html_e( 'Enter the password you received to open it.', 'filora' ); ?></p>
			<form method="post" action="<?php echo esc_url( $router->link( array( 'a' => 'unlock' ) ) ); ?>" class="fs-form">
				<input type="hidden" name="ft" value="<?php echo esc_attr( $ctx['ticket'] ); ?>">
				<label class="fs-sr" for="fs-pw"><?php esc_html_e( 'Password', 'filora' ); ?></label>
				<input class="fs-input" id="fs-pw" type="password" name="password" autocomplete="current-password" required autofocus placeholder="<?php esc_attr_e( 'Password', 'filora' ); ?>">
				<button class="fs-btn fs-btn--primary fs-btn--block" type="submit"><?php esc_html_e( 'Unlock', 'filora' ); ?></button>
			</form>
		<?php elseif ( 'email' === $gate ) : ?>
			<h1><?php esc_html_e( 'Verify your email', 'filora' ); ?></h1>
			<p class="fs-muted"><?php esc_html_e( 'This share is reserved for specific people. Enter your email address to receive a one-time access code.', 'filora' ); ?></p>
			<form method="post" action="<?php echo esc_url( $router->link( array( 'a' => 'otp_send' ) ) ); ?>" class="fs-form">
				<input type="hidden" name="ft" value="<?php echo esc_attr( $ctx['ticket'] ); ?>">
				<label class="fs-sr" for="fs-em"><?php esc_html_e( 'Email address', 'filora' ); ?></label>
				<input class="fs-input" id="fs-em" type="email" name="email" autocomplete="email" required autofocus placeholder="<?php esc_attr_e( 'you@company.com', 'filora' ); ?>" value="<?php echo esc_attr( $email ); ?>">
				<button class="fs-btn fs-btn--primary fs-btn--block" type="submit"><?php esc_html_e( 'Send me a code', 'filora' ); ?></button>
			</form>
		<?php else : ?>
			<h1><?php esc_html_e( 'Check your inbox', 'filora' ); ?></h1>
			<p class="fs-muted">
				<?php
				/* translators: %s: email address */
				echo esc_html( sprintf( __( 'If %s is allowed to access this share, a 6-digit code is on its way. It expires in 10 minutes.', 'filora' ), $email ) );
				?>
			</p>
			<form method="post" action="<?php echo esc_url( $router->link( array( 'a' => 'otp_verify' ) ) ); ?>" class="fs-form">
				<input type="hidden" name="ft" value="<?php echo esc_attr( $ctx['ticket'] ); ?>">
				<input type="hidden" name="email" value="<?php echo esc_attr( $email ); ?>">
				<label class="fs-sr" for="fs-code"><?php esc_html_e( 'Access code', 'filora' ); ?></label>
				<input class="fs-input fs-input--code" id="fs-code" name="code" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" autocomplete="one-time-code" required autofocus placeholder="000000">
				<button class="fs-btn fs-btn--primary fs-btn--block" type="submit"><?php esc_html_e( 'Open the share', 'filora' ); ?></button>
			</form>
			<form method="post" action="<?php echo esc_url( $router->link( array( 'a' => 'otp_send' ) ) ); ?>" class="fs-inline-form">
				<input type="hidden" name="ft" value="<?php echo esc_attr( $ctx['ticket'] ); ?>">
				<input type="hidden" name="email" value="<?php echo esc_attr( $email ); ?>">
				<button class="fs-link" type="submit"><?php esc_html_e( 'Send a new code', 'filora' ); ?></button>
			</form>
		<?php endif; ?>
		<?php if ( '' !== $error ) : ?>
			<p class="fs-alert" role="alert"><?php echo esc_html( $error ); ?></p>
		<?php endif; ?>
		<p class="fs-secure-note"><?php echo $icon( 'shield' ); // phpcs:ignore ?><span><?php esc_html_e( 'Secure, private access', 'filora' ); ?></span></p>
	</section>

<?php else : ?>
	<?php
	$is_dir_share = 'dir' === $share['type'];
	$is_dropbox   = 'dropbox' === $share['mode'];
	$rel          = $is_dir_share ? $router->rel( $dir ) : '';
	$folders      = array();
	$files        = array();
	foreach ( $items as $item ) {
		if ( $item['dir'] ) {
			$folders[] = $item;
		} else {
			$files[] = $item;
		}
	}
	$images  = count(
		array_filter(
			$files,
			static function ( $f ) {
				return 'image' === $f['cat'];
			}
		)
	);
	$gallery = $images >= 3 && $images >= 0.7 * count( $files );
	$expires = $share['expires_at'] ? strtotime( $share['expires_at'] . ' UTC' ) : 0;
	$owner   = ! empty( $options['show_owner'] ) ? get_userdata( (int) $share['created_by'] ) : null;
	$note    = '' === $rel ? trim( (string) $options['message'] ) : '';

	// Heading: the share name at its root, the folder name inside sub-folders.
	$heading = $router->display_name();
	if ( $is_dropbox && '' === $share['label'] ) {
		$heading = __( 'Send us your files', 'filora' );
	} elseif ( '' !== $rel ) {
		$heading = basename( $rel );
	} elseif ( ! $is_dir_share ) {
		$heading = $label( basename( $ctx['root'] ) );
	}
	$trail = array();
	if ( '' !== $rel ) {
		$trail[] = array( $router->display_name(), $router->link() );
		$acc     = '';
		$parts   = explode( '/', $rel );
		array_pop( $parts );
		foreach ( $parts as $part ) {
			$acc     = '' === $acc ? $part : $acc . '/' . $part;
			$trail[] = array( $part, $router->link( array( 'p' => $acc ) ) );
		}
	}
	$chips = array();
	if ( $is_dir_share && $ctx['can_browse'] ) {
		$chips[] = array( ! $folders && $files && count( $files ) === $images ? 'image' : 'folder', $summarize( count( $folders ), count( $files ), $images ) );
	} elseif ( ! $is_dir_share ) {
		$chips[] = array( 'file', $type_label( Mime::category( $ctx['root'] ) ) . ' · ' . $fsize( filesize( $ctx['root'] ) ) );
	}
	if ( $expires ) {
		/* translators: %s: date */
		$chips[] = array( 'clock', sprintf( __( 'Available until %s', 'filora' ), I18n::date( $expires ) ) );
	}
	if ( $owner && '' === $note && '' === $rel ) {
		/* translators: %s: person */
		$chips[] = array( 'user', sprintf( __( 'Shared by %s', 'filora' ), $owner->display_name ) );
	}
	?>
	<section class="fs-hero">
		<div class="fs-hero-text">
			<?php if ( $trail ) : ?>
				<nav class="fs-trail" aria-label="<?php esc_attr_e( 'Breadcrumb', 'filora' ); ?>">
					<a class="fs-back" href="<?php echo esc_url( $trail[ count( $trail ) - 1 ][1] ); ?>" aria-label="<?php esc_attr_e( 'Back', 'filora' ); ?>"><?php echo $icon( 'back' ); // phpcs:ignore ?></a>
					<?php foreach ( $trail as $i => $crumb ) : ?>
						<?php if ( $i ) : ?>
							<?php echo $icon( 'chevron', 'fs-sep' ); // phpcs:ignore ?>
						<?php endif; ?>
						<a href="<?php echo esc_url( $crumb[1] ); ?>"><?php echo esc_html( $crumb[0] ); ?></a>
					<?php endforeach; ?>
				</nav>
			<?php else : ?>
				<p class="fs-eyebrow"><?php echo esc_html( $is_dropbox ? __( 'File drop', 'filora' ) : __( 'Shared with you', 'filora' ) ); ?></p>
			<?php endif; ?>
			<h1><?php echo esc_html( $heading ); ?></h1>
			<?php if ( $chips ) : ?>
				<ul class="fs-chips">
					<?php foreach ( $chips as $chip ) : ?>
						<li><?php echo 'user' === $chip[0] ? '<span class="fs-avatar fs-avatar--xs">' . esc_html( $initial( $owner->display_name ) ) . '</span>' : $icon( $chip[0] ); // phpcs:ignore ?><span><?php echo esc_html( $chip[1] ); ?></span></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<div class="fs-hero-actions">
			<?php if ( $ctx['can_upload'] && ! $is_dropbox ) : ?>
				<button type="button" class="fs-btn" data-pick><?php echo $icon( 'plus' ); // phpcs:ignore ?><span><?php esc_html_e( 'Add files', 'filora' ); ?></span></button>
			<?php endif; ?>
			<?php if ( $is_dir_share && $ctx['can_download'] && ! empty( $options['allow_zip'] ) && $items ) : ?>
				<a class="fs-btn fs-btn--primary" href="<?php echo esc_url( $router->link( array( 'a' => 'zip', 'p' => $rel ) ) ); ?>" rel="nofollow"><?php echo $icon( 'download' ); // phpcs:ignore ?><span><?php echo esc_html( '' === $rel ? __( 'Download all', 'filora' ) : __( 'Download this folder', 'filora' ) ); ?></span></a>
			<?php elseif ( ! $is_dir_share && $ctx['can_download'] ) : ?>
				<a class="fs-btn fs-btn--primary" href="<?php echo esc_url( $router->link( array( 'a' => 'dl' ) ) ); ?>" rel="nofollow"><?php echo $icon( 'download' ); // phpcs:ignore ?><span><?php esc_html_e( 'Download', 'filora' ); ?></span></a>
			<?php endif; ?>
		</div>
	</section>

	<?php if ( '' !== $note ) : ?>
		<figure class="fs-note">
			<?php if ( $owner ) : ?>
				<span class="fs-avatar"><?php echo esc_html( $initial( $owner->display_name ) ); ?></span>
			<?php else : ?>
				<span class="fs-avatar fs-avatar--icon"><?php echo $icon( 'quote' ); // phpcs:ignore ?></span>
			<?php endif; ?>
			<div>
				<?php if ( $owner ) : ?>
					<figcaption><?php echo esc_html( $owner->display_name ); ?></figcaption>
				<?php endif; ?>
				<blockquote><?php echo nl2br( esc_html( $note ) ); ?></blockquote>
			</div>
		</figure>
	<?php endif; ?>

	<?php if ( ! $ctx['can_preview'] && $ctx['can_browse'] && ! empty( $share['max_downloads'] ) ) : ?>
		<p class="fs-hint"><?php echo $icon( 'shield' ); // phpcs:ignore ?><?php esc_html_e( 'Previews are disabled because this link has a download limit.', 'filora' ); ?></p>
	<?php endif; ?>

	<?php
	$drop_zone = static function () use ( $options, $icon, $is_dropbox ) {
		?>
		<section class="fs-drop<?php echo $is_dropbox ? ' fs-drop--hero' : ''; ?>" id="fs-drop">
			<input type="file" id="fs-file" multiple hidden>
			<div class="fs-drop-inner">
				<span class="fs-drop-icon"><?php echo $icon( 'upload' ); // phpcs:ignore ?></span>
				<p class="fs-drop-title"><?php esc_html_e( 'Drag & drop files here', 'filora' ); ?></p>
				<p class="fs-muted fs-small">
					<?php esc_html_e( 'or', 'filora' ); ?>
					<button type="button" class="fs-link" id="fs-browse"><?php esc_html_e( 'browse your device', 'filora' ); ?></button>
				</p>
				<?php if ( ! empty( $options['ask_name'] ) ) : ?>
					<input class="fs-input fs-input--sm" id="fs-visitor" type="text" maxlength="100" autocomplete="name" placeholder="<?php esc_attr_e( 'Your name (required)', 'filora' ); ?>">
				<?php endif; ?>
				<p class="fs-muted fs-small">
					<?php
					/* translators: %s: max size */
					echo esc_html( sprintf( __( 'Up to %s per file', 'filora' ), size_format( (int) $options['upload_max'] * MB_IN_BYTES ) ) );
					if ( '*' !== $options['upload_exts'] ) {
						$exts  = array_filter( explode( ',', (string) $options['upload_exts'] ) );
						$short = array_slice( $exts, 0, 8 );
						echo ' · <span title="' . esc_attr( strtoupper( implode( ', ', $exts ) ) ) . '">' . esc_html( strtoupper( implode( ', ', $short ) ) . ( count( $exts ) > 8 ? ' +' . ( count( $exts ) - 8 ) : '' ) ) . '</span>';
					}
					?>
				</p>
			</div>
			<ul class="fs-queue" id="fs-queue" aria-live="polite"></ul>
		</section>
		<?php
	};
	?>

	<?php if ( $is_dropbox ) : ?>
		<?php
		if ( $ctx['can_upload'] ) {
			$drop_zone();
		}
		?>
		<section class="fs-mine" id="fs-mine" <?php echo $my_files ? '' : 'hidden'; ?>>
			<h2><?php esc_html_e( 'Your uploads', 'filora' ); ?></h2>
			<ul>
				<?php foreach ( $my_files as $file ) : ?>
					<li><?php echo $icon( 'check' ); // phpcs:ignore ?><span><?php echo esc_html( $file['name'] ); ?></span><small><?php echo esc_html( $fsize( $file['size'] ) ); ?></small></li>
				<?php endforeach; ?>
			</ul>
			<p class="fs-muted fs-small"><?php esc_html_e( 'For privacy, you only see the files you sent from this browser.', 'filora' ); ?></p>
		</section>

	<?php elseif ( ! $is_dir_share ) : ?>
		<?php
		$cat = Mime::category( $ctx['root'] );
		$raw = $router->link( array( 'a' => 'raw' ) );
		?>
		<section class="fs-single">
			<?php if ( $ctx['can_preview'] && 'image' === $cat && Mime::inline_safe( Mime::type( $ctx['root'] ) ) ) : ?>
				<img class="fs-single-media" src="<?php echo esc_url( $raw ); ?>" alt="<?php echo esc_attr( basename( $ctx['root'] ) ); ?>">
			<?php elseif ( $ctx['can_preview'] && 'video' === $cat ) : ?>
				<video class="fs-single-media" src="<?php echo esc_url( $raw ); ?>" controls playsinline preload="metadata"></video>
			<?php elseif ( $ctx['can_preview'] && 'pdf' === $cat ) : ?>
				<iframe class="fs-single-pdf" src="<?php echo esc_url( $raw ); ?>" title="<?php echo esc_attr( basename( $ctx['root'] ) ); ?>"></iframe>
			<?php elseif ( $ctx['can_preview'] && 'code' === $cat && filesize( $ctx['root'] ) <= 512 * KB_IN_BYTES ) : ?>
				<?php $plain = in_array( Paths::ext( $ctx['root'] ), array( 'txt', 'md', 'markdown', 'log', 'text', '' ), true ); ?>
				<pre class="fs-single-text<?php echo $plain ? '' : ' is-code'; ?>"><?php echo esc_html( (string) file_get_contents( $ctx['root'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents ?></pre>
			<?php else : ?>
				<div class="fs-single-doc">
					<?php echo $doc_art( $cat, Paths::ext( $ctx['root'] ), 'fs-doc--xl' ); // phpcs:ignore ?>
					<?php if ( $ctx['can_preview'] && 'audio' === $cat ) : ?>
						<audio src="<?php echo esc_url( $raw ); ?>" controls preload="metadata"></audio>
					<?php else : ?>
						<p class="fs-muted"><?php esc_html_e( 'There is no preview for this type of file. Download it to open it on your device.', 'filora' ); ?></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</section>

	<?php elseif ( $ctx['can_browse'] ) : ?>
		<?php if ( ! $items ) : ?>
			<div class="fs-empty">
				<span class="fs-empty-art"><?php echo $icon( 'folder' ); // phpcs:ignore ?></span>
				<p><?php esc_html_e( 'This folder is empty for now.', 'filora' ); ?></p>
			</div>
		<?php else : ?>
			<?php if ( count( $items ) > 6 ) : ?>
				<div class="fs-toolbar">
					<label class="fs-search">
						<?php echo $icon( 'search' ); // phpcs:ignore ?>
						<span class="fs-sr"><?php esc_html_e( 'Search in this folder', 'filora' ); ?></span>
						<input type="search" id="fs-filter" placeholder="<?php esc_attr_e( 'Search in this folder…', 'filora' ); ?>" autocomplete="off">
					</label>
					<div class="fs-seg" role="group" aria-label="<?php esc_attr_e( 'Display', 'filora' ); ?>">
						<button type="button" data-view="grid" title="<?php esc_attr_e( 'Thumbnails', 'filora' ); ?>" aria-label="<?php esc_attr_e( 'Thumbnails', 'filora' ); ?>"><?php echo $icon( 'grid' ); // phpcs:ignore ?></button>
						<button type="button" data-view="list" title="<?php esc_attr_e( 'List', 'filora' ); ?>" aria-label="<?php esc_attr_e( 'List', 'filora' ); ?>"><?php echo $icon( 'list' ); // phpcs:ignore ?></button>
					</div>
				</div>
			<?php endif; ?>

			<div class="fs-content" id="fs-items" data-view="grid">
				<?php if ( $folders ) : ?>
					<section class="fs-section">
						<h2 class="fs-section-title"><?php esc_html_e( 'Folders', 'filora' ); ?> <span><?php echo esc_html( number_format_i18n( count( $folders ) ) ); ?></span></h2>
						<ul class="fs-grid fs-grid--folders">
							<?php foreach ( $folders as $item ) : ?>
								<?php
								$sum    = isset( $item['summary'] ) ? $item['summary'] : null;
								$thumbs = $sum ? $sum['thumbs'] : array();
								?>
								<li class="fs-item fs-folder" data-name="<?php echo esc_attr( strtolower( $item['name'] ) ); ?>" data-rel="<?php echo esc_attr( $item['rel'] ); ?>" data-dir="1">
									<a class="fs-card" href="<?php echo esc_url( $router->link( array( 'p' => $item['rel'] ) ) ); ?>">
										<span class="fs-folder-art<?php echo $thumbs ? ' has-photos' : ''; ?>">
											<span class="fs-folder-back"></span>
											<?php if ( $thumbs ) : ?>
												<?php foreach ( $thumbs as $n => $t ) : ?>
													<img class="fs-paper fs-paper--<?php echo (int) $n + ( 1 === count( $thumbs ) ? 1 : 0 ); ?>" loading="lazy" decoding="async" alt="" src="<?php echo esc_url( $router->link( array( 'a' => 'thumb', 'p' => $t ) ) ); ?>">
												<?php endforeach; ?>
											<?php elseif ( ! $sum || $sum['files'] || $sum['dirs'] ) : ?>
												<span class="fs-paper fs-paper--blank fs-paper--0"></span><span class="fs-paper fs-paper--blank fs-paper--2"></span>
											<?php endif; ?>
											<span class="fs-folder-front"></span>
										</span>
										<span class="fs-info">
											<span class="fs-name" title="<?php echo esc_attr( $item['name'] ); ?>"><?php echo esc_html( $item['name'] ); ?></span>
											<span class="fs-sub"><?php echo esc_html( $sum ? $summarize( $sum['dirs'], $sum['files'], $sum['images'] ) : __( 'Folder', 'filora' ) ); ?></span>
										</span>
									</a>
								</li>
							<?php endforeach; ?>
						</ul>
					</section>
				<?php endif; ?>

				<?php if ( $files ) : ?>
					<section class="fs-section">
						<h2 class="fs-section-title"><?php echo esc_html( $gallery && count( $files ) === $images ? __( 'Photos', 'filora' ) : __( 'Files', 'filora' ) ); ?> <span><?php echo esc_html( number_format_i18n( count( $files ) ) ); ?></span></h2>
						<ul class="fs-grid fs-grid--files<?php echo $gallery ? ' is-gallery' : ''; ?>">
							<?php foreach ( $files as $item ) : ?>
								<?php
								if ( $ctx['can_preview'] ) {
									$href = $router->link( array( 'p' => $item['rel'] ) );
								} elseif ( $ctx['can_download'] ) {
									$href = $router->link(
										array(
											'a' => 'dl',
											'p' => $item['rel'],
										)
									);
								} else {
									$href = '';
								}
								$tag = $href ? 'a' : 'div';
								?>
								<li class="fs-item fs-file fs-cat--<?php echo esc_attr( $item['cat'] ); ?>" data-name="<?php echo esc_attr( strtolower( $item['name'] ) ); ?>" data-rel="<?php echo esc_attr( $item['rel'] ); ?>" data-dir="0">
									<<?php echo $tag; // phpcs:ignore ?> class="fs-card"<?php echo $href ? ' href="' . esc_url( $href ) . '"' : ''; ?><?php echo $ctx['can_preview'] ? ' data-preview' : ''; ?>>
										<span class="fs-visual<?php echo $item['thumb'] ? ' fs-visual--photo' : ''; ?>">
											<?php if ( $item['thumb'] ) : ?>
												<img loading="lazy" decoding="async" alt="" src="<?php echo esc_url( $router->link( array( 'a' => 'thumb', 'p' => $item['rel'], 's' => $gallery ? 640 : 320 ) ) ); ?>">
											<?php else : ?>
												<?php echo $doc_art( $item['cat'], $item['ext'] ); // phpcs:ignore ?>
											<?php endif; ?>
											<?php if ( $ctx['can_preview'] ) : ?>
												<span class="fs-hover"><?php echo $icon( 'eye' ); // phpcs:ignore ?></span>
											<?php endif; ?>
										</span>
										<span class="fs-info">
											<span class="fs-name" title="<?php echo esc_attr( $item['name'] ); ?>"><?php echo esc_html( $label( $item['name'] ) ); ?></span>
											<span class="fs-sub"><?php echo esc_html( $gallery && 'image' === $item['cat'] ? $fsize( $item['size'] ) : $type_label( $item['cat'] ) . ' · ' . $fsize( $item['size'] ) ); ?></span>
										</span>
									</<?php echo $tag; // phpcs:ignore ?>>
									<?php if ( $ctx['can_download'] ) : ?>
										<a class="fs-dl" href="<?php echo esc_url( $router->link( array( 'a' => 'dl', 'p' => $item['rel'] ) ) ); ?>" rel="nofollow" title="<?php esc_attr_e( 'Download', 'filora' ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: file */ __( 'Download %s', 'filora' ), $item['name'] ) ); ?>"><?php echo $icon( 'download' ); // phpcs:ignore ?></a>
									<?php endif; ?>
								</li>
							<?php endforeach; ?>
						</ul>
					</section>
				<?php endif; ?>
			</div>
			<p class="fs-empty fs-empty--filter" id="fs-nores" hidden><?php esc_html_e( 'No matching files.', 'filora' ); ?></p>
		<?php endif; ?>

		<?php
		if ( $ctx['can_upload'] ) {
			$drop_zone();
		}
		?>
	<?php endif; ?>
<?php endif; ?>
</main>

<footer class="fs-foot">
	<?php echo $icon( 'lock' ); // phpcs:ignore ?>
	<span><?php echo esc_html( '' !== $sharing['footer'] ? $sharing['footer'] : __( 'Private link — please do not forward it.', 'filora' ) ); ?></span>
</footer>

<?php if ( $data ) : ?>
<script type="application/json" id="fs-data"><?php echo wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?></script>
<script nonce="<?php echo esc_attr( $nonce ); ?>"><?php echo $asset( 'share.js' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></script>
<?php endif; ?>
</body>
</html>
