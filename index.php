<?php

if ( ! defined( 'SHADOW_TAX_VERSION' ) ) {
	define( 'SHADOW_TAX_VERSION', '0.0.2' );
}

if ( ! defined( 'SHADOW_TAX_PATH' ) ) {
	define( 'SHADOW_TAX_PATH', dirname( __FILE__ ) . '/' );
}

if ( ! defined( 'SHADOW_TAX_INC' ) ) {
	define( 'SHADOW_TAX_INC', SHADOW_TAX_PATH . 'includes/' );
}

require_once SHADOW_TAX_INC . 'shadow-taxonomy.php';
require_once SHADOW_TAX_INC . 'shadow-taxonomy-cli.php';
