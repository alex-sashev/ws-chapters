<?php
/**
 * Branded HTML emails: the site's look (emblem, "WIDOWS SONS" in black, "EUROPE" in gold, "Brotherhood Beyond
 * Borders") on a light background, a gold button, and the plain link below it for email apps that hide buttons.
 *
 * Built with tables and inline styles, which is what email apps (Gmail, Outlook, Apple Mail) understand.
 * Fonts: Cinzel where the email app has it, Georgia otherwise (most email apps do not load web fonts).
 *
 * @package ws-chapters
 */

defined( 'ABSPATH' ) || exit;

/**
 * The email's HTML.
 *
 * @param array $a {
 *     @type string   $preheader Short line shown next to the subject in the inbox.
 *     @type string   $heading   Title inside the email.
 *     @type string[] $before    Paragraphs above the button (plain text).
 *     @type string   $button    Button label.
 *     @type string   $url       Button link.
 *     @type string[] $after     Paragraphs below the button (plain text).
 * }
 */
function ws_email_html( array $a ) {
	$a = wp_parse_args( $a, array( 'preheader' => '', 'heading' => '', 'before' => array(), 'code' => '', 'button' => '', 'url' => '', 'after' => array() ) );

	$ink    = '#0B0B0C';
	$text   = '#1C1A17';
	$muted  = '#6B655C';
	$gold   = '#B8913F'; // A shade darker than the site's gold, so it reads on white.
	$goldbg = '#C9A24A';
	$line   = '#E3DCCD';
	$paper  = '#F6F3EC';
	$serif  = "'Cinzel', Georgia, 'Times New Roman', serif";
	$sans   = "'Source Sans 3', 'Helvetica Neue', Helvetica, Arial, sans-serif";
	$logo   = get_stylesheet_directory_uri() . '/assets/ws-mark.png';
	$home   = home_url( '/' );

	$p = function ( $t ) use ( $text, $sans ) {
		return '<p style="margin:0 0 16px;font-family:' . $sans . ';font-size:16px;line-height:1.6;color:' . $text . ';">' . nl2br( esc_html( $t ) ) . '</p>';
	};
	$small = function ( $t ) use ( $muted, $sans ) {
		return '<p style="margin:0 0 12px;font-family:' . $sans . ';font-size:14px;line-height:1.6;color:' . $muted . ';">' . nl2br( esc_html( $t ) ) . '</p>';
	};

	$button = '';
	if ( $a['button'] && $a['url'] ) {
		$url     = esc_url( $a['url'] );
		$button  = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 24px;"><tr><td align="center" bgcolor="' . $goldbg . '" style="border-radius:4px;">'
			. '<a href="' . $url . '" style="display:inline-block;padding:15px 30px;font-family:' . $sans . ';font-size:15px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:' . $ink . ';text-decoration:none;border-radius:4px;">' . esc_html( $a['button'] ) . '</a>'
			. '</td></tr></table>';
		// The plain link, for email apps that block buttons or images.
		$button .= $small( __( 'If the button does not work, copy this link into your browser:', 'ws-chapters' ) )
			. '<p style="margin:0 0 24px;font-family:' . $sans . ';font-size:13px;line-height:1.5;word-break:break-all;"><a href="' . $url . '" style="color:#8A6D2C;">' . esc_html( $a['url'] ) . '</a></p>';
	}

	$body = '';
	foreach ( (array) $a['before'] as $t ) {
		$body .= $p( $t );
	}
	if ( '' !== $a['code'] ) { // A one-time code, large and spaced so it is easy to read and type.
		$body .= '<p style="margin:0 0 24px;font-family:' . $sans . ';font-size:34px;line-height:1.2;font-weight:700;letter-spacing:8px;color:' . $ink . ';">' . esc_html( $a['code'] ) . '</p>';
	}
	$body .= $button;
	foreach ( (array) $a['after'] as $t ) {
		$body .= $small( $t );
	}

	return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light only"><title>' . esc_html( $a['heading'] ) . '</title></head>'
		. '<body style="margin:0;padding:0;background:' . $paper . ';">'
		. '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:' . $paper . ';">' . esc_html( $a['preheader'] ) . '</div>'
		. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="' . $paper . '" style="background:' . $paper . ';"><tr><td align="center" style="padding:32px 12px;">'
		. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;background:#FFFFFF;border:1px solid ' . $line . ';border-top:4px solid ' . $goldbg . ';border-radius:6px;">'

		// Header: emblem and wordmark, as on the site, in black and gold.
		. '<tr><td align="center" style="padding:32px 24px 8px;">'
		. '<a href="' . esc_url( $home ) . '" style="text-decoration:none;"><img src="' . esc_url( $logo ) . '" width="150" height="63" alt="Widows Sons" style="display:block;border:0;width:150px;height:auto;margin:0 auto 18px;"></a>'
		. '<div style="font-family:' . $serif . ';font-size:34px;line-height:1.05;font-weight:700;letter-spacing:2px;color:' . $ink . ';text-transform:uppercase;">Widows Sons</div>'
		. '<div style="font-family:' . $serif . ';font-size:30px;line-height:1.15;font-weight:700;letter-spacing:4px;color:' . $gold . ';text-transform:uppercase;">Europe</div>'
		. '<div style="margin-top:12px;font-family:' . $serif . ';font-size:12px;letter-spacing:4px;color:' . $ink . ';text-transform:uppercase;">Brotherhood Beyond Borders</div>'
		. '</td></tr>'
		. '<tr><td align="center" style="padding:16px 40px 0;"><div style="height:1px;line-height:1px;font-size:0;background:' . $line . ';">&nbsp;</div></td></tr>'

		// Message.
		. '<tr><td style="padding:28px 40px 12px;">'
		. ( $a['heading'] ? '<h1 style="margin:0 0 18px;font-family:' . $serif . ';font-size:22px;line-height:1.3;font-weight:700;letter-spacing:1px;color:' . $ink . ';text-transform:uppercase;">' . esc_html( $a['heading'] ) . '</h1>' : '' )
		. $body
		. '</td></tr>'

		// Footer.
		. '<tr><td style="padding:20px 40px 28px;border-top:1px solid ' . $line . ';">'
		. '<p style="margin:0;font-family:' . $sans . ';font-size:12px;line-height:1.6;color:' . $muted . ';text-align:center;">'
		. 'Widows Sons Europe · <a href="' . esc_url( $home ) . '" style="color:#8A6D2C;text-decoration:none;">' . esc_html( wp_parse_url( $home, PHP_URL_HOST ) ) . '</a><br>'
		. esc_html__( 'You receive this email because a Widows Sons Europe admin added you to the members\' area.', 'ws-chapters' )
		. '</p></td></tr>'

		. '</table></td></tr></table></body></html>';
}

/** Send a branded email. Returns what wp_mail returns. */
function ws_send_email( $to, $subject, array $content ) {
	return wp_mail( $to, $subject, ws_email_html( $content ), array( 'Content-Type: text/html; charset=UTF-8' ) );
}
