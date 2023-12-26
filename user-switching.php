<?php
/**
 * 
 *
 * @package   user-switching
 * @link      
 * @author    
 * @copyright 
 * @license   
 *
 * Plugin Name:       User Switch
 * Description:       Plugin for https://ua.noskar.com/
 * Version:           0.1
 * Plugin URI:        
 * Author:            Arthur Pereyaslov
 * Author URI:        https://github.com/Haket001?tab=repositories
 * Text Domain:       user-switching
 * Domain Path:       
 * Network:           true
 * Requires at least: 5.6
 * Requires PHP:      7.0
 * License URI:       
 *
 *
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class user_switching {
	public static $application = 'WordPress/User Switching';

	const REDIRECT_TYPE_NONE = null;
	const REDIRECT_TYPE_URL = 'url';
	const REDIRECT_TYPE_POST = 'post';
	const REDIRECT_TYPE_TERM = 'term';
	const REDIRECT_TYPE_USER = 'user';
	const REDIRECT_TYPE_COMMENT = 'comment';
	public function init_hooks() {
		add_filter( 'user_has_cap', array( $this, 'filter_user_has_cap' ), 10, 4 );
		add_filter( 'map_meta_cap', array( $this, 'filter_map_meta_cap' ), 10, 4 );
		add_filter( 'user_row_actions', array( $this, 'filter_user_row_actions' ), 10, 2 );
		add_action( 'plugins_loaded', array( $this, 'action_plugins_loaded' ), 1 );
		add_action( 'init', array( $this, 'action_init' ) );
		add_action( 'all_admin_notices', array( $this, 'action_admin_notices' ), 1 );
		add_action( 'wp_logout', 'user_switching_clear_olduser_cookie' );
		add_action( 'wp_login', 'user_switching_clear_olduser_cookie' );
		add_filter( 'ms_user_row_actions', array( $this, 'filter_user_row_actions' ), 10, 2 );
		add_filter( 'login_message', array( $this, 'filter_login_message' ), 1 );
		add_filter( 'removable_query_args', array( $this, 'filter_removable_query_args' ) );
		add_action( 'wp_meta', array( $this, 'action_wp_meta' ) );
		add_filter( 'plugin_row_meta', array( $this, 'filter_plugin_row_meta' ), 10, 2 );
		add_action( 'wp_footer', array( $this, 'action_wp_footer' ) );
		add_action( 'personal_options', array( $this, 'action_personal_options' ) );
		add_action( 'admin_bar_menu', array( $this, 'action_admin_bar_menu' ), 11 );
		add_action( 'bp_member_header_actions', array( $this, 'action_bp_button' ), 11 );
		add_action( 'bp_directory_members_actions', array( $this, 'action_bp_button' ), 11 );
		add_action( 'bbp_template_after_user_details_menu_items', array( $this, 'action_bbpress_button' ) );
		add_action( 'woocommerce_login_form_start', array( $this, 'action_woocommerce_login_form_start' ), 10, 0 );
		add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'action_woocommerce_order_details' ), 1 );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'filter_woocommerce_account_menu_items' ), 999 );
		add_filter( 'woocommerce_get_endpoint_url', array( $this, 'filter_woocommerce_get_endpoint_url' ), 10, 2 );
		add_action( 'switch_to_user', array( $this, 'forget_woocommerce_session' ) );
		add_action( 'switch_back_user', array( $this, 'forget_woocommerce_session' ) );
	}
	public function action_plugins_loaded() {
		if ( ! defined( 'USER_SWITCHING_COOKIE' ) ) {
			define( 'USER_SWITCHING_COOKIE', 'wordpress_user_sw_' . COOKIEHASH );
		}

		if ( ! defined( 'USER_SWITCHING_SECURE_COOKIE' ) ) {
			define( 'USER_SWITCHING_SECURE_COOKIE', 'wordpress_user_sw_secure_' . COOKIEHASH );
		}

		if ( ! defined( 'USER_SWITCHING_OLDUSER_COOKIE' ) ) {
			define( 'USER_SWITCHING_OLDUSER_COOKIE', 'wordpress_user_sw_olduser_' . COOKIEHASH );
		}
	}

	public function action_personal_options( WP_User $user ) {
		$link = self::maybe_switch_url( $user );

		if ( ! $link ) {
			return;
		}

		?>
		<tr class="user-switching-wrap">
			<th scope="row">
				<?php echo esc_html_x( 'User Switching', 'User Switching title on user profile screen', 'user-switching' ); ?>
			</th>
			<td>
				<a id="user_switching_switcher" href="<?php echo esc_url( $link ); ?>">
					<?php esc_html_e( 'Switch&nbsp;To', 'user-switching' ); ?>
				</a>
			</td>
		</tr>
		<?php
	}

	public static function remember() {
		$cookie_life = apply_filters( 'auth_cookie_expiration', 172800, get_current_user_id(), false );
		$current = wp_parse_auth_cookie( '', 'logged_in' );

		if ( ! $current ) {
			return false;
		}
		return ( intval( $current['expiration'] ) - time() > $cookie_life );
	}

	public function action_init() {
		load_plugin_textdomain( 'user-switching', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

		if ( ! isset( $_REQUEST['action'] ) ) {
			return;
		}

		$current_user = ( is_user_logged_in() ) ? wp_get_current_user() : null;

		switch ( $_REQUEST['action'] ) {

			case 'switch_to_user':
				$user_id = absint( $_REQUEST['user_id'] ?? 0 );

				if ( ! current_user_can( 'switch_to_user', $user_id ) ) {
					wp_die( esc_html__( 'Could not switch users.', 'user-switching' ), 403 );
				}

				check_admin_referer( "switch_to_user_{$user_id}" );

				$user = switch_to_user( $user_id, self::remember() );
				if ( $user ) {
					$redirect_to = self::get_redirect( $user, $current_user );

					$args = array(
						'user_switched' => 'true',
					);

					if ( $redirect_to ) {
						wp_safe_redirect( add_query_arg( $args, $redirect_to ), 302, self::$application );
					} elseif ( ! current_user_can( 'read' ) ) {
						wp_safe_redirect( add_query_arg( $args, home_url() ), 302, self::$application );
					} else {
						wp_safe_redirect( add_query_arg( $args, admin_url() ), 302, self::$application );
					}
					exit;
				} else {
					wp_die( esc_html__( 'Could not switch users.', 'user-switching' ), 404 );
				}
				break;
			case 'switch_to_olduser':

				$old_user = self::get_old_user();
				if ( ! $old_user ) {
					wp_die( esc_html__( 'Could not switch users.', 'user-switching' ), 400 );
				}

				if ( ! self::authenticate_old_user( $old_user ) ) {
					wp_die( esc_html__( 'Could not switch users.', 'user-switching' ), 403 );
				}

				check_admin_referer( "switch_to_olduser_{$old_user->ID}" );


				if ( switch_to_user( $old_user->ID, self::remember(), false ) ) {

					if ( ! empty( $_REQUEST['interim-login'] ) && function_exists( 'login_header' ) ) {
						$GLOBALS['interim_login'] = 'success'; 
						login_header( '', '' );
						exit;
					}

					$redirect_to = self::get_redirect( $old_user, $current_user );
					$args = array(
						'user_switched' => 'true',
						'switched_back' => 'true',
					);

					if ( $redirect_to ) {
						wp_safe_redirect( add_query_arg( $args, $redirect_to ), 302, self::$application );
					} else {
						wp_safe_redirect( add_query_arg( $args, admin_url( 'users.php' ) ), 302, self::$application );
					}
					exit;
				} else {
					wp_die( esc_html__( 'Could not switch users.', 'user-switching' ), 404 );
				}
				break;

			case 'switch_off':
	
				if ( ! $current_user || ! current_user_can( 'switch_off' ) ) {
					wp_die( esc_html__( 'Could not switch off.', 'user-switching' ), 403 );
				}

				check_admin_referer( "switch_off_{$current_user->ID}" );

				if ( switch_off_user() ) {
					$redirect_to = self::get_redirect( null, $current_user );
					$args = array(
						'switched_off' => 'true',
					);

					if ( $redirect_to ) {
						wp_safe_redirect( add_query_arg( $args, $redirect_to ), 302, self::$application );
					} else {
						wp_safe_redirect( add_query_arg( $args, home_url() ), 302, self::$application );
					}
					exit;
				} else {
					wp_die( esc_html__( 'Could not switch off.', 'user-switching' ), 403 );
				}
				break;

		}
	}

	protected static function get_redirect( WP_User $new_user = null, WP_User $old_user = null ) {
		$redirect_to = '';
		$requested_redirect_to = '';
		$redirect_type = self::REDIRECT_TYPE_NONE;

		if ( ! empty( $_REQUEST['redirect_to'] ) ) {
			$redirect_to = self::remove_query_args( wp_unslash( $_REQUEST['redirect_to'] ) );
			$requested_redirect_to = wp_unslash( $_REQUEST['redirect_to'] );
			$redirect_type = self::REDIRECT_TYPE_URL;
		} elseif ( ! empty( $_GET['redirect_to_post'] ) ) {
			$post_id = absint( $_GET['redirect_to_post'] );
			$redirect_type = self::REDIRECT_TYPE_POST;

			if ( function_exists( 'is_post_publicly_viewable' ) && is_post_publicly_viewable( $post_id ) ) {
				$link = get_permalink( $post_id );

				if ( is_string( $link ) ) {
					$redirect_to = $link;
					$requested_redirect_to = $link;
				}
			}
		} elseif ( ! empty( $_GET['redirect_to_term'] ) ) {
			$term = get_term( absint( $_GET['redirect_to_term'] ) );
			$redirect_type = self::REDIRECT_TYPE_TERM;

			if ( ( $term instanceof WP_Term ) && is_taxonomy_viewable( $term->taxonomy ) ) {
				$link = get_term_link( $term );

				if ( is_string( $link ) ) {
					$redirect_to = $link;
					$requested_redirect_to = $link;
				}
			}
		} elseif ( ! empty( $_GET['redirect_to_user'] ) ) {
			$user = get_userdata( absint( $_GET['redirect_to_user'] ) );
			$redirect_type = self::REDIRECT_TYPE_USER;

			if ( $user instanceof WP_User ) {
				$link = get_author_posts_url( $user->ID );

				if ( is_string( $link ) ) {
					$redirect_to = $link;
					$requested_redirect_to = $link;
				}
			}
		} elseif ( ! empty( $_GET['redirect_to_comment'] ) ) {
			$comment = get_comment( absint( $_GET['redirect_to_comment'] ) );
			$redirect_type = self::REDIRECT_TYPE_COMMENT;

			if ( $comment instanceof WP_Comment ) {
				if ( 'approved' === wp_get_comment_status( $comment ) ) {
					$link = get_comment_link( $comment );

					if ( is_string( $link ) ) {
						$redirect_to = $link;
						$requested_redirect_to = $link;
					}
				} elseif ( function_exists( 'is_post_publicly_viewable' ) && is_post_publicly_viewable( (int) $comment->comment_post_ID ) ) {
					$link = get_permalink( (int) $comment->comment_post_ID );

					if ( is_string( $link ) ) {
						$redirect_to = $link;
						$requested_redirect_to = $link;
					}
				}
			}
		}

		if ( ! $new_user ) {
			$redirect_to = apply_filters( 'logout_redirect', $redirect_to, $requested_redirect_to, $old_user );
		} else {
			$redirect_to = apply_filters( 'login_redirect', $redirect_to, $requested_redirect_to, $new_user );
		}
		return apply_filters( 'user_switching_redirect_to', $redirect_to, $redirect_type, $new_user, $old_user );
	}

	public function action_admin_notices() {
		$user = wp_get_current_user();
		$old_user = self::get_old_user();

		if ( $old_user ) {
			$switched_locale = false;
			$lang_attr = '';
			$locale = get_user_locale( $old_user );
			$switched_locale = switch_to_locale( $locale );
			$lang_attr = str_replace( '_', '-', $locale );

			?>
			<div id="user_switching" class="updated notice notice-success is-dismissible">
				<?php
				if ( $lang_attr ) {
					printf(
						'<p lang="%s">',
						esc_attr( $lang_attr )
					);
				} else {
					echo '<p>';
				}
				?>
				<span class="dashicons dashicons-admin-users" style="color:#56c234" aria-hidden="true"></span>
				<?php
				$message = '';
				$just_switched = isset( $_GET['user_switched'] );
				if ( $just_switched ) {
					$message = esc_html( self::switched_to_message( $user ) );
				}
				$switch_back_url = add_query_arg( array(
					'redirect_to' => rawurlencode( self::current_url() ),
				), self::switch_back_url( $old_user ) );

				$message .= sprintf(
					' <a href="%s">%s</a>.',
					esc_url( $switch_back_url ),
					esc_html( self::switch_back_message( $old_user ) )
				);

				$message = apply_filters( 'user_switching_switched_message', $message, $user, $old_user, $switch_back_url, $just_switched );

				echo wp_kses( $message, array(
					'a' => array(
						'href' => array(),
					),
				) );
				?>
				</p>
			</div>
			<?php
			if ( $switched_locale ) {
				restore_previous_locale();
			}
		} elseif ( isset( $_GET['user_switched'] ) ) {
			?>
			<div id="user_switching" class="updated notice notice-success is-dismissible">
				<p>
				<?php
				if ( isset( $_GET['switched_back'] ) ) {
					echo esc_html( self::switched_back_message( $user ) );
				} else {
					echo esc_html( self::switched_to_message( $user ) );
				}
				?>
				</p>
			</div>
			<?php
		}
	}

	public static function get_old_user() {
		$cookie = user_switching_get_olduser_cookie();
		if ( ! empty( $cookie ) ) {
			$old_user_id = wp_validate_auth_cookie( $cookie, 'logged_in' );

			if ( $old_user_id ) {
				return get_userdata( $old_user_id );
			}
		}
		return false;
	}


	public static function authenticate_old_user( WP_User $user ) {
		$cookie = user_switching_get_auth_cookie();
		if ( ! empty( $cookie ) ) {
			if ( self::secure_auth_cookie() ) {
				$scheme = 'secure_auth';
			} else {
				$scheme = 'auth';
			}

			$old_user_id = wp_validate_auth_cookie( end( $cookie ), $scheme );

			if ( $old_user_id ) {
				return ( $user->ID === $old_user_id );
			}
		}
		return false;
	}


	public function action_admin_bar_menu( WP_Admin_Bar $wp_admin_bar ) {
		if ( ! is_admin_bar_showing() ) {
			return;
		}

		if ( $wp_admin_bar->get_node( 'user-actions' ) ) {
			$parent = 'user-actions';
		} else {
			return;
		}

		$old_user = self::get_old_user();

		if ( $old_user ) {
			$wp_admin_bar->add_node( array(
				'parent' => $parent,
				'id' => 'switch-back',
				'title' => esc_html( self::switch_back_message( $old_user ) ),
				'href' => add_query_arg( array(
					'redirect_to' => rawurlencode( self::current_url() ),
				), self::switch_back_url( $old_user ) ),
			) );
		}

		if ( current_user_can( 'switch_off' ) ) {
			$url = self::switch_off_url( wp_get_current_user() );
			$redirect_to = is_admin() ? self::get_admin_redirect_to() : array(
				'redirect_to' => rawurlencode( self::current_url() ),
			);

			if ( is_array( $redirect_to ) ) {
				$url = add_query_arg( $redirect_to, $url );
			}

			$wp_admin_bar->add_node( array(
				'parent' => $parent,
				'id' => 'switch-off',
				'title' => esc_html__( 'Switch Off', 'user-switching' ),
				'href' => $url,
			) );
		}

		if ( ! is_admin() && is_author() && ( get_queried_object() instanceof WP_User ) ) {
			if ( $old_user ) {
				$wp_admin_bar->add_node( array(
					'parent' => 'edit',
					'id' => 'author-switch-back',
					'title' => esc_html( self::switch_back_message( $old_user ) ),
					'href' => add_query_arg( array(
						'redirect_to' => rawurlencode( self::current_url() ),
					), self::switch_back_url( $old_user ) ),
				) );
			} elseif ( current_user_can( 'switch_to_user', get_queried_object_id() ) ) {
				$wp_admin_bar->add_node( array(
					'parent' => 'edit',
					'id' => 'author-switch-to',
					'title' => esc_html__( 'Switch&nbsp;To', 'user-switching' ),
					'href' => add_query_arg( array(
						'redirect_to' => rawurlencode( self::current_url() ),
					), self::switch_to_url( get_queried_object() ) ),
				) );
			}
		}
	}

	public static function get_admin_redirect_to() {
		if ( ! empty( $_GET['post'] ) ) {
			return array(
				'redirect_to_post' => intval( $_GET['post'] ),
			);
		} elseif ( ! empty( $_GET['tag_ID'] ) ) {
			return array(
				'redirect_to_term' => intval( $_GET['tag_ID'] ),
			);
		} elseif ( ! empty( $_GET['user_id'] ) ) {
			return array(
				'redirect_to_user' => intval( $_GET['user_id'] ),
			);
		} elseif ( ! empty( $_GET['c'] ) ) {
			return array(
				'redirect_to_comment' => intval( $_GET['c'] ),
			);
		}

		return null;
	}

	public function action_wp_meta() {
		$old_user = self::get_old_user();

		if ( $old_user instanceof WP_User ) {
			$url = add_query_arg( array(
				'redirect_to' => rawurlencode( self::current_url() ),
			), self::switch_back_url( $old_user ) );
			printf(
				'<li id="user_switching_switch_on"><a href="%s">%s</a></li>',
				esc_url( $url ),
				esc_html( self::switch_back_message( $old_user ) )
			);
		}
	}

	public function action_wp_footer() {
		if ( is_admin_bar_showing() || did_action( 'wp_meta' ) ) {
			return;
		}

		if ( ! apply_filters( 'user_switching_in_footer', true ) ) {
			return;
		}

		$old_user = self::get_old_user();

		if ( $old_user instanceof WP_User ) {
			$url = add_query_arg( array(
				'redirect_to' => rawurlencode( self::current_url() ),
			), self::switch_back_url( $old_user ) );
			printf(
				'<p id="user_switching_switch_on" style="position:fixed;bottom:40px;padding:0;margin:0;left:10px;font-size:13px;z-index:99999;"><a href="%s">%s</a></p>',
				esc_url( $url ),
				esc_html( self::switch_back_message( $old_user ) )
			);
		}
	}

	public function filter_login_message( $message ) {
		$old_user = self::get_old_user();

		if ( $old_user instanceof WP_User ) {
			$url = self::switch_back_url( $old_user );

			if ( ! empty( $_REQUEST['interim-login'] ) ) {
				$url = add_query_arg( array(
					'interim-login' => '1',
				), $url );
			} elseif ( ! empty( $_REQUEST['redirect_to'] ) ) {
				$url = add_query_arg( array(
					'redirect_to' => rawurlencode( wp_unslash( $_REQUEST['redirect_to'] ) ),
				), $url );
			}

			$message .= '<p class="message" id="user_switching_switch_on">';
			$message .= '<span class="dashicons dashicons-admin-users" style="color:#56c234" aria-hidden="true"></span> ';
			$message .= sprintf(
				'<a href="%1$s" onclick="window.location.href=\'%1$s\';return false;">%2$s</a>',
				esc_url( $url ),
				esc_html( self::switch_back_message( $old_user ) )
			);
			$message .= '</p>';
		}

		return $message;
	}

	public function filter_user_row_actions( array $actions, WP_User $user ) {
		$link = self::maybe_switch_url( $user );

		if ( ! $link ) {
			return $actions;
		}

		$actions['switch_to_user'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $link ),
			esc_html__( 'Switch&nbsp;To', 'user-switching' )
		);

		return $actions;
	}

	public function action_bp_button() {
		$user = null;

		if ( bp_is_user() ) {
			$user = get_userdata( bp_displayed_user_id() );
		} elseif ( bp_is_members_directory() ) {
			$user = get_userdata( bp_get_member_user_id() );
		}

		if ( ! $user ) {
			return;
		}

		$link = self::maybe_switch_url( $user );

		if ( ! $link ) {
			return;
		}

		$link = add_query_arg( array(
			'redirect_to' => rawurlencode( bp_core_get_user_domain( $user->ID ) ),
		), $link );

		$components = array_keys( buddypress()->active_components );

		echo bp_get_button( array(
			'id' => 'user_switching',
			'component' => reset( $components ),
			'link_href' => esc_url( $link ),
			'link_text' => esc_html__( 'Switch&nbsp;To', 'user-switching' ),
			'wrapper_id' => 'user_switching_switch_to',
		) );
	}

	public function action_bbpress_button() {
		$user = get_userdata( bbp_get_user_id() );

		if ( ! $user ) {
			return;
		}

		$link = self::maybe_switch_url( $user );

		if ( ! $link ) {
			return;
		}

		$link = add_query_arg( array(
			'redirect_to' => rawurlencode( bbp_get_user_profile_url( $user->ID ) ),
		), $link );

		echo '<ul id="user_switching_switch_to">';
		printf(
			'<li><a href="%s">%s</a></li>',
			esc_url( $link ),
			esc_html__( 'Switch&nbsp;To', 'user-switching' )
		);
		echo '</ul>';
	}

	public function filter_removable_query_args( array $args ) {
		return array_merge( $args, array(
			'user_switched',
			'switched_off',
			'switched_back',
		) );
	}

	public static function maybe_switch_url( WP_User $user ) {
		$old_user = self::get_old_user();

		if ( $old_user && ( $old_user->ID === $user->ID ) ) {
			return self::switch_back_url( $old_user );
		} elseif ( current_user_can( 'switch_to_user', $user->ID ) ) {
			return self::switch_to_url( $user );
		} else {
			return false;
		}
	}

	public static function switch_to_url( WP_User $user ) {
		return wp_nonce_url( add_query_arg( array(
			'action' => 'switch_to_user',
			'user_id' => $user->ID,
			'nr' => 1,
		), wp_login_url() ), "switch_to_user_{$user->ID}" );
	}

	public static function switch_back_url( WP_User $user ) {
		return wp_nonce_url( add_query_arg( array(
			'action' => 'switch_to_olduser',
			'nr' => 1,
		), wp_login_url() ), "switch_to_olduser_{$user->ID}" );
	}

	public static function switch_off_url( WP_User $user ) {
		return wp_nonce_url( add_query_arg( array(
			'action' => 'switch_off',
			'nr' => 1,
		), wp_login_url() ), "switch_off_{$user->ID}" );
	}

	public static function switched_to_message( WP_User $user ) {
		$message = sprintf(
			__( 'Switched to %1$s (%2$s).', 'user-switching' ),
			$user->display_name,
			$user->user_login
		);

		return str_replace( sprintf(
			' (%s)',
			$user->user_login
		), '', $message );
	}

	public static function switch_back_message( WP_User $user ) {
		$message = sprintf(
			__( 'Switch back to %1$s (%2$s)', 'user-switching' ),
			$user->display_name,
			$user->user_login
		);
		return str_replace( sprintf(
			' (%s)',
			$user->user_login
		), '', $message );
	}

	public static function switched_back_message( WP_User $user ) {
		$message = sprintf(
			__( 'Switched back to %1$s (%2$s).', 'user-switching' ),
			$user->display_name,
			$user->user_login
		);

		return str_replace( sprintf(
			' (%s)',
			$user->user_login
		), '', $message );
	}

	public static function current_url() {
		return ( is_ssl() ? 'https://' : 'http://' ) . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
	}

	public static function remove_query_args( $url ) {
		return remove_query_arg( wp_removable_query_args(), $url );
	}

	public static function secure_olduser_cookie() {
		return ( is_ssl() && ( 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME ) ) );
	}
	public static function secure_auth_cookie() {
		return ( is_ssl() && ( 'https' === wp_parse_url( wp_login_url(), PHP_URL_SCHEME ) ) );
	}

	public function action_woocommerce_login_form_start() {
		echo $this->filter_login_message( '' );
	}

	public function action_woocommerce_order_details( WC_Order $order ) {
		$user = $order->get_user();

		if ( ! $user || ! current_user_can( 'switch_to_user', $user->ID ) ) {
			return;
		}

		$url = add_query_arg( array(
			'redirect_to' => rawurlencode( $order->get_view_order_url() ),
		), self::switch_to_url( $user ) );

		printf(
			'<p class="form-field form-field-wide"><a href="%1$s">%2$s</a></p>',
			esc_url( $url ),
			esc_html__( 'Switch&nbsp;To', 'user-switching' )
		);
	}

	public function filter_woocommerce_account_menu_items( array $items ) {
		$old_user = self::get_old_user();

		if ( ! $old_user ) {
			return $items;
		}

		$items['user-switching-switch-back'] = self::switch_back_message( $old_user );

		return $items;
	}

	public function filter_woocommerce_get_endpoint_url( $url, $endpoint ) {
		if ( 'user-switching-switch-back' !== $endpoint ) {
			return $url;
		}

		$old_user = self::get_old_user();

		if ( ! $old_user ) {
			return $url;
		}

		return self::switch_back_url( $old_user );
	}

	public function forget_woocommerce_session() {
		if ( ! function_exists( 'WC' ) ) {
			return;
		}

		$wc = WC();

		if ( ! property_exists( $wc, 'session' ) ) {
			return;
		}

		if ( ! method_exists( $wc->session, 'forget_session' ) ) {
			return;
		}

		$wc->session->forget_session();
	}

	public function filter_user_has_cap( array $user_caps, array $required_caps, array $args, WP_User $user ) {
		if ( 'switch_to_user' === $args[0] ) {
			if ( empty( $args[2] ) ) {
				$user_caps['switch_to_user'] = false;
				return $user_caps;
			}
			if ( array_key_exists( 'switch_users', $user_caps ) ) {
				$user_caps['switch_to_user'] = $user_caps['switch_users'];
				return $user_caps;
			}

			$user_caps['switch_to_user'] = ( user_can( $user->ID, 'edit_user', $args[2] ) && ( $args[2] !== $user->ID ) );
		} elseif ( 'switch_off' === $args[0] ) {
			if ( array_key_exists( 'switch_users', $user_caps ) ) {
				$user_caps['switch_off'] = $user_caps['switch_users'];
				return $user_caps;
			}

			$user_caps['switch_off'] = user_can( $user->ID, 'edit_users' );
		}

		return $user_caps;
	}

	public function filter_map_meta_cap( array $required_caps, $cap, $user_id, array $args ) {
		if ( 'switch_to_user' === $cap ) {
			if ( empty( $args[0] ) || $args[0] === $user_id ) {
				$required_caps[] = 'do_not_allow';
			}
		}
		return $required_caps;
	}
	public static function get_instance() {
		static $instance;

		if ( ! isset( $instance ) ) {
			$instance = new user_switching();
		}

		return $instance;
	}

	private function __construct() {}
}

if ( ! function_exists( 'user_switching_set_olduser_cookie' ) ) {

	function user_switching_set_olduser_cookie( $old_user_id, $pop = false, $token = '' ) {
		$secure_auth_cookie = user_switching::secure_auth_cookie();
		$secure_olduser_cookie = user_switching::secure_olduser_cookie();
		$expiration = time() + 172800; // 48 hours
		$auth_cookie = user_switching_get_auth_cookie();
		$olduser_cookie = wp_generate_auth_cookie( $old_user_id, $expiration, 'logged_in', $token );

		if ( $secure_auth_cookie ) {
			$auth_cookie_name = USER_SWITCHING_SECURE_COOKIE;
			$scheme = 'secure_auth';
		} else {
			$auth_cookie_name = USER_SWITCHING_COOKIE;
			$scheme = 'auth';
		}

		if ( $pop ) {
			array_pop( $auth_cookie );
		} else {
			array_push( $auth_cookie, wp_generate_auth_cookie( $old_user_id, $expiration, $scheme, $token ) );
		}

		$auth_cookie = wp_json_encode( $auth_cookie );

		if ( false === $auth_cookie ) {
			return;
		}

		do_action( 'set_user_switching_cookie', $auth_cookie, $expiration, $old_user_id, $scheme, $token );

		$scheme = 'logged_in';

		do_action( 'set_olduser_cookie', $olduser_cookie, $expiration, $old_user_id, $scheme, $token );

		if ( ! apply_filters( 'user_switching_send_auth_cookies', true ) ) {
			return;
		}

		setcookie( $auth_cookie_name, $auth_cookie, $expiration, SITECOOKIEPATH, COOKIE_DOMAIN, $secure_auth_cookie, true );
		setcookie( USER_SWITCHING_OLDUSER_COOKIE, $olduser_cookie, $expiration, COOKIEPATH, COOKIE_DOMAIN, $secure_olduser_cookie, true );
	}
}

if ( ! function_exists( 'user_switching_clear_olduser_cookie' ) ) {

	function user_switching_clear_olduser_cookie( $clear_all = true ) {
		$auth_cookie = user_switching_get_auth_cookie();
		if ( ! empty( $auth_cookie ) ) {
			array_pop( $auth_cookie );
		}
		if ( $clear_all || empty( $auth_cookie ) ) {
	
			do_action( 'clear_olduser_cookie' );

			if ( ! apply_filters( 'user_switching_send_auth_cookies', true ) ) {
				return;
			}

			$expire = time() - 31536000;
			setcookie( USER_SWITCHING_COOKIE,         ' ', $expire, SITECOOKIEPATH, COOKIE_DOMAIN );
			setcookie( USER_SWITCHING_SECURE_COOKIE,  ' ', $expire, SITECOOKIEPATH, COOKIE_DOMAIN );
			setcookie( USER_SWITCHING_OLDUSER_COOKIE, ' ', $expire, COOKIEPATH, COOKIE_DOMAIN );
		} else {
			if ( user_switching::secure_auth_cookie() ) {
				$scheme = 'secure_auth';
			} else {
				$scheme = 'auth';
			}

			$old_cookie = end( $auth_cookie );

			$old_user_id = wp_validate_auth_cookie( $old_cookie, $scheme );
			if ( $old_user_id ) {
				$parts = wp_parse_auth_cookie( $old_cookie, $scheme );

				if ( false !== $parts ) {
					user_switching_set_olduser_cookie( $old_user_id, true, $parts['token'] );
				}
			}
		}
	}
}

if ( ! function_exists( 'user_switching_get_olduser_cookie' ) ) {

	function user_switching_get_olduser_cookie() {
		if ( isset( $_COOKIE[ USER_SWITCHING_OLDUSER_COOKIE ] ) ) {
			return wp_unslash( $_COOKIE[ USER_SWITCHING_OLDUSER_COOKIE ] );
		} else {
			return false;
		}
	}
}

if ( ! function_exists( 'user_switching_get_auth_cookie' ) ) {

	function user_switching_get_auth_cookie() {
		if ( user_switching::secure_auth_cookie() ) {
			$auth_cookie_name = USER_SWITCHING_SECURE_COOKIE;
		} else {
			$auth_cookie_name = USER_SWITCHING_COOKIE;
		}

		if ( isset( $_COOKIE[ $auth_cookie_name ] ) && is_string( $_COOKIE[ $auth_cookie_name ] ) ) {
			$cookie = json_decode( wp_unslash( $_COOKIE[ $auth_cookie_name ] ) );
		}
		if ( ! isset( $cookie ) || ! is_array( $cookie ) ) {
			$cookie = array();
		}
		return $cookie;
	}
}

if ( ! function_exists( 'switch_to_user' ) ) {

	function switch_to_user( $user_id, $remember = false, $set_old_user = true ) {
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return false;
		}

		$old_user_id = ( is_user_logged_in() ) ? get_current_user_id() : false;
		$old_token = wp_get_session_token();
		$auth_cookies = user_switching_get_auth_cookie();
		$auth_cookie = end( $auth_cookies );
		$cookie_parts = $auth_cookie ? wp_parse_auth_cookie( $auth_cookie ) : false;

		if ( $set_old_user && $old_user_id ) {
			$new_token = '';
			user_switching_set_olduser_cookie( $old_user_id, false, $old_token );
		} else {
			$new_token = $cookie_parts['token'] ?? '';
			user_switching_clear_olduser_cookie( false );
		}
		$session_filter = function ( array $session ) use ( $old_user_id, $old_token ) {
			$session['switched_from_id'] = $old_user_id;
			$session['switched_from_session'] = $old_token;
			return $session;
		};

		add_filter( 'attach_session_information', $session_filter, 99 );

		wp_clear_auth_cookie();
		wp_set_auth_cookie( $user_id, $remember, '', $new_token );
		wp_set_current_user( $user_id );

		remove_filter( 'attach_session_information', $session_filter, 99 );

		if ( $set_old_user && $old_user_id ) {
			do_action( 'switch_to_user', $user_id, $old_user_id, $new_token, $old_token );
		} else {

			do_action( 'switch_back_user', $user_id, $old_user_id, $new_token, $old_token );
		}

		if ( $old_token && $old_user_id && ! $set_old_user ) {
			$manager = WP_Session_Tokens::get_instance( $old_user_id );
			$manager->destroy( $old_token );
		}

		return $user;
	}
}

if ( ! function_exists( 'switch_off_user' ) ) {

	function switch_off_user() {
		$old_user_id = get_current_user_id();

		if ( ! $old_user_id ) {
			return false;
		}

		$old_token = wp_get_session_token();

		user_switching_set_olduser_cookie( $old_user_id, false, $old_token );
		wp_clear_auth_cookie();
		wp_set_current_user( 0 );

		do_action( 'switch_off_user', $old_user_id, $old_token );

		return true;
	}
}

if ( ! function_exists( 'current_user_switched' ) ) {

	function current_user_switched() {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		return user_switching::get_old_user();
	}
}

$GLOBALS['user_switching'] = user_switching::get_instance();
$GLOBALS['user_switching']->init_hooks();
