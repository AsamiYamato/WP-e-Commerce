<?php

class WPSC_Router {

	private $controller;
	private $controller_name;
	private $controller_method;
	private $controller_slug;
	private $controller_args;
	private static $instance;

	public static function get_instance() {
		if ( empty( self::$instance ) ) {
			self::$instance = new WPSC_Router();
		}

		return self::$instance;
	}


	public function __get( $name ) {
		// read-only props
		if ( in_array( $name, array(
			'controller',
			'controller_name',
			'controller_method',
			'controller_slug',
			'controller_args',
		) ) ) {
			return $this->$name;
		}

		return null;
	}

	/**
	 * Construct the Router object.
	 *
	 * Use WPSC_Router::get_instance() instead of calling this directly.
	 *
	 * @since  0.1
	 * @access private
	 */
	private function __construct() {
		add_action( 'parse_request', array( $this, '_action_parse_request' ) );
		add_filter( 'query_vars'   , array( $this, '_filter_query_vars' ) );

		if ( wpsc_get_option( 'store_as_front_page' ) ) {
			add_action( 'pre_get_posts', array( $this, '_action_prepare_front_page' ), 1, 1 );
		}

		add_action( 'wp', array( $this, '_action_setup_controller' ), 1 );
	}

	/**
	 * In case the store is set as front page, fool WordPress into thinking
	 * this is a wpsc-product post type archive before the database is queried
	 * for posts.
	 *
	 * Action hook: 'pre_get_post'
	 *
	 * @since  0.1
	 * @access private
	 *
	 * @param  WP_Query $q query object
	 */
	public function _action_prepare_front_page( $q ) {
		if ( ! $q->is_main_query() ) {
			return;
		}

		if ( $q->is_singular() && 'wpsc-product' == $q->get( 'post_type' ) ) {
			add_filter( 'posts_where', array( $this, '_prepare_single_product' ), 10, 2 );
		}

		if ( $this->is_store_front_page() ) {
			$q->set( 'post_type', 'wpsc-product' );
			$q->wpsc_is_store_front_page = true;
			$q->is_post_type_archive     = true;
			$q->is_archive               = true;
		}
	}

	/**
	 * For reasons yet unknown, single product pages 404 when the store is set to the home page.
	 *
	 * This is a less than ideal hack, but it seems to effectively resolve the issue.
	 *
	 * @link   https://github.com/wp-e-commerce/WP-e-Commerce/issues/1832
	 *
	 * @since  4.0
	 * @param  string   $where The 'where' variable in the SQL query generated by WP_Query::get_posts().
	 * @param  WP_Query $wp    The WP_Query object.
	 *
	 * @return string   $where The modified 'where' variable in the SQL query generated by WP_Query::get_posts().
	 */
	public function _prepare_single_product( $where, $wp ) {
		global $wpdb;

		if ( false !== strpos( $where, " AND ($wpdb->posts.ID = '0')" ) ) {

			$product = get_page_by_path( $wp->get( 'pagename' ), OBJECT, 'wpsc-product' );

			$where = str_replace( " AND ($wpdb->posts.ID = '0')", " AND ($wpdb->posts.ID = '$product->ID')", $where );
		}

		return $where;
	}

	/**
	 * Shortcut method, return true if this is the main store, and it is being
	 * displayed as the front page.
	 *
	 * @since  0.1
	 * @access private
	 *
	 * @return boolean
	 */
	private function is_store_front_page() {
		$controller = get_query_var( 'wpsc_controller' );

		$id = get_queried_object_id();

		// is_home() is always true if this is the front page and store is set
		// to be displayed here. This is because 'page_on_front' is set to an
		// empty value whenever wpsc_store_as_front_page is set to true
		return ! $controller && is_home() && ! $id && wpsc_get_option( 'store_as_front_page' );
	}

	/**
	 * Setup the controller object.
	 *
	 * Action hook: wpsc_setup_customer
	 *
	 * @since  0.1
	 * @access private
	 */
	public function _action_setup_controller() {
		// get controller name
		$controller = get_query_var( 'wpsc_controller' );

		// if controller name is not specified, and this is one of WP eCommerce
		// related pages (archive, single, taxonomy), route to the corresponding
		// controller
		if ( ! $controller ) {
			if ( is_post_type_archive( 'wpsc-product' ) || $this->is_store_front_page() ) {
				$controller = 'main-store';
			} elseif ( is_singular( 'wpsc-product' ) ) {
				$controller = 'single';
			} elseif ( is_tax( 'wpsc_product_category' ) ) {
				$controller = 'category';
			}
		}

		// initialize proper query flags in $wp_query
		$this->init_query_flags( $controller );

		// if a corresponding controller is found for this request
		if ( ! empty( $controller ) ) {
			// set header to 200, as WordPress will set it to 404 automatically
			status_header( 200 );

			// initialize the controller object
			$this->init_controller( $controller );
		}
	}

	public function _action_parse_request( &$wp ) {

		if ( empty( $wp->query_vars['wpsc_controller'] ) ) {
			return;
		}

		// Add / remove filters so that unnecessary SQL queries are not executed
		add_filter( 'posts_request'  , array( $this, '_filter_disable_main_query' )     , 10, 2 );
		add_filter( 'split_the_query', array( $this, '_filter_disable_split_the_query' ), 10, 2 );

	}

	public function _filter_disable_main_query( $sql, $query ) {
		if ( ! $query->is_main_query() ) {
			return $sql;
		}

		return '';
	}

	public function _filter_disable_split_the_query( $split, $query ) {
		if ( ! $query->is_main_query() ) {
			return $split;
		}

		return false;
	}

	/**
	 * Initialize $wp_query flags for WPEC controllers.
	 *
	 * @since  0.1
	 * @access private
	 *
	 * @param  string $controller Controller name
	 */
	private function init_query_flags( $controller ) {
		global $wp_query;

		// initialize all controller conditional flags to false
		$props = array_keys( wpsc_get_page_slugs() );

		foreach ( $props as $name ) {
			$prop            = 'wpsc_is_' . str_replace( '-', '_', $name );
			$wp_query->$prop = false;
		}

		$wp_query->wpsc_is_controller = false;

		if ( empty( $controller ) ) {
			return;
		}

		// is_404 is always set to false for our pseudo-pages (cart, checkout,
		// account, login etc.)
		$wp_query->is_404 = false;

		// front page flags
	 	if ( ! $this->is_store_front_page() ) {
			$wp_query->is_home                  = false;
			$wp_query->wpsc_is_store_front_page = false;
		}

		// flip the flag corresponding to this controller
		$wp_query->wpsc_is_controller = true;
		$prop = 'wpsc_is_' . str_replace( '-', '_', $controller );
		$wp_query->$prop = true;
	}

	/**
	 * Used when a controller is erroneously not registered.
	 * Whenever a URL endpoint that should be MVC is non-existant, it should 404.
	 *
	 * @since  4.0
	 * @param  string $template Template path.
	 * @return string           Modified template path.
	 */
	public function redirect_to_404( $template ) {
		$not_found = locate_template( '404.php' );

		if ( ! empty( $not_found ) ) {
			$template = $not_found;
		}

		return $template;
	}

	/**
	 * Sets query parameters and headers to indicate 404 not found, when controller is not found.
	 *
	 * @since  4.0
	 * @return void
	 */
	private function not_found() {
		global $wp_query;

		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();

		add_filter( 'template_include', array( $this, 'redirect_to_404' ) );
	}

	private function init_controller( $controller ) {
		global $wp_query;

		if ( empty( $controller ) ) {
			return;
		}

		$controller_args = trim( get_query_var( 'wpsc_controller_args' ), '/' );
		$controller_args = explode( '/', $controller_args );

		if ( ! is_array( $controller_args ) ) {
			$controller_args = array();
		}

		$slug   = array_shift( $controller_args );

		$method = str_replace( array( ' ', '-' ), '_', $slug );

		if ( ! $method ) {
			$slug = $method = 'index';
		}

		$this->controller_slug   = $slug;
		$this->controller_method = $method;
		$this->controller_name   = $controller;
		$this->controller        = _wpsc_load_controller( $controller, $method );

		// If method/path not found, show the 404 page
		if ( ! is_callable( array( $this->controller, $method ) ) ) {
			_wpsc_doing_it_wrong( __FUNCTION__, __( 'Invalid controller method: ' . get_class( $this->controller ) . '::' . $method . '()', 'wp-e-commerce' ), '4.0' );
			return $this->not_found();
		}

		do_action( 'wpsc_router_init' );

		$this->controller_args = $controller_args;

		if ( is_callable( array( $this->controller, '_pre_action' ) ) ) {
			call_user_func( array( $this->controller, '_pre_action' ), $this->controller_method, $this->controller_args );
		}

		call_user_func_array( array( $this->controller, $this->controller_method ), $this->controller_args );

		if ( is_callable( array( $this->controller, '_post_action' ) ) ) {
			call_user_func( array( $this->controller, '_post_action' ), $this->controller_method, $this->controller_args );
		}

	}

	public function _filter_query_vars( $q ) {
		$q[] = 'wpsc_controller';
		$q[] = 'wpsc_controller_args';

		return $q;
	}
}