<?php
/**
 * Public share page (standalone: no theme code, strict CSP, inline assets).
 *
 * Available: $router (ShareRouter), $share (row|null), $options, $nonce, $ctx, $view and view vars.
 *
 * @package Filora
 */

namespace Filora;

defined( 'ABSPATH' ) || exit;

$sharing   = Settings::get( 'sharing' );
$site_name = get_bloginfo( 'name' );
$accent    = $sharing['accent'] ? $sharing['accent'] : '#4f46e5';
$logo      = $sharing['logo'] ? $sharing['logo'] : get_site_icon_url( 128 );
$is_rtl    = is_rtl();
$asset     = static function ( $file ) {
	$dist = FILORA_DIR . 'assets/dist/' . $file;
	return is_readable( $dist ) ? (string) file_get_contents( $dist ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
};
$fsize     = static function ( $bytes ) {
	return size_format( (int) $bytes, (int) $bytes < KB_IN_BYTES ? 0 : 1 );
};
$fmt_date  = static function ( $ts ) {
	return wp_date( get_option( 'date_format' ), $ts );
};
$icon      = static function ( $id, $class = '' ) {
	return '<svg class="fs-ico ' . esc_attr( $class ) . '" aria-hidden="true"><use href="#i-' . esc_attr( $id ) . '"/></svg>';
};
$tile      = static function ( $cat, $ext ) use ( $icon ) {
	if ( 'dir' === $cat ) {
		return '<span class="fs-tile fs-tile--dir">' . $icon( 'folder' ) . '</span>';
	}
	if ( 'upload' === $cat ) {
		return '<span class="fs-tile fs-tile--upload">' . $icon( 'upload' ) . '</span>';
	}
	$label = strtoupper( substr( (string) $ext, 0, 4 ) );
	return '<span class="fs-tile fs-tile--' . esc_attr( $cat ) . '">' . $icon( 'file' ) . ( '' !== $label ? '<b>' . esc_html( $label ) . '</b>' : '' ) . '</span>';
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
				'name' => $item['name'],
				'rel'  => $item['rel'],
				'cat'  => $item['cat'],
				'ext'  => $item['ext'],
				'size' => $item['size'],
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
			'noPreview'  => __( 'No preview available for this file.', 'filora' ),
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
<html lang="<?php echo esc_attr( str_replace( '_', '-', get_locale() ) ); ?>" dir="<?php echo $is_rtl ? 'rtl' : 'ltr'; ?>">
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
		<symbol id="i-folder" viewBox="0 0 24 24"><path d="M3 7.5A2.5 2.5 0 0 1 5.5 5h3.6a2 2 0 0 1 1.4.6l1.3 1.3a2 2 0 0 0 1.4.6h5.3A2.5 2.5 0 0 1 21 10v7.5a2.5 2.5 0 0 1-2.5 2.5h-13A2.5 2.5 0 0 1 3 17.5z" fill="currentColor"/></symbol>
		<symbol id="i-file" viewBox="0 0 24 24"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z" fill="currentColor" opacity=".18"/><path d="M14 3v5h5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></symbol>
		<symbol id="i-download" viewBox="0 0 24 24"><path d="M12 4v11m0 0-4.5-4.5M12 15l4.5-4.5M5 19.5h14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></symbol>
		<symbol id="i-upload" viewBox="0 0 24 24"><path d="M12 16V5m0 0L7.5 9.5M12 5l4.5 4.5M5 19.5h14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></symbol>
		<symbol id="i-lock" viewBox="0 0 24 24"><rect x="4.5" y="10.5" width="15" height="10" rx="2.5" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M8 10.5V8a4 4 0 0 1 8 0v2.5" fill="none" stroke="currentColor" stroke-width="1.8"/></symbol>
		<symbol id="i-mail" viewBox="0 0 24 24"><rect x="3.5" y="5.5" width="17" height="13" rx="2.5" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="m4.5 7 7.5 6 7.5-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></symbol>
		<symbol id="i-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.5" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M12 7.5V12l3 2" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></symbol>
		<symbol id="i-link" viewBox="0 0 24 24"><path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></symbol>
		<symbol id="i-shield" viewBox="0 0 24 24"><path d="M12 3.5 5 6v5.5c0 4.2 2.9 7.7 7 9 4.1-1.3 7-4.8 7-9V6z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></symbol>
		<symbol id="i-archive" viewBox="0 0 24 24"><rect x="3.5" y="4.5" width="17" height="5" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M5 9.5v8A2.5 2.5 0 0 0 7.5 20h9a2.5 2.5 0 0 0 2.5-2.5v-8M10 13h4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></symbol>
		<symbol id="i-grid" viewBox="0 0 24 24"><rect x="4" y="4" width="6.5" height="6.5" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.8"/><rect x="13.5" y="4" width="6.5" height="6.5" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.8"/><rect x="4" y="13.5" width="6.5" height="6.5" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.8"/><rect x="13.5" y="13.5" width="6.5" height="6.5" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.8"/></symbol>
		<symbol id="i-list" viewBox="0 0 24 24"><path d="M9 6.5h11M9 12h11M9 17.5h11M4.5 6.5h.01M4.5 12h.01M4.5 17.5h.01" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></symbol>
		<symbol id="i-search" viewBox="0 0 24 24"><circle cx="11" cy="11" r="6.5" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="m16 16 4 4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></symbol>
		<symbol id="i-chevron" viewBox="0 0 24 24"><path d="m9.5 6 6 6-6 6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></symbol>
		<symbol id="i-x" viewBox="0 0 24 24"><path d="m6.5 6.5 11 11m0-11-11 11" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></symbol>
		<symbol id="i-check" viewBox="0 0 24 24"><path d="m5 12.5 4.5 4.5L19 7.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></symbol>
		<symbol id="i-logout" viewBox="0 0 24 24"><path d="M14.5 5.5h2a2.5 2.5 0 0 1 2.5 2.5v8a2.5 2.5 0 0 1-2.5 2.5h-2M10 16l-4-4 4-4M6 12h9" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></symbol>
		<symbol id="i-home" viewBox="0 0 24 24"><path d="M4 10.5 12 4l8 6.5V19a1.5 1.5 0 0 1-1.5 1.5H15V15h-6v5.5H5.5A1.5 1.5 0 0 1 4 19z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></symbol>
	</defs>
</svg>

<header class="fs-top">
	<span class="fs-brand">
		<?php if ( $logo ) : ?>
			<img src="<?php echo esc_url( $logo ); ?>" alt="" width="28" height="28">
		<?php else : ?>
			<span class="fs-brand-dot"></span>
		<?php endif; ?>
		<span><?php echo esc_html( $site_name ); ?></span>
	</span>
	<?php if ( 'page' === $view && $ctx['gated'] ) : ?>
		<a class="fs-btn fs-btn--ghost fs-btn--sm" href="<?php echo esc_url( $router->link( array( 'a' => 'logout' ) ) ); ?>"><?php echo $icon( 'logout' ); // phpcs:ignore ?><span><?php esc_html_e( 'Lock', 'filora' ); ?></span></a>
	<?php endif; ?>
</header>

<main class="fs-main">
<?php if ( 'message' === $view ) : ?>
	<?php $m = isset( $messages[ $kind ] ) ? $messages[ $kind ] : $messages['not_found']; ?>
	<section class="fs-card fs-center">
		<div class="fs-badge-icon fs-badge-icon--<?php echo esc_attr( $kind ); ?>"><?php echo $icon( $m[0] ); // phpcs:ignore ?></div>
		<h1><?php echo esc_html( $m[1] ); ?></h1>
		<p class="fs-muted"><?php echo esc_html( '' !== $text ? $text : $m[2] ); ?></p>
	</section>

<?php elseif ( 'gate' === $view ) : ?>
	<section class="fs-card fs-center fs-gate">
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
	</section>

<?php else : ?>
	<?php
	$is_dir_share = 'dir' === $share['type'];
	$file_count   = 0;
	$total_size   = 0;
	foreach ( $items as $item ) {
		if ( ! $item['dir'] ) {
			++$file_count;
			$total_size += $item['size'];
		}
	}
	$expires = $share['expires_at'] ? strtotime( $share['expires_at'] . ' UTC' ) : 0;
	$owner   = ! empty( $options['show_owner'] ) ? get_userdata( (int) $share['created_by'] ) : null;
	$meta    = array();
	if ( $is_dir_share && $ctx['can_browse'] ) {
		/* translators: %s: number of items */
		$meta[] = sprintf( _n( '%s item', '%s items', count( $items ), 'filora' ), number_format_i18n( count( $items ) ) );
		if ( $total_size ) {
			$meta[] = $fsize( $total_size );
		}
	} elseif ( ! $is_dir_share ) {
		$meta[] = $fsize( filesize( $ctx['root'] ) );
	}
	if ( $owner ) {
		/* translators: %s: person */
		$meta[] = sprintf( __( 'Shared by %s', 'filora' ), $owner->display_name );
	}
	/* translators: %s: date */
	$meta[] = $expires ? sprintf( __( 'Available until %s', 'filora' ), $fmt_date( $expires ) ) : __( 'No expiration', 'filora' );
	?>
	<section class="fs-hero">
		<?php
		if ( $is_dir_share ) {
			echo $tile( 'dropbox' === $share['mode'] ? 'upload' : 'dir', '' ); // phpcs:ignore
		} else {
			echo $tile( Mime::category( $ctx['root'] ), Paths::ext( $ctx['root'] ) ); // phpcs:ignore
		}
		?>
		<div class="fs-hero-text">
			<h1><?php echo esc_html( 'dropbox' === $share['mode'] && '' === $share['label'] ? __( 'Send us your files', 'filora' ) : $router->display_name() ); ?></h1>
			<p class="fs-meta"><?php echo esc_html( implode( ' · ', $meta ) ); ?></p>
		</div>
		<div class="fs-hero-actions">
			<?php if ( $is_dir_share && $ctx['can_download'] && ! empty( $options['allow_zip'] ) && $items ) : ?>
				<a class="fs-btn fs-btn--primary" href="<?php echo esc_url( $router->link( array( 'a' => 'zip', 'p' => $router->rel( $dir ) ) ) ); ?>" rel="nofollow"><?php echo $icon( 'archive' ); // phpcs:ignore ?><span><?php esc_html_e( 'Download all', 'filora' ); ?></span></a>
			<?php elseif ( ! $is_dir_share && $ctx['can_download'] ) : ?>
				<a class="fs-btn fs-btn--primary" href="<?php echo esc_url( $router->link( array( 'a' => 'dl' ) ) ); ?>" rel="nofollow"><?php echo $icon( 'download' ); // phpcs:ignore ?><span><?php esc_html_e( 'Download', 'filora' ); ?></span></a>
			<?php endif; ?>
		</div>
	</section>

	<?php if ( '' !== trim( (string) $options['message'] ) ) : ?>
		<div class="fs-note"><?php echo nl2br( esc_html( $options['message'] ) ); ?></div>
	<?php endif; ?>

	<?php if ( ! $ctx['can_preview'] && $ctx['can_browse'] && ! empty( $share['max_downloads'] ) ) : ?>
		<p class="fs-hint"><?php echo $icon( 'shield' ); // phpcs:ignore ?><?php esc_html_e( 'Previews are disabled because this link has a download limit.', 'filora' ); ?></p>
	<?php endif; ?>

	<?php if ( $ctx['can_upload'] ) : ?>
		<section class="fs-drop" id="fs-drop">
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
	<?php endif; ?>

	<?php if ( 'dropbox' === $share['mode'] ) : ?>
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
		$cat  = Mime::category( $ctx['root'] );
		$raw  = $router->link( array( 'a' => 'raw' ) );
		$size = (int) filesize( $ctx['root'] );
		?>
		<section class="fs-single">
			<div class="fs-single-preview">
				<?php if ( $ctx['can_preview'] && 'image' === $cat && Mime::inline_safe( Mime::type( $ctx['root'] ) ) ) : ?>
					<img src="<?php echo esc_url( $raw ); ?>" alt="<?php echo esc_attr( basename( $ctx['root'] ) ); ?>">
				<?php elseif ( $ctx['can_preview'] && 'video' === $cat ) : ?>
					<video src="<?php echo esc_url( $raw ); ?>" controls playsinline preload="metadata"></video>
				<?php elseif ( $ctx['can_preview'] && 'audio' === $cat ) : ?>
					<?php echo $tile( $cat, Paths::ext( $ctx['root'] ) ); // phpcs:ignore ?>
					<audio src="<?php echo esc_url( $raw ); ?>" controls preload="metadata"></audio>
				<?php elseif ( $ctx['can_preview'] && 'pdf' === $cat ) : ?>
					<iframe src="<?php echo esc_url( $raw ); ?>" title="<?php echo esc_attr( basename( $ctx['root'] ) ); ?>"></iframe>
				<?php else : ?>
					<?php echo $tile( $cat, Paths::ext( $ctx['root'] ) ); // phpcs:ignore ?>
				<?php endif; ?>
			</div>
			<div class="fs-single-info">
				<strong><?php echo esc_html( basename( $ctx['root'] ) ); ?></strong>
				<span class="fs-muted"><?php echo esc_html( $fsize( $size ) . ' · ' . $fmt_date( (int) filemtime( $ctx['root'] ) ) ); ?></span>
			</div>
		</section>

	<?php elseif ( $ctx['can_browse'] ) : ?>
		<?php
		$crumbs = array();
		$rel    = $router->rel( $dir );
		if ( '' !== $rel ) {
			$acc = '';
			foreach ( explode( '/', $rel ) as $part ) {
				$acc      = '' === $acc ? $part : $acc . '/' . $part;
				$crumbs[] = array( $part, $acc );
			}
		}
		?>
		<div class="fs-bar">
			<nav class="fs-crumbs" aria-label="<?php esc_attr_e( 'Breadcrumb', 'filora' ); ?>">
				<a href="<?php echo esc_url( $router->link() ); ?>"><?php echo $icon( 'home' ); // phpcs:ignore ?><span><?php echo esc_html( $router->display_name() ); ?></span></a>
				<?php foreach ( $crumbs as $crumb ) : ?>
					<?php echo $icon( 'chevron', 'fs-sep' ); // phpcs:ignore ?>
					<a href="<?php echo esc_url( $router->link( array( 'p' => $crumb[1] ) ) ); ?>"><?php echo esc_html( $crumb[0] ); ?></a>
				<?php endforeach; ?>
			</nav>
			<div class="fs-tools">
				<label class="fs-search">
					<?php echo $icon( 'search' ); // phpcs:ignore ?>
					<span class="fs-sr"><?php esc_html_e( 'Filter', 'filora' ); ?></span>
					<input type="search" id="fs-filter" placeholder="<?php esc_attr_e( 'Filter…', 'filora' ); ?>">
				</label>
				<div class="fs-seg" role="group" aria-label="<?php esc_attr_e( 'View', 'filora' ); ?>">
					<button type="button" data-view="grid" aria-label="<?php esc_attr_e( 'Grid', 'filora' ); ?>"><?php echo $icon( 'grid' ); // phpcs:ignore ?></button>
					<button type="button" data-view="list" aria-label="<?php esc_attr_e( 'List', 'filora' ); ?>"><?php echo $icon( 'list' ); // phpcs:ignore ?></button>
				</div>
			</div>
		</div>

		<?php if ( ! $items ) : ?>
			<div class="fs-empty"><?php echo $icon( 'folder' ); // phpcs:ignore ?><p><?php esc_html_e( 'This folder is empty.', 'filora' ); ?></p></div>
		<?php else : ?>
			<ul class="fs-items" id="fs-items" data-view="grid">
				<?php foreach ( $items as $item ) : ?>
					<?php
					if ( $item['dir'] ) {
						$href = $router->link( array( 'p' => $item['rel'] ) );
					} elseif ( $ctx['can_preview'] ) {
						$href = $router->link( array( 'p' => $item['rel'] ) );
					} elseif ( $ctx['can_download'] ) {
						$href = $router->link( array( 'a' => 'dl', 'p' => $item['rel'] ) );
					} else {
						$href = '';
					}
					?>
					<li class="fs-item" data-name="<?php echo esc_attr( strtolower( $item['name'] ) ); ?>" data-rel="<?php echo esc_attr( $item['rel'] ); ?>" data-dir="<?php echo $item['dir'] ? '1' : '0'; ?>">
						<<?php echo $href ? 'a href="' . esc_url( $href ) . '"' : 'div'; ?> class="fs-item-main"<?php echo ! $item['dir'] && $ctx['can_preview'] ? ' data-preview' : ''; ?>>
							<span class="fs-thumb">
								<?php if ( $item['thumb'] ) : ?>
									<img loading="lazy" decoding="async" alt="" src="<?php echo esc_url( $router->link( array( 'a' => 'thumb', 'p' => $item['rel'] ) ) ); ?>">
								<?php else : ?>
									<?php echo $tile( $item['cat'], $item['ext'] ); // phpcs:ignore ?>
								<?php endif; ?>
							</span>
							<span class="fs-item-text">
								<span class="fs-name" title="<?php echo esc_attr( $item['name'] ); ?>"><?php echo esc_html( $item['name'] ); ?></span>
								<span class="fs-sub"><?php echo esc_html( ( $item['dir'] ? __( 'Folder', 'filora' ) : $fsize( $item['size'] ) ) . ' · ' . $fmt_date( $item['mtime'] ) ); ?></span>
							</span>
						</<?php echo $href ? 'a' : 'div'; ?>>
						<?php if ( ! $item['dir'] && $ctx['can_download'] ) : ?>
							<a class="fs-dl" href="<?php echo esc_url( $router->link( array( 'a' => 'dl', 'p' => $item['rel'] ) ) ); ?>" rel="nofollow" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: file */ __( 'Download %s', 'filora' ), $item['name'] ) ); ?>"><?php echo $icon( 'download' ); // phpcs:ignore ?></a>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
			<p class="fs-empty fs-empty--filter" id="fs-nores" hidden><?php esc_html_e( 'No matching files.', 'filora' ); ?></p>
		<?php endif; ?>
	<?php endif; ?>
<?php endif; ?>
</main>

<footer class="fs-foot">
	<?php echo $icon( 'shield' ); // phpcs:ignore ?>
	<span><?php echo esc_html( '' !== $sharing['footer'] ? $sharing['footer'] : __( 'Private link — please do not forward it.', 'filora' ) ); ?></span>
</footer>

<?php if ( $data ) : ?>
<script type="application/json" id="fs-data"><?php echo wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?></script>
<script nonce="<?php echo esc_attr( $nonce ); ?>"><?php echo $asset( 'share.js' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></script>
<?php endif; ?>
</body>
</html>
