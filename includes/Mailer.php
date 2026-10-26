<?php
/**
 * Transactional emails (one-time codes, share notifications).
 *
 * @package Filora
 */

namespace Filora;

defined( 'ABSPATH' ) || exit;

/**
 * Minimal, client-friendly HTML emails sent with wp_mail().
 */
final class Mailer {

	/**
	 * Send an email.
	 *
	 * @param string $to      Recipient.
	 * @param string $subject Subject.
	 * @param string $title   Heading.
	 * @param string $body    HTML body (already escaped).
	 * @return bool
	 */
	public static function send( $to, $subject, $title, $body ) {
		$accent = Settings::value( 'sharing', 'accent', '#4f46e5' );
		$site   = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$html   = '<!doctype html><html><body style="margin:0;background:#f4f4f7;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#1f2330">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:32px 12px">'
			. '<table role="presentation" width="100%" style="max-width:520px;background:#fff;border-radius:16px;overflow:hidden" cellpadding="0" cellspacing="0">'
			. '<tr><td style="height:6px;background:' . esc_attr( $accent ) . '"></td></tr>'
			. '<tr><td style="padding:28px 32px 8px;font-size:13px;color:#6b7080">' . esc_html( $site ) . '</td></tr>'
			. '<tr><td style="padding:0 32px;font-size:20px;font-weight:600">' . esc_html( $title ) . '</td></tr>'
			. '<tr><td style="padding:16px 32px 32px;font-size:15px;line-height:1.6">' . $body . '</td></tr>'
			. '</table></td></tr></table></body></html>';
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		return wp_mail( $to, '[' . $site . '] ' . $subject, $html, $headers );
	}

	/**
	 * One-time access code.
	 *
	 * @param string $to    Email.
	 * @param string $code  Code.
	 * @param string $label Share name.
	 * @return bool
	 */
	public static function otp( $to, $code, $label ) {
		$body = '<p>' . esc_html__( 'Use this code to open the shared files:', 'filora' ) . '</p>'
			. '<p style="font-size:32px;font-weight:700;letter-spacing:8px;margin:20px 0;font-family:ui-monospace,Menlo,Consolas,monospace">' . esc_html( $code ) . '</p>'
			. '<p style="color:#6b7080;font-size:13px">' . esc_html__( 'This code expires in 10 minutes. If you did not request it, you can ignore this email.', 'filora' ) . '</p>';
		/* translators: %s: share name */
		return self::send( $to, sprintf( __( 'Your access code for “%s”', 'filora' ), $label ), __( 'Your access code', 'filora' ), $body );
	}

	/**
	 * Notify the share owner of an event.
	 *
	 * @param array  $share   Share row.
	 * @param string $event   view|download|upload.
	 * @param array  $details Details (files, email, name).
	 */
	public static function notify_owner( array $share, $event, array $details ) {
		$options = Shares::options( $share );
		$notify  = $options['notify'];
		if ( 'none' === $notify || ( 'uploads' === $notify && 'upload' !== $event ) ) {
			return;
		}
		// Throttle non-upload notifications to one every 15 minutes per share.
		if ( 'upload' !== $event ) {
			$key = 'filora_notify_' . $share['id'] . '_' . $event;
			if ( get_transient( $key ) ) {
				return;
			}
			set_transient( $key, 1, 15 * MINUTE_IN_SECONDS );
		}
		$owner = get_userdata( (int) $share['created_by'] );
		if ( ! $owner || ! is_email( $owner->user_email ) ) {
			return;
		}
		$label = '' !== $share['label'] ? $share['label'] : basename( Paths::from_storage( $share['path'] ) );
		$who   = ! empty( $details['email'] ) ? $details['email'] : ( ! empty( $details['name'] ) ? $details['name'] : __( 'Someone', 'filora' ) );
		switch ( $event ) {
			case 'upload':
				/* translators: 1: visitor, 2: share name */
				$title = sprintf( __( '%1$s uploaded files to “%2$s”', 'filora' ), $who, $label );
				break;
			case 'download':
				/* translators: 1: visitor, 2: share name */
				$title = sprintf( __( '%1$s downloaded files from “%2$s”', 'filora' ), $who, $label );
				break;
			default:
				/* translators: 1: visitor, 2: share name */
				$title = sprintf( __( '%1$s opened “%2$s”', 'filora' ), $who, $label );
		}
		$body = '';
		if ( ! empty( $details['files'] ) ) {
			$body .= '<ul style="padding-left:18px">';
			foreach ( array_slice( (array) $details['files'], 0, 30 ) as $file ) {
				$body .= '<li>' . esc_html( $file ) . '</li>';
			}
			$body .= '</ul>';
		}
		$body .= '<p style="color:#6b7080;font-size:13px">' . esc_html( gmdate( 'Y-m-d H:i', time() ) ) . ' UTC — IP ' . esc_html( Http::ip_for_log() ) . '</p>';
		$body .= '<p><a style="display:inline-block;background:' . esc_attr( Settings::value( 'sharing', 'accent', '#4f46e5' ) ) . ';color:#fff;text-decoration:none;padding:10px 18px;border-radius:10px" href="' . esc_url( admin_url( 'admin.php?page=filora#/shares' ) ) . '">' . esc_html__( 'Manage share links', 'filora' ) . '</a></p>';
		self::send( $owner->user_email, $title, $title, $body );
	}
}
