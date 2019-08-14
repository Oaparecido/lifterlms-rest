<?php
/**
 * Singleton class trait.
 *
 * @package  LifterLMS_REST/Classes
 *
 * @since [version]
 * @version [version]
 */

defined( 'ABSPATH' ) || exit;

/**
 * LLMS_REST_Trait_Singleton class..
 *
 * @since [version]
 */
trait LLMS_REST_Trait_Singleton {

	/**
	 * Singleton instance of the class.
	 *
	 * @var obj
	 */
	private static $instance = null;

	/**
	 * Private Constructor.
	 *
	 * @since [version]
	 *
	 * @return void
	 */
	private function __construct() {}

	/**
	 * Singleton Instance of the LifterLMS_REST_API class.
	 *
	 * @since [version]
	 *
	 * @return obj instance of the LifterLMS_REST_API class.
	 */
	public static function instance() {

		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;

	}

}
