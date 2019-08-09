<?php
/**
 * Base users controller class.
 *
 * @package  LifterLMS_REST/Abstracts
 *
 * @since [version]
 * @version [version]
 */

defined( 'ABSPATH' ) || exit;

/**
 * LLMS_REST_Users_Controller class..
 *
 * @since [version]
 */
abstract class LLMS_REST_Users_Controller extends LLMS_Rest_Controller {

	/**
	 * Resource ID or Name.
	 *
	 * For example: 'student' or 'instructor'
	 *
	 * @var string
	 */
	protected $resource_name;

	/**
	 * Schema properties available for ordering the collection.
	 *
	 * @var string[]
	 */
	protected $orderby_properties = array(
		'id',
		'email',
		'name',
		'registered_date',
	);

	/**
	 * Determine if the current user has permissions to manage the role(s) present in a request.
	 *
	 * @since [version]
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return true|WP_Error
	 */
	protected function check_roles_permissions( $request ) {

		global $wp_roles;

		$schema = $this->get_item_schema();
		$roles  = array();
		if ( ! empty( $request['roles'] ) ) {
			$roles = $request['roles'];
		} elseif ( ! empty( $schema['properties']['roles']['default'] ) ) {
			$roles = $schema['properties']['roles']['default'];
		}

		foreach ( $roles as $role ) {

			if ( ! isset( $wp_roles->role_objects[ $role ] ) ) {
				// Translators: %s = role key.
				return llms_rest_bad_request_error( sprintf( __( 'The role %s does not exist.', 'lifterlms' ), $role ) );
			}

			$potential_role = $wp_roles->role_objects[ $role ];

			/*
			 * Don't let anyone with 'edit_users' (admins) edit their own role to something without it.
			 * Multisite super admins can freely edit their blog roles -- they possess all caps.
			 */
			if ( ! ( is_multisite()
				&& current_user_can( 'manage_sites' ) )
				&& get_current_user_id() === $request['id']
				&& ! $potential_role->has_cap( 'edit_users' )
			) {
				return llms_rest_authorization_required_error( __( 'You are not allowed to give users this role.', 'lifterlms' ) );
			}

			// Include admin functions to get access to get_editable_roles().
			require_once ABSPATH . 'wp-admin/includes/admin.php';

			// The new role must be editable by the logged-in user.
			$editable_roles = get_editable_roles();

			if ( empty( $editable_roles[ $role ] ) ) {
				return llms_rest_authorization_required_error( __( 'You are not allowed to give users this role.', 'lifterlms' ) );
			}

		}

		return true;

	}

	/**
	 * Insert the prepared data into the database.
	 *
	 * @since [version]
	 *
	 * @param array $prepared Prepared item data.
	 * @param WP_REST_Request $request Request object.
	 * @return obj Object Instance of object from $this->get_object().
	 */
	protected function create_object( $prepared, $request ) {

		$object_id = wp_insert_user( $prepared );

		if ( is_wp_error( $object_id ) ) {
			return $object_id;
		}

		return $this->update_additional_data( $object_id, $prepared, $request );

	}


	/**
	 * Delete the object.
	 *
	 * Note: we do not return 404s when the resource to delete cannot be found. We assume it's already been deleted and respond with 204.
	 * Errors returned by this method should be any error other than a 404!
	 *
	 * @since [version]
	 *
	 * @param obj $object Instance of the object from $this->get_object().
	 * @param WP_REST_Request $request Request object.
	 * @return true|WP_Error true when the object is removed, WP_Error on failure.
	 */
	protected function delete_object( $object, $request ) {

		$id       = $object->get( 'id' );
		$reassign = 0 === $request['reassign'] ? null : $request['reassign'];

		if ( ! empty( $reassign ) ) {
			if ( $reassign === $id || ! get_userdata( $reassign ) ) {
				return llms_rest_bad_request_error( __( 'Invalid user ID for reassignment.', 'lifterlms' ) );
			}
		}

		// Include admin user functions to get access to wp_delete_user().
		require_once ABSPATH . 'wp-admin/includes/user.php';

		$result = wp_delete_user( $id, $reassign );

		if ( ! $result ) {
			return llms_rest_server_error( __( 'The user could not be deleted.', 'lifterlms' ) );
		}

		return true;

	}

	/**
	 * Retrieves the query params for the objects collection.
	 *
	 * @since [version]
	 *
	 * @return array Collection parameters.
	 */
	public function get_collection_params() {

		$params = parent::get_collection_params();

		$params['roles'] = array(
			'description' => __( 'Include only API keys matching a specific permission.', 'lifterlms' ),
			'type'        => 'string',
			'enum'        => $this->get_enum_roles(),
		);

		return $params;

	}

	/**
	 * Retrieve arguments for deleting a resource.
	 *
	 * @since [version]
	 *
	 * @return array
	 */
	public function get_delete_item_args() {
		return array(
			'reassign' => array(
				'type'              => 'integer',
				'description'       => __( 'Reassign the deleted user\'s posts and links to this user ID.', 'lifterlms' ),
				'default'           => 0,
				'sanitize_callback' => 'absint',
			),
		);
	}

	/**
	 * Retrieve an array of allowed user role values.
	 *
	 * @since [version]
	 *
	 * @return string[]
	 */
	protected function get_enum_roles() {

		global $wp_roles;
		return array_keys( $wp_roles->roles );

	}

	/**
	 * Get the item schema.
	 *
	 * @since [version]
	 *
	 * @return array
	 */
	public function get_item_schema() {

		$schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => $this->resource_name,
			'type'       => 'object',
			'properties' => array(
				'id'                 => array(
					'description' => __( 'Unique identifier for the user.', 'lifterlms' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'username'           => array(
					'description' => __( 'Login name for the user.', 'lifterlms' ),
					'type'        => 'string',
					'context'     => array( 'edit' ),
					'arg_options' => array(
						'sanitize_callback' => array( $this, 'sanitize_username' ),
					),
				),
				'name'       => array(
					'description' => __( 'Display name for the user.', 'lifterlms' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
				'first_name'         => array(
					'description' => __( 'First name for the user.', 'lifterlms' ),
					'type'        => 'string',
					'context'     => array( 'edit' ),
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
				'last_name'          => array(
					'description' => __( 'Last name for the user.', 'lifterlms' ),
					'type'        => 'string',
					'context'     => array( 'edit' ),
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
				'email'              => array(
					'description' => __( 'The email address for the user.', 'lifterlms' ),
					'type'        => 'string',
					'format'      => 'email',
					'context'     => array( 'edit' ),
					'required'    => true,
				),
				'url'                => array(
					'description' => __( 'URL of the user.', 'lifterlms' ),
					'type'        => 'string',
					'format'      => 'uri',
					'context'     => array( 'view', 'edit' ),
				),
				'description'        => array(
					'description' => __( 'Description of the user.', 'lifterlms' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
				),
				'nickname'           => array(
					'description' => __( 'The nickname for the user.', 'lifterlms' ),
					'type'        => 'string',
					'context'     => array( 'edit' ),
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
				'registered_date'    => array(
					'description' => __( 'Registration date for the user.', 'lifterlms' ),
					'type'        => 'string',
					'format'      => 'date-time',
					'context'     => array( 'edit' ),
					'readonly'    => true,
				),
				'roles'              => array(
					'description' => __( 'Roles assigned to the user.', 'lifterlms' ),
					'type'        => 'array',
					'items'       => array(
						'type' => 'string',
						'enum' => $this->get_enum_roles(),
					),
					'context'     => array( 'edit' ),
					'default'     => array( 'student' ),
				),
				'password'           => array(
					'description' => __( 'Password for the user (never included).', 'lifterlms' ),
					'type'        => 'string',
					'context'     => array(), // Password is never displayed.
					'arg_options' => array(
						'sanitize_callback' => array( $this, 'sanitize_password' ),
					),
				),
				'billing_address_1'           => array(
					'description' => __( 'User address line 1.', 'lifterlms' ),
					'type'        => 'string',
					'context'     => array( 'edit' ),
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
				'billing_address_2'           => array(
					'description' => __( 'User address line 2.', 'lifterlms' ),
					'type'        => 'string',
					'context'     => array( 'edit' ),
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
				'billing_city'           => array(
					'description' => __( 'User address city name.', 'lifterlms' ),
					'type'        => 'string',
					'context'     => array( 'edit' ),
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
				'billing_state'           => array(
					'description' => __( 'User address ISO code for the state, province, or district.', 'lifterlms' ),
					'type'        => 'string',
					'context'     => array( 'edit' ),
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
				'billing_postcode'           => array(
					'description' => __( 'User address postal code.', 'lifterlms' ),
					'type'        => 'string',
					'context'     => array( 'edit' ),
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
				'billing_country'           => array(
					'description' => __( 'User address ISO code for the country.', 'lifterlms' ),
					'type'        => 'string',
					'context'     => array( 'edit' ),
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			),
		);

		if ( get_option( 'show_avatars' ) ) {

			$avatar_properties = array();
			foreach ( rest_get_avatar_sizes() as $size ) {
				$avatar_properties[ $size ] = array(
					// Translators: %d = avatar image size in pixels.
					'description' => sprintf( __( 'Avatar URL with image size of %d pixels.', 'lifterlms' ), $size ),
					'type'        => 'string',
					'format'      => 'uri',
					'context'     => array( 'view', 'edit' ),
				);
			}

			$schema['properties']['avatar_urls'] = array(
				'description' => __( 'Avatar URLs for the user.', 'lifterlms' ),
				'type'        => 'object',
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
				'properties'  => $avatar_properties,
			);

		}

		return $schema;

	}

	/**
	 * Map request keys to database keys for insertion.
	 *
	 * Array keys are the request fields (as defined in the schema) and
	 * array values are the database fields.
	 *
	 * @since [version]
	 *
	 * @return array
	 */
	protected function map_schema_to_database() {

		$map = parent::map_schema_to_database();

		$map['username'] = 'user_login';
		$map['password'] = 'user_pass';
		$map['name'] = 'display_name';
		$map['email'] = 'user_email';
		$map['url'] = 'user_url';
		$map['registered_date'] = 'user_registered';

		// Not inserted/read via database calls.
		unset( $map['roles'], $map['avatar_urls'] );

		return $map;

	}

	/**
	 * Prepare request arguments for a database insert/update.
	 *
	 * @since [version]
	 *
	 * @param WP_Rest_Request $request Request object.
	 * @return array
	 */
	protected function prepare_item_for_database( $request ) {

		$prepared = parent::prepare_item_for_database( $request );

		// If we're creating a new item, maybe add some defaults.
		if ( empty( $prepared['id'] ) ) {

			// Pass an explicit false to wp_insert_user().
			$prepared['role'] = false;

			if ( empty( $prepared['user_pass'] ) ) {
				$prepared['user_pass'] = wp_generate_password( 22 );
			}

			if ( empty( $prepared['user_login'] ) ) {
				$prepared['user_login'] = LLMS_Person_Handler::generate_username( $prepared['user_email'] );
			}

		}

		return $prepared;

	}

	/**
	 * Prepare an object for response.
	 *
	 * @since [version]
	 *
	 * @param LLMS_Abstract_User_Data $object User object.
	 * @param WP_REST_Request $request Request object.
	 * @return array
	 */
	protected function prepare_object_for_response( $object, $request ) {

		$prepared = array();
		$map      = array_flip( $this->map_schema_to_database() );
		$fields   = $this->get_fields_for_response( $request );

		// Write Only.
		unset( $map['user_pass'] );

		foreach ( $map as $db_key => $schema_key ) {
			$prepared[ $schema_key ] = $object->get( $db_key );
		}

		if ( in_array( 'roles', $fields, true ) ) {
			$prepared['roles'] = $object->get_user()->roles;
		}

		if ( in_array( 'avatar_urls', $fields, true ) ) {
			$prepared['avatar_urls'] = rest_get_avatar_urls( $object->get( 'user_email' ) );
		}

		return $prepared;

	}

	/**
	 * Validate a username is valid and allowed.
	 *
	 * @since [version]
	 *
	 * @param string $value User-submitted username.
	 * @param WP_REST_Request $request Request object.
	 * @param string $param Parameter name.
	 * @return WP_Error|string Sanitized username if valid or error object.
	 */
	public function sanitize_password( $value, $request, $param ) {

		$password = (string) $value;

		if ( false !== strpos( $password, '\\' ) ) {
			return llms_rest_bad_request_error( __( 'Passwords cannot contain the "\\" character.', 'lifterlms' ) );
		}

		// @todo: Should validate against password strength too, maybe?

		return $password;

	}

	/**
	 * Validate a username is valid and allowed.
	 *
	 * @since [version]
	 *
	 * @param string $value User-submitted username.
	 * @param WP_REST_Request $request Request object.
	 * @param string $param Parameter name.
	 * @return WP_Error|string Sanitized username if valid or error object.
	 */
	public function sanitize_username( $value, $request, $param ) {

		$username = (string) $value;

		if ( ! validate_username( $username ) ) {
			return llms_rest_bad_request_error( __( 'Username contains invalid characters.', 'lifterlms' ) );
		}

		/**
		 * Filter defined in WP Core.
		 *
		 * @link  https://developer.wordpress.org/reference/hooks/illegal_user_logins/
		 *
		 * @param array $illegal_logins Array of banned usernames.
		 */
		$illegal_logins = (array) apply_filters( 'illegal_user_logins', array() );
		if ( in_array( strtolower( $username ), array_map( 'strtolower', $illegal_logins ), true ) ) {
			return llms_rest_bad_request_error( __( 'Username is not allowed.', 'lifterlms' ) );
		}

		return $username;

	}

	/**
	 * Update the object in the database with prepared data.
	 *
	 * @since [version]
	 *
	 * @param array $prepared Prepared item data.
	 * @param WP_REST_Request $request Request object.
	 * @return obj Object Instance of object from $this->get_object().
	 */
	protected function update_object( $prepared, $request ) {

		$prepared['ID'] = $prepared['id'];

		$object_id = wp_update_user( $prepared );
		if ( is_wp_error( $object_id ) ) {
			return $object_id;
		}

		unset( $prepared['ID'] );

		return $this->update_additional_data( $object_id, $prepared, $request );

	}

	/**
	 * Updates additional information not handled by WP Core insert/update user functions.
	 *
	 * @since [version]
	 *
	 * @param int $object_id WP User id.
	 * @param array $prepared Prepared item data.
	 * @param WP_REST_Request $request Request object.
	 * @return LLMS_Abstract_User_Data|WP_error
	 */
	protected function update_additional_data( $object_id, $prepared, $request ) {

		$object = $this->get_object( $object_id );

		if ( is_wp_error( $object ) ) {
			return $object;
		}

		$metas = array(
			'billing_address_1',
			'billing_address_2',
			'billing_city',
			'billing_state',
			'billing_postcode',
			'billing_country',
		);

		foreach ( $metas as $meta ) {
			if ( ! empty( $prepared[ $meta ] ) ) {
				$object->set( $meta, $prepared[ $meta ] );
			}
		}

		if ( ! empty( $request['roles'] ) ) {
			$user = $object->get_user();
			array_map( array( $user, 'add_role' ), $request['roles'] );
		}

		return $object;

	}

}
