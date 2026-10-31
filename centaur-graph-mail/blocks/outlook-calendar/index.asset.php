<?php
defined( 'ABSPATH' ) || exit;

// Dependencies of index.js, which is written without a build step.
return array(
	'dependencies' => array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render', 'wp-i18n' ),
	'version'      => CENTAUR_GRAPH_MAIL_VERSION,
);
