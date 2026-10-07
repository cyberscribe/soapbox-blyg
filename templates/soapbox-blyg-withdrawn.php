<?php
/**
 * The page shown at a withdrawn item's address.
 *
 * To change it, copy this file into your theme and edit the copy. The link elements in the head
 * are part of the protocol and should stay: they lead from this page back to the item's document.
 * The page's few style rules are the inline style of the 'soapbox-blyg-page' handle.
 *
 * Available in $data: origin (SoapboxBlyg\Origins\Origin), item_url, withdrawn_at (ISO 8601),
 * webmention (the endpoint URL, or '' when mentions are off).
 *
 * @package SoapboxBlyg
 */

defined( 'ABSPATH' ) || exit;

$soapbox_blyg_when = wp_date( get_option( 'date_format' ), strtotime( $data['withdrawn_at'] ) );
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?php echo esc_html( sprintf( /* translators: %s: site name */ __( 'Withdrawn - %s', 'soapbox-blyg' ), get_bloginfo( 'name' ) ) ); ?></title>
<link rel="blyg" href="<?php echo esc_url( $data['origin']->url() ); ?>">
<link rel="alternate" type="application/json" href="<?php echo esc_url( $data['item_url'] ); ?>">
<?php if ( '' !== $data['webmention'] ) : ?>
<link rel="webmention" href="<?php echo esc_url( $data['webmention'] ); ?>">
<?php endif; ?>
<?php wp_print_styles( 'soapbox-blyg-page' ); ?>
</head>
<body>
<main>
<h1><?php echo esc_html_x( 'Withdrawn', 'page heading: this item has been withdrawn by its author', 'soapbox-blyg' ); ?></h1>
<p>
<?php
echo esc_html(
	sprintf(
		/* translators: %s: date */
		__( 'The author withdrew this item on %s. Its content is no longer published.', 'soapbox-blyg' ),
		$soapbox_blyg_when
	)
);
?>
</p>
<p><a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php bloginfo( 'name' ); ?></a></p>
</main>
</body>
</html>
