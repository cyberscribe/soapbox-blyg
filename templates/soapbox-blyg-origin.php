<?php
/**
 * The page shown at an origin's bare mount, when the origin has nowhere better to send a person.
 *
 * To change it, copy this file into your theme and edit the copy.
 *
 * Available in $data: origin (SoapboxBlyg\Origins\Origin), manifest (URL), feed (URL).
 *
 * @package SoapboxBlyg
 */

defined( 'ABSPATH' ) || exit;
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( $data['origin']->title ); ?></title>
<link rel="blyg" href="<?php echo esc_url( $data['origin']->url() ); ?>">
<link rel="alternate" type="application/rss+xml" title="<?php echo esc_attr( $data['origin']->title ); ?>" href="<?php echo esc_url( $data['feed'] ); ?>">
<?php wp_print_styles( 'soapbox-blyg-page' ); ?>
</head>
<body>
<main>
<h1><?php echo esc_html( $data['origin']->title ); ?></h1>
<?php if ( '' !== $data['origin']->description ) : ?>
<p><?php echo esc_html( $data['origin']->description ); ?></p>
<?php endif; ?>
<p><?php esc_html_e( 'This is a blyg: a publication you can subscribe to with any Blygger reader, by pasting this address into it, or with any feed reader, using the feed below.', 'soapbox-for-blygger' ); ?></p>
<p><a href="<?php echo esc_url( $data['feed'] ); ?>"><?php esc_html_e( 'Feed', 'soapbox-for-blygger' ); ?></a> &middot; <a href="<?php echo esc_url( $data['manifest'] ); ?>"><?php esc_html_e( 'Manifest', 'soapbox-for-blygger' ); ?></a> &middot; <a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php bloginfo( 'name' ); ?></a></p>
</main>
</body>
</html>
