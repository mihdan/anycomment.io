<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! class_exists( 'AnyCommentSocialAuth' ) ) :
	/**
	 * AC_SocialAuth helps to process website authentication.
	 */
	class AnyCommentSocialAuth {
		/**
		 * Different social types.
		 * Used for routing, images, etc.
		 */
		const SOCIAL_VKONTAKTE = 'vkontakte';
		const SOCIAL_FACEBOOK = 'facebook';
		const SOCIAL_TWITTER = 'twitter';
		const SOCIAL_GOOGLE = 'google';
		const SOCIAL_GITHUB = 'github';
		const SOCIAL_ODNOKLASSNIKI = 'odnoklassniki';
		const SOCIAL_INSTAGRAM = 'instagram';
		const SOCIAL_TWITCH = 'twitch';
		const SOCIAL_DRIBBBLE = 'dribbble';
		const SOCIAL_YAHOO = 'yahoo';
		const SOCIAL_WORDPRESS = 'wordpress';

		/**
		 * @var \Hybridauth\Hybridauth
		 */
		private $_auth;

		/**
		 * Determine type of social.
		 */
		const META_SOCIAL_TYPE = 'anycomment_social';

		/**
		 * Determine whether user has parent or not.
		 */
		const META_PARENT = 'anycomment_parent';

		/**
		 * Link to social.
		 */
		const META_SOCIAL_LINK = 'anycomment_social_link';

		/**
		 * Avatar hosted on website.
		 */
		const META_SOCIAL_AVATAR = 'anycomment_social_avatar';

		/**
		 * Avatar hosted on remote.
		 */
		const META_SOCIAL_AVATAR_ORIGIN = 'anycomment_social_avatar_origin';

		/**
		 * Cookie used to store errors.
		 */
		const COOKIE_ERROR_STORAGE = 'anycomment_errors';

		/**
		 * Associative array list of providers.
		 */
		protected static $providers = [
			self::SOCIAL_VKONTAKTE     => 'Vkontakte',
			self::SOCIAL_FACEBOOK      => 'Facebook',
			self::SOCIAL_TWITTER       => 'Twitter',
			self::SOCIAL_GOOGLE        => 'Google',
			self::SOCIAL_GITHUB        => 'GitHub',
			self::SOCIAL_ODNOKLASSNIKI => 'Odnoklassniki',
			self::SOCIAL_INSTAGRAM     => 'Instagram',
			self::SOCIAL_TWITCH        => 'TwitchTV',
			self::SOCIAL_DRIBBBLE      => 'Dribbble',
			self::SOCIAL_YAHOO         => 'Yahoo',
			self::SOCIAL_WORDPRESS     => 'WordPress',
		];

		protected static $rest_prefix = 'anycomment';
		protected static $rest_version = 'v1';

		/**
		 * @var array Used to track list of requested avatar and the same email requested again, it will user existing check.
		 */
		protected static $avatarMap = [];

		/**
		 * AC_SocialAuth constructor.
		 */
		public function __construct() {
			// When only guests allowed, social should not be allowed
			if ( ! AnyCommentGenericSettings::isFormTypeGuests() ) {
				$this->init_rest_route();
			}
		}

		/**
		 * Get REST namespace.
		 * @return string
		 */
		private static function get_rest_namespace() {
			return sprintf( '%s/%s', static::$rest_prefix, static::$rest_version );
		}

		/**
		 * Used to initiate REST routes to log in client, etc.
		 */
		private function init_rest_route() {
			add_action( 'rest_api_init', function () {
				$namespace = static::get_rest_namespace();
				$route     = '/auth/(?P<social>\w[\w\s]*)/';
				register_rest_route( $namespace, $route, [
					'methods'  => 'GET',
					'callback' => [ $this, 'process_socials' ],
				] );
			} );
		}

		/**
		 * Main method to process social-like request to authentication, etc.
		 *
		 * @param WP_REST_Request $request
		 *
		 * @return mixed
		 */
		public function process_socials( WP_REST_Request $request ) {
			$redirect        = $request->get_param( 'redirect' );
			$social          = $request->get_param( 'social' );
			$cookie_redirect = 'post_redirect';

			if ( ! $this->social_exists( $social ) || is_user_logged_in() ) {
				wp_redirect( $redirect );
				exit();
			}

			if ( $redirect !== null ) {
				setcookie( $cookie_redirect, $redirect, time() + 3600 );
			} else {
				$redirectCookie = isset( $_COOKIE[ $cookie_redirect ] ) ? $_COOKIE[ $cookie_redirect ] : null;

				$anchor = parse_url( $redirectCookie, PHP_URL_FRAGMENT );
				if ( $redirectCookie !== null && empty( $anchor ) ) {
					$redirectCookie .= '#comments';
				}

				$redirect = $redirectCookie !== null ? $redirectCookie : '/';
			}

			try {
				$this->prepare_auth();
				$adapter = $this->authenticate( $social );
			} catch ( \Exception $exception ) {
				wp_redirect( $redirect );
				exit();
			}

			$user = $adapter->getUserProfile();

			if ( ! $user instanceof \Hybridauth\User\Profile ) {
				wp_redirect( $redirect );
				exit();
			}

			if ( session_status() == PHP_SESSION_NONE ) {
				session_start();
			}

			$auth_result = $this->process_authentication( $user, $social );

			if ( $auth_result instanceof WP_Error ) {
				static::addError( $auth_result->get_error_message() );
			}

			if ( session_status() == PHP_SESSION_ACTIVE ) {
				session_destroy();
			}

			wp_redirect( $redirect );
			exit();
		}

		/**
		 * Get provider name based on provided social key.
		 *
		 * @param string $social Social name, usually lowercase, which comes from REST API.
		 *
		 * @return null|string NULL when unable to get provider name.
		 */
		public function get_provider_name( $social ) {
			if ( ! $this->social_exists( $social ) ) {
				return null;
			}

			return trim( self::$providers[ $social ] );
		}

		/**
		 * Check whether social exists or not.
		 *
		 * @param string $social Social name to be checked for existance.
		 *
		 * @return bool
		 */
		public function social_exists( $social ) {
			$array = self::$providers;

			if ( isset( $array[ strtolower( $social ) ] ) ) {
				return true;
			}

			return false;
		}

		/**
		 * Get verbal name based on provided social key.
		 *
		 * @param string $social Social media key.
		 *
		 * @return mixed
		 */
		public function get_verbal_name( $social ) {
			$verbals = [
				self::SOCIAL_VKONTAKTE     => __( 'Vkontakte', 'anycomment' ),
				self::SOCIAL_FACEBOOK      => __( 'Facebook', 'anycomment' ),
				self::SOCIAL_TWITTER       => __( 'Twitter', 'anycomment' ),
				self::SOCIAL_GOOGLE        => __( 'Google', 'anycomment' ),
				self::SOCIAL_GITHUB        => __( 'GitHub', 'anycomment' ),
				self::SOCIAL_ODNOKLASSNIKI => __( 'Odnoklassniki', 'anycomment' ),
				self::SOCIAL_INSTAGRAM     => __( 'Instagram', 'anycomment' ),
				self::SOCIAL_TWITCH        => __( 'Twitch', 'anycomment' ),
				self::SOCIAL_DRIBBBLE      => __( 'Dribbble', 'anycomment' ),
				self::SOCIAL_YAHOO         => __( 'Yahoo', 'anycomment' ),
				self::SOCIAL_WORDPRESS     => __( 'WordPress', 'anycomment' ),
			];

			return isset( $verbals[ $social ] ) ?
				$verbals[ $social ] :
				$social;
		}

		/**
		 * Prepare authentication.
		 * @throws \Hybridauth\Exception\InvalidArgumentException on failure.
		 */
		private function prepare_auth() {
			$config = [
				'providers' => [
					self::$providers[ self::SOCIAL_VKONTAKTE ]     => [
						'enabled'  => AnyCommentSocialSettings::isVkOn(),
						'keys'     => [
							'id'     => AnyCommentSocialSettings::getVkAppId(),
							'secret' => AnyCommentSocialSettings::getVkSecureKey()
						],
						'callback' => static::get_vk_callback(),
					],
					self::$providers[ self::SOCIAL_GOOGLE ]        => [
						'enabled'  => AnyCommentSocialSettings::isGoogleOn(),
						'keys'     => [
							'id'     => AnyCommentSocialSettings::getGoogleClientId(),
							'secret' => AnyCommentSocialSettings::getGoogleSecret()
						],
						'scope'    => 'profile https://www.googleapis.com/auth/plus.login https://www.googleapis.com/auth/plus.profile.emails.read',
						'callback' => static::get_google_callback(),
					],
					self::$providers[ self::SOCIAL_FACEBOOK ]      => [
						'enabled'  => AnyCommentSocialSettings::isFbOn(),
						'scope'    => 'email, public_profile',
						'keys'     => [
							'id'     => AnyCommentSocialSettings::getFbAppId(),
							'secret' => AnyCommentSocialSettings::getFbAppSecret()
						],
						'callback' => static::get_facebook_callback(),
					],
					self::$providers[ self::SOCIAL_TWITTER ]       => [
						'enabled'  => AnyCommentSocialSettings::isTwitterOn(),
						'keys'     => [
							'key'    => AnyCommentSocialSettings::getTwitterConsumerKey(),
							'secret' => AnyCommentSocialSettings::getTwitterConsumerSecret()
						],
						'callback' => static::get_twitter_callback(),
					],
					self::$providers[ self::SOCIAL_GITHUB ]        => [
						'enabled'  => AnyCommentSocialSettings::isGithubOn(),
						'keys'     => [
							'id'     => AnyCommentSocialSettings::getGithubClientId(),
							'secret' => AnyCommentSocialSettings::getGithubSecretKey()
						],
						'callback' => static::get_github_callback(),
					],
					self::$providers[ self::SOCIAL_ODNOKLASSNIKI ] => [
						'enabled'  => AnyCommentSocialSettings::isOkOn(),
						'keys'     => [
							'id'     => AnyCommentSocialSettings::getOkAppId(),
							'key'    => AnyCommentSocialSettings::getOkAppKey(),
							'secret' => AnyCommentSocialSettings::getOkAppSecret()
						],
						'callback' => static::get_ok_callback(),
					],
					self::$providers[ self::SOCIAL_INSTAGRAM ]     => [
						'enabled'  => AnyCommentSocialSettings::isInstagramOn(),
						'keys'     => [
							'id'     => AnyCommentSocialSettings::getInstagramClientId(),
							'secret' => AnyCommentSocialSettings::getInstagramClientSecret()
						],
						'callback' => static::get_instagram_callback(),
					],
					self::$providers[ self::SOCIAL_TWITCH ]        => [
						'enabled'  => AnyCommentSocialSettings::isTwitchOn(),
						'keys'     => [
							'id'     => AnyCommentSocialSettings::getTwitchClientId(),
							'secret' => AnyCommentSocialSettings::getTwitchClientSecret()
						],
						'callback' => static::get_twitch_callback(),
					],
					self::$providers[ self::SOCIAL_DRIBBBLE ]      => [
						'enabled'  => AnyCommentSocialSettings::isDribbbleOn(),
						'keys'     => [
							'id'     => AnyCommentSocialSettings::getDribbbleClientId(),
							'secret' => AnyCommentSocialSettings::getDribbbleClientSecret()
						],
						'callback' => static::get_dribbble_callback(),
					],
					self::$providers[ self::SOCIAL_YAHOO ]         => [
						'enabled'  => AnyCommentSocialSettings::isYahooOn(),
						'keys'     => [
							'id'     => AnyCommentSocialSettings::getYahooAppId(),
							'secret' => AnyCommentSocialSettings::getYahooClientSecret()
						],
						'callback' => static::get_yahoo_callback(),
					]
				],
			];

			$this->_auth = new \Hybridauth\Hybridauth( $config );
		}

		/**
		 * Authenticate a user.
		 *
		 * @param string $social Socila network name.
		 *
		 * @return bool|\Hybridauth\Adapter\AdapterInterface
		 * @throws \Hybridauth\Exception\InvalidArgumentException
		 * @throws \Hybridauth\Exception\UnexpectedValueException
		 */
		private function authenticate( $social ) {
			if ( $this->_auth === null ) {
				return false;
			}

			$providerName = $this->get_provider_name( $social );

			return $this->_auth->authenticate( $providerName );
		}

		/**
		 * Get vk callback URL.
		 *
		 * @param null|string $redirect Redirect URL added to the link.
		 *
		 * @return string
		 */
		public static function get_vk_callback( $redirect = null ) {
			return static::get_callback_url( self::SOCIAL_VKONTAKTE, $redirect );
		}

		/**
		 * Get ok callback URL.
		 *
		 * @param null|string $redirect Redirect URL added to the link.
		 *
		 * @return string
		 */
		public static function get_ok_callback( $redirect = null ) {
			return static::get_callback_url( self::SOCIAL_ODNOKLASSNIKI, $redirect );
		}

		/**
		 * Get Odnoklassniki callback URL.
		 *
		 * @param null|string $redirect Redirect URL added to the link.
		 *
		 * @return string
		 */
		public static function get_instagram_callback( $redirect = null ) {
			return static::get_callback_url( self::SOCIAL_INSTAGRAM, $redirect );
		}

		/**
		 * Get Twitch callback URL.
		 *
		 * @param null|string $redirect Redirect URL added to the link.
		 *
		 * @return string
		 */
		public static function get_twitch_callback( $redirect = null ) {
			return static::get_callback_url( self::SOCIAL_TWITCH, $redirect );
		}

		/**
		 * Get Dribble callback URL.
		 *
		 * @param null|string $redirect Redirect URL added to the link.
		 *
		 * @return string
		 */
		public static function get_dribbble_callback( $redirect = null ) {
			return static::get_callback_url( self::SOCIAL_DRIBBBLE, $redirect );
		}

		/**
		 * Get Yahoo callback URL.
		 *
		 * @param null|string $redirect Redirect URL added to the link.
		 *
		 * @return string
		 */
		public static function get_yahoo_callback( $redirect = null ) {
			return static::get_callback_url( self::SOCIAL_YAHOO, $redirect );
		}

		/**
		 * Get WordPress callback URL.
		 *
		 * @param null|string $redirect Redirect URL added to the link.
		 *
		 * @return string
		 */
		public static function get_wordpress_callback( $redirect = null ) {
			return static::get_callback_url( self::SOCIAL_WORDPRESS, $redirect );
		}

		/**
		 * Get Google callback URL.
		 *
		 * @param null|string $redirect Redirect URL added to the link.
		 *
		 * @return string
		 */
		public static function get_google_callback( $redirect = null ) {
			return static::get_callback_url( self::SOCIAL_GOOGLE, $redirect );
		}

		/**
		 * Get Github callback URL.
		 *
		 * @param null|string $redirect Redirect URL added to the link.
		 *
		 * @return string
		 */
		public static function get_github_callback( $redirect = null ) {
			return static::get_callback_url( self::SOCIAL_GITHUB, $redirect );
		}

		/**
		 * Get Facebook callback URL.
		 *
		 * @param null|string $redirect Redirect URL added to the link.
		 *
		 * @return string
		 */
		public static function get_facebook_callback( $redirect = null ) {
			return static::get_callback_url( self::SOCIAL_FACEBOOK, $redirect );
		}

		/**
		 * Get Twitter callback URL.
		 *
		 * @param null|string $redirect Redirect URL added to the link.
		 *
		 * @return string
		 */
		public static function get_twitter_callback( $redirect = null ) {
			return static::get_callback_url( self::SOCIAL_TWITTER, $redirect );
		}

		/**
		 * Get REST url + redirect url with it.
		 *
		 * @param string $social_type URL type, e.g. vk
		 * @param string|null $redirect Redirect URL where to send back user.
		 * @param bool $addHash If required to add #comments hash will allows to move users creen directly to comments section.
		 *
		 * @return string
		 */
		public static function get_callback_url( $social_type, $redirect = null, $addHash = false ) {
			$url = static::get_rest_namespace() . "/auth/" . $social_type;

			if ( $redirect !== null ) {
				$url .= "?redirect=$redirect";
			}

			if ( $addHash ) {
				$url .= '#comments';
			}

			return rest_url( $url );
		}

		/**
		 * Process Google authorization.
		 *
		 * @param \Hybridauth\User\Profile $user User to be processed.
		 * @param string $social Social name.
		 *
		 * @return bool|WP_Error WP_Error on failure.
		 */
		private function process_authentication( $user, $social ) {
			$email = isset( $user->email ) && ! empty( $user->email ) ?
				$user->email :
				null;

			$userdata = [
				'user_login'    => $user->identifier,
				'display_name'  => $user->displayName,
				'user_nicename' => $user->firstName,
				'first_name'    => $user->firstName,
				'last_name'     => $user->lastName,
				'user_url'      => $user->profileURL
			];

			if ( $email !== null ) {
				$userdata['user_email'] = $email;
			}

			$searchBy    = $email !== null ? 'email' : 'login';
			$searchValue = $email !== null ? $email : $userdata['user_login'];

			return $this->auth_user(
				$user,
				$social,
				$searchBy,
				$searchValue,
				$userdata,
				[
					self::META_SOCIAL_LINK          => $user->profileURL,
					self::META_SOCIAL_AVATAR_ORIGIN => $user->photoURL,
				]
			);
		}

		/**
		 * Authenticate user with additional check whether such
		 * user already exists or not. When user does not exist,
		 * it create it. When exists, uses existing information to authorize.
		 *
		 * @see AnyCommentSocialAuth::get_user_by() for more information about $field and $value fields.
		 *
		 * @param Hybridauth\User\Profile $user_profile User profile from library.
		 * @param string $social Social type. e.g. twitter.
		 * @param string $field Field name to be used to check existence of such user.
		 * @param string $value Value of the $field to be checked for.
		 * @param array $user_data User data to be created for user, when does not exist.
		 * @param array $user_meta User meta data to be create for user, when does not exist.
		 *
		 * @return bool|WP_Error
		 */
		public function auth_user( $user_profile, $social, $field, $value, array $user_data, array $user_meta ) {
			if ( $field == 'login' ) {
				// Need to make username unique per social network, otherwise
				// some of the IDs might have collision at some point
				// format: social_login
				// login usually is ID
				$value = sprintf( '%s_%s', $social, $value );
			}

			if ( isset( $user_data['user_login'] ) ) {
				$user_data['user_login'] = sprintf( '%s_%s', $social, $user_data['user_login'] );
			}

			if ( ! isset( $user_data['role'] ) ) {
				$user_data['role'] = AnyCommentGenericSettings::getRegisterDefaultGroup();
			}

			// Preset social type as it is passed anyways in the method
			if ( ! isset( $user_meta[ self::META_SOCIAL_TYPE ] ) ) {
				$user_meta[ self::META_SOCIAL_TYPE ] = $social;
			}

			$user = $this->get_user_by( $field, $value );

			if ( $user !== false && ! AnyCommentUserMeta::isSocialLogin( $user ) ) {
				return new WP_Error( 'use_wordpress_to_login', __( "Please use normal login form, as this email is associated with a WordPress user.", "anycomment" ) );
			}

			$found_user_social = AnyCommentUserMeta::getSocialType( $user );

			if ( $found_user_social !== null && $field === 'email' && $found_user_social !== $social ) {
				return new WP_Error( 'use_original_social_account', sprintf( __( "Please use %s to login as this %s seems to be already taken.", "anycomment" ),
					$this->get_verbal_name( $found_user_social ),
					( $field === 'email' ? __( "email", 'anycomment' ) : __( "login", 'anycomment' ) )
				) );
			}

			if ( $user === false ) {

				if ( ! isset( $user_meta[ self::META_SOCIAL_AVATAR ] ) ) {
					$user_meta[ self::META_SOCIAL_AVATAR ] = $this->upload_photo( $social, $user_profile );
				}

				$newUserId = $this->insert_user( $user_data, $user_meta );

				if ( ! $newUserId ) {
					return false;
				}

				$userId = $newUserId;
			} else {
				$userId = $user->ID;
				$this->update_user_meta( $user->ID, $user_meta );
			}

			wp_clear_auth_cookie();
			wp_set_current_user( $userId );
			wp_set_auth_cookie( $userId, true );

			return true;
		}

		/**
		 * Upload photo locally from social.
		 *
		 * @param \Hybridauth\User\Profile $user_profile
		 *
		 * @return string|null string on success, null on failure.
		 */
		public function upload_photo( $social, $user_profile ) {
			$photoUrl = null;

			if ( ! empty( $user_profile->photoURL ) ) {
				$localUrl = AnyCommentUploadHandler::uploadAvatar( $user_profile->photoURL, [
					$social,
					$user_profile->identifier
				] );

				if ( $localUrl !== false ) {
					$photoUrl = $localUrl;
				}
			}

			return $photoUrl;
		}

		public function is_user_from_social( $user, $social ) {
			$social_from_meta = trim( get_user_meta( $user->ID, self::META_SOCIAL_TYPE, true ) );
			$social           = trim( $social );

			if ( $social_from_meta !== $social ) {
				return false;
			}

			return true;
		}


		/**
		 * Check weather user exists.
		 *
		 * @param string $field Field to check for user existence (e.g. username).
		 * @param string $value Fields to check for value of $field.
		 * @param string $social Name of the social.
		 *
		 * @return false|WP_User false on failure.
		 */
		public function get_user_by( $field, $value, $social = null ) {
			$user = get_user_by( $field, $value );

			if ( $user === false ) {
				return false;
			}

			if ( $social !== null ) {
				$social_from_meta = AnyCommentUserMeta::getSocialType( $user );
				$social           = trim( $social );

				if ( $social_from_meta !== $social ) {
					return false;
				}
			}

			return $user;
		}

		/**
		 * @param array $user_data See options below:
		 * - user_login
		 * - user_email
		 * - display_name
		 * - user_nicename
		 * - user_url
		 * @param array $user_meta User meta to be added when `$userdata` added successfully.
		 *
		 * @return false|int false on failure, int on success.
		 *
		 * @see wp_generate_password() for how password is being generated.
		 * @see wp_insert_user() for how `$userdata` param is being processed.
		 * @see add_user_meta() for how `$usermeta` param is being processed.
		 */
		public function insert_user( $user_data, $user_meta ) {
			if ( ! isset( $user_data['userpass'] ) ) {
				// Generate some random password for user
				$user_data['user_pass'] = wp_generate_password( 12, true );
			}

			$newUserId = wp_insert_user( $user_data );

			if ( $newUserId instanceof WP_Error ) {
				return false;
			}

			$metaInsertCount = 0;
			foreach ( $user_meta as $key => $value ) {
				if ( add_user_meta( $newUserId, $key, $value ) ) {
					$metaInsertCount ++;
				}
			}

			// If number of inserted metas is less then was requested to add
			if ( $metaInsertCount < count( $user_meta ) ) {
				return false;
			}

			return $newUserId;
		}

		/**
		 * Update user meta by ID.
		 *
		 * @see update_user_meta() when meta does not exist, it will be added. See for more info.
		 *
		 * @param int $user_id User's ID.
		 * @param array $usermeta List of user meta to be updated.
		 *
		 * @return bool
		 */
		public function update_user_meta( $user_id, $usermeta ) {
			if ( ! get_user_by( 'id', $user_id ) || ! is_array( $usermeta ) || ! empty( $usermeta ) ) {
				return false;
			}

			$metaUpdateCount = 0;
			foreach ( $usermeta as $key => $value ) {
				if ( update_user_meta( $user_id, $key, $value ) ) {
					$metaUpdateCount ++;
				}
			}

			// If number of inserted metas is less then was requested to add
			if ( $metaUpdateCount < count( $usermeta ) ) {
				return false;
			}

			return true;
		}

		/**
		 * Get current user avatar URL.
		 *
		 * @return string|null NULL returned when user does not have any avatar.
		 */
		public function get_active_user_avatar_url() {
			$active_user = $user = wp_get_current_user();
			if ( $active_user instanceof WP_User ) {

				return $this->get_user_avatar_url( $user->ID );
			}

			return null;
		}

		/**
		 * Get user social type.
		 *
		 * @param int $user_id User ID to be checked social type for.
		 *
		 * @return mixed
		 */
		public static function get_user_social_type( $user_id ) {
			return get_user_meta( $user_id, 'anycomment_social', true );
		}

		/**
		 * Get user avatar by user id.
		 *
		 * @param int $id_or_email User ID to be searched for.
		 *
		 * @return mixed|null
		 */
		public function get_user_avatar_url( $id_or_email ) {

			if ( AnyCommentIntegrationSettings::isWPUserAvatarOn() ) {
				global $wpua_functions;
				if ( $wpua_functions->has_wp_user_avatar( $id_or_email ) ) {
					return $wpua_functions->get_wp_user_avatar_src( $id_or_email, AnyCommentAvatars::DEFAULT_AVATAR_WIDTH );
				}
			}


			/**
			 * Get avatar from social network.
			 */
			$userId = null;

			if ( is_numeric( $id_or_email ) ) {
				$userId = $id_or_email;
			} elseif ( is_string( $id_or_email ) && ( $user = get_user_by( 'email', $id_or_email ) ) !== false ) {
				$userId = $user->ID;
			} elseif ( $id_or_email instanceof WP_User ) {
				$userId = $id_or_email->ID;
			} elseif ( $id_or_email instanceof WP_Comment ) {

				if ( (int) $id_or_email->user_id !== 0 ) {
					$userId = $id_or_email->user_id;
				} elseif ( ! empty( $id_or_email->comment_author_email ) ) {
					$id_or_email = $id_or_email->comment_author_email;
				}
			}

			if ( $userId !== null ) {
				$avatarUrl = AnyCommentUserMeta::getSocialAvatar( $userId );

				if ( ! empty( $avatarUrl ) ) {
					return $avatarUrl;
				}
			}

			/**
			 * As user does not have social avatar, it is required to che
			 * whether user has non-default gravatar avatar, if does return,
			 * otherwise get default plugin's one.
			 */
			if ( $this->has_gravatar( $id_or_email ) ) {
				return get_avatar_url( $id_or_email, [ 'size' => AnyCommentAvatars::DEFAULT_AVATAR_WIDTH ] );
			}

			if ( ! AnyCommentGenericSettings::isDefaultAvatarAnyComment() ) {
				return get_avatar_url( $id_or_email, [
					'size'    => AnyCommentAvatars::DEFAULT_AVATAR_WIDTH,
					'default' => AnyCommentGenericSettings::getDefaultAvatar()
				] );
			}


			return AnyComment()->plugin_url() . '/assets/img/no-avatar.svg';
		}

		/**
		 * Utility function to check if a gravatar exists for a given email or id
		 *
		 * @param int|string|object $id_or_email A user ID,  email address, or comment object
		 *
		 * @return bool if the gravatar exists or not
		 */
		public function has_gravatar( $id_or_email ) {
			//id or email code borrowed from wp-includes/pluggable.php
			$email = '';
			if ( is_numeric( $id_or_email ) ) {
				$id   = (int) $id_or_email;
				$user = get_userdata( $id );
				if ( $user ) {
					$email = $user->user_email;
				}
			} elseif ( is_object( $id_or_email ) ) {
				// No avatar for pingbacks or trackbacks
				$allowed_comment_types = apply_filters( 'get_avatar_comment_types', array( 'comment' ) );
				if ( ! empty( $id_or_email->comment_type ) && ! in_array( $id_or_email->comment_type, (array) $allowed_comment_types ) ) {
					return false;
				}

				if ( ! empty( $id_or_email->user_id ) ) {
					$id   = (int) $id_or_email->user_id;
					$user = get_userdata( $id );
					if ( $user ) {
						$email = $user->user_email;
					}
				} elseif ( ! empty( $id_or_email->comment_author_email ) ) {
					$email = $id_or_email->comment_author_email;
				}
			} else {
				$email = $id_or_email;
			}

			$email = strtolower( trim( $email ) );

			if ( isset( static::$avatarMap[ $email ] ) ) {
				return static::$avatarMap[ $email ];
			}

			// Build the Gravatar URL by hasing the email address
			$url = 'http://www.gravatar.com/avatar/' . md5( $email ) . '?d=404';

			// Now check the headers...
			$headers = @get_headers( $url );

			// If 200 is found, the user has a Gravatar; otherwise, they don't.
			$hasGravatar = ! preg_match( '|200|', $headers[0] ) ? false : true;

			static::$avatarMap[ $email ] = $hasGravatar;

			return $hasGravatar;
		}

		/**
		 * Get list of errors stored in cookies.
		 *
		 * @param bool $decode If required to return decoded JSON.
		 * @param bool $andClean Will clean cookie before errors returned.
		 *
		 * @return string|array|null
		 */
		public static function getErrors( $decode = true, $andClean = false ) {
			$errors = isset( $_COOKIE[ self::COOKIE_ERROR_STORAGE ] ) ? $_COOKIE[ self::COOKIE_ERROR_STORAGE ] : null;

			if ( $errors === null ) {
				return null;
			}

			if ( $decode ) {
				return json_decode( stripslashes( $errors ) );
			}

			if ( $andClean ) {
				static::cleanErrors();
			}

			return $errors;
		}

		/**
		 * Add new error.
		 *
		 * @param string $errorMessage Error message to be added.
		 *
		 * @return bool
		 */
		public function addError( $errorMessage ) {

			$errors = static::getErrors();

			if ( $errors !== null ) {
				$errors[] = $errorMessage;
			} else {
				$errors   = [];
				$errors[] = $errorMessage;
			}

			$errorsJson = json_encode( $errors, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE );

			return setcookie( self::COOKIE_ERROR_STORAGE, $errorsJson, 0, "/" );
		}

		/**
		 * Clean cookie errors.
		 */
		public static function cleanErrors() {
			if ( static::getErrors( false ) !== null ) {
				setcookie( self::COOKIE_ERROR_STORAGE, null, - 1, '/' );
			}
		}
	}
endif;