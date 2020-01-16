<?php
/**
 * The PlaceHolder class
 *
 * @since 		3.0
 * @package    	LiteSpeed
 * @subpackage 	LiteSpeed/inc
 * @author     	LiteSpeed Technologies <info@litespeedtech.com>
 */
namespace LiteSpeed ;

defined( 'WPINC' ) || exit ;

class Placeholder extends Base
{
	protected static $_instance ;

	const TYPE_GENERATE = 'generate' ;

	private $_conf_placeholder_resp ;
	private $_conf_placeholder_resp_generator ;
	private $_conf_placeholder_resp_svg ;
	private $_conf_placeholder_lqip ;
	private $_conf_placeholder_lqip_qual ;
	private $_conf_placeholder_resp_color ;
	private $_conf_placeholder_resp_async ;
	private $_placeholder_resp_dict = array() ;
	private $_ph_queue = array() ;

	protected $_summary;

	/**
	 * Init
	 *
	 * @since  3.0
	 * @access protected
	 */
	protected function __construct()
	{
		$this->_conf_placeholder_resp = Conf::val( Base::O_MEDIA_PLACEHOLDER_RESP ) ;
		$this->_conf_placeholder_resp_generator = Conf::val( Base::O_MEDIA_PLACEHOLDER_RESP_GENERATOR ) ;
		$this->_conf_placeholder_resp_svg 	= Conf::val( Base::O_MEDIA_PLACEHOLDER_RESP_SVG ) ;
		$this->_conf_placeholder_lqip 		= Conf::val( Base::O_MEDIA_PLACEHOLDER_LQIP ) ;
		$this->_conf_placeholder_lqip_qual	= Conf::val( Base::O_MEDIA_PLACEHOLDER_LQIP_QUAL ) ;
		$this->_conf_placeholder_resp_async = Conf::val( Base::O_MEDIA_PLACEHOLDER_RESP_ASYNC ) ;
		$this->_conf_placeholder_resp_color = Conf::val( Base::O_MEDIA_PLACEHOLDER_RESP_COLOR ) ;
		$this->_conf_ph_default = Conf::val( Base::O_MEDIA_LAZY_PLACEHOLDER ) ?: LITESPEED_PLACEHOLDER ;

		$this->_summary = self::get_summary();
	}

	/**
	 * Init Placeholder
	 */
	public function init()
	{
		Log::debug2( '[Placeholder] init' ) ;

		add_action( 'litspeed_after_admin_init', array( $this, 'after_admin_init' ) ) ;
	}

	/**
	 * Display column in Media
	 *
	 * @since  3.0
	 * @access public
	 */
	public function after_admin_init()
	{
		if ( $this->_conf_placeholder_lqip ) {
			add_action( 'litespeed_media_row', array( $this, 'media_row_con' ) ) ;
		}
	}

	/**
	 * Display LQIP column
	 *
	 * @since  3.0
	 * @access public
	 */
	public function media_row_con( $post_id )
	{
		$meta_value = wp_get_attachment_metadata( $post_id ) ;

		if ( empty( $meta_value[ 'file' ] ) ) {
			return;
		}

		echo '<div><div class="litespeed-text-dimgray litespeed-text-center">LQIP</div>' ;

		// List all sizes
		$all_sizes = array( $meta_value[ 'file' ] ) ;
		$size_path = pathinfo( $meta_value[ 'file' ], PATHINFO_DIRNAME ) . '/' ;
		foreach ( $meta_value[ 'sizes' ] as $v ) {
			$all_sizes[] = $size_path . $v[ 'file' ] ;
		}

		foreach ( $all_sizes as $short_path ) {
			$lqip_folder = LITESPEED_STATIC_DIR . '/lqip/' . $short_path ;

			if ( is_dir( $lqip_folder ) ) {
				Log::debug( '[LQIP] Found folder: ' . $short_path ) ;

				// List all files
				foreach ( scandir( $lqip_folder ) as $v ) {
					if ( $v == '.' || $v == '..' ) {
						continue ;
					}

					echo '<div class="litespeed-media-p"><a href="' . File::read( $lqip_folder . '/' . $v ) . '" target="_blank">' . $v . '</a></div>' ;
				}

			}
		}


		echo '</div>' ;
	}

	/**
	 * Replace image with placeholder
	 *
	 * @since  3.0
	 * @access public
	 */
	public function replace( $html, $src, $size )
	{
		// Check if need to enable responsive placeholder or not
		$this_placeholder = $this->_placeholder( $src, $size ) ?: $this->_conf_ph_default ;

		$additional_attr = '' ;
		if ( $this->_conf_placeholder_resp_generator && $this_placeholder != $this->_conf_ph_default ) {
			Log::debug2( '[Placeholder] Use resp placeholder [size] ' . $size ) ;
			$additional_attr = ' data-placeholder-resp="' . $size . '"' ;
		}

		$snippet = '<noscript>' . $html . '</noscript>' ;
		$html = str_replace( array( ' src=', ' srcset=', ' sizes=' ), array( ' data-src=', ' data-srcset=', ' data-sizes=' ), $html ) ;
		$html = str_replace( '<img ', '<img data-lazyloaded="1"' . $additional_attr . ' src="' . $this_placeholder . '" ', $html ) ;
		$snippet = $html . $snippet ;

		return $snippet ;
	}

	/**
	 * Generate responsive placeholder
	 *
	 * @since  2.5.1
	 * @access private
	 */
	private function _placeholder( $src, $size )
	{
		// Low Quality Image Placeholders
		if ( ! $size ) {
			Log::debug2( '[Placeholder] no size ' . $src ) ;
			return false ;
		}

		if ( ! $this->_conf_placeholder_resp ) {
			return false ;
		}

		// If use local generator
		if ( ! $this->_conf_placeholder_resp_generator ) {
			return $this->_generate_placeholder_locally( $size ) ;
		}

		Log::debug2( '[Placeholder] Resp placeholder process [src] ' . $src . ' [size] ' . $size ) ;

		// Only LQIP needs $src
		$arr_key = $this->_conf_placeholder_lqip ? $size . ' ' . $src : $size ;

		// Check if its already in dict or not
		if ( ! empty( $this->_placeholder_resp_dict[ $arr_key ] ) ) {
			Log::debug2( '[Placeholder] already in dict' ) ;

			return $this->_placeholder_resp_dict[ $arr_key ] ;
		}

		// Need to generate the responsive placeholder
		$placeholder_realpath = $this->_placeholder_realpath( $src, $size ) ; // todo: give offload API
		if ( file_exists( $placeholder_realpath ) ) {
			Log::debug2( '[Placeholder] file exists' ) ;
			$this->_placeholder_resp_dict[ $arr_key ] = File::read( $placeholder_realpath ) ;

			return $this->_placeholder_resp_dict[ $arr_key ] ;
		}

		// Add to cron queue

		// Prevent repeated requests
		if ( in_array( $arr_key, $this->_ph_queue ) ) {
			Log::debug2( '[Placeholder] file bypass generating due to in queue' ) ;
			return $this->_generate_placeholder_locally( $size ) ;
		}

		$this->_ph_queue[] = $arr_key ;

		// Send request to generate placeholder
		if ( ! $this->_conf_placeholder_resp_async ) {
			// If requested recently, bypass
			if ( $this->_summary && ! empty( $this->_summary[ 'curr_request' ] ) && time() - $this->_summary[ 'curr_request' ] < 300 ) {
				Log::debug2( '[Placeholder] file bypass generating due to interval limit' ) ;
				return false ;
			}
			// Generate immediately
			$this->_placeholder_resp_dict[ $arr_key ] = $this->_generate_placeholder( $arr_key ) ;

			return $this->_placeholder_resp_dict[ $arr_key ] ;
		}

		// Prepare default svg placeholder as tmp placeholder
		$tmp_placeholder = $this->_generate_placeholder_locally( $size ) ;

		// Store it to prepare for cron
		if ( empty( $this->_summary[ 'queue' ] ) ) {
			$this->_summary[ 'queue' ] = array() ;
		}
		if ( in_array( $arr_key, $this->_summary[ 'queue' ] ) ) {
			Log::debug2( '[Placeholder] already in queue' ) ;

			return $tmp_placeholder ;
		}

		if ( count( $this->_summary[ 'queue' ] ) > 100 ) {
			Log::debug2( '[Placeholder] queue is full' ) ;

			return $tmp_placeholder ;
		}

		$this->_summary[ 'queue' ][] = $arr_key ;

		Log::debug( '[Placeholder] Added placeholder queue' ) ;

		self::save_summary();
		return $tmp_placeholder ;

	}

	/**
	 * Check if there is a placeholder cache folder
	 *
	 * @since  2.5.1
	 * @access public
	 */
	public static function has_placehoder_cache()
	{
		return is_dir( LITESPEED_STATIC_DIR . '/placeholder' ) ;
	}

	/**
	 * Check if there is a LQIP cache folder
	 *
	 * @since  3.0
	 * @access public
	 */
	public static function has_lqip_cache()
	{
		return is_dir( LITESPEED_STATIC_DIR . '/lqip' ) ;
	}

	/**
	 * Generate realpath of placeholder file
	 *
	 * @since  2.5.1
	 * @access private
	 */
	private function _placeholder_realpath( $src, $size )
	{
		// Use plain color placholder
		if ( ! $this->_conf_placeholder_lqip ) {
			return LITESPEED_STATIC_DIR . "/placeholder/$size." . md5( $this->_conf_placeholder_resp_color ) ;
		}

		// Use LQIP Cloud generator, each image placeholder will be separately stored

		// Compatibility with WebP
		if ( substr( $src, -5 ) === '.webp' ) {
			$src = substr( $src, 0, -5 ) ;
		}

		// External images will use cache folder directly
		$domain = parse_url( $src, PHP_URL_HOST ) ;
		if ( $domain && ! Utility::internal( $domain ) ) { // todo: need to improve `util:internal()` to include `CDN::internal()`
			$md5 = md5( $src ) ;

			return LITESPEED_STATIC_DIR . '/lqip/remote/' . substr( $md5, 0, 1 ) . '/' . substr( $md5, 1, 1 ) . '/' . $md5 . '.' . $size ;
		}

		// Drop domain
		$short_path = Utility::att_short_path( $src ) ;

		return LITESPEED_STATIC_DIR . '/lqip/' . $short_path . '/' . $size ;

	}

	/**
	 * Delete file-based cache folder
	 *
	 * @since  2.5.1
	 * @access public
	 */
	public function rm_cache_folder()
	{
		if ( self::has_placehoder_cache() ) {
			File::rrmdir( LITESPEED_STATIC_DIR . '/placeholder' ) ;
		}

		// Clear placeholder in queue too
		self::save_summary( array() ) ;

		Log::debug2( '[Placeholder] Cleared placeholder queue' ) ;
	}

	/**
	 * Delete file-based cache folder for LQIP
	 *
	 * @since  3.0
	 * @access public
	 */
	public function rm_lqip_cache_folder()
	{
		if ( self::has_lqip_cache() ) {
			File::rrmdir( LITESPEED_STATIC_DIR . '/lqip' ) ;
		}

		// Clear LQIP in queue too
		self::save_summary( array() ) ;

		Log::debug( '[Placeholder] Cleared LQIP queue' ) ;
	}

	/**
	 * Cron placeholder generation
	 *
	 * @since  2.5.1
	 * @access public
	 */
	public static function cron( $continue = false )
	{
		$_instance = self::get_instance();
		if ( empty( $_instance->_summary[ 'queue' ] ) ) {
			return ;
		}

		// For cron, need to check request interval too
		if ( ! $continue ) {
			if ( ! empty( $_instance->_summary[ 'curr_request' ] ) && time() - $_instance->_summary[ 'curr_request' ] < 300 ) {
				Log::debug( '[Placeholder] Last request not done' );
				return ;
			}
		}

		foreach ( $_instance->_summary[ 'queue' ] as $v ) {
			Log::debug( '[Placeholder] cron job [size] ' . $v ) ;

			$_instance->_generate_placeholder( $v ) ;

			// only request first one
			if ( ! $continue ) {
				return ;
			}
		}
	}

	/**
	 * Generate placeholder locally
	 *
	 * @since  3.0
	 * @access private
	 */
	private function _generate_placeholder_locally( $size )
	{
		Log::debug2( '[Placeholder] _generate_placeholder local [size] ' . $size ) ;

		$size = explode( 'x', $size ) ;

		$svg = str_replace( array( '{width}', '{height}', '{color}' ), array( $size[ 0 ], $size[ 1 ], $this->_conf_placeholder_resp_color ), $this->_conf_placeholder_resp_svg ) ;

		return 'data:image/svg+xml;base64,' . base64_encode( $svg ) ;
	}

	/**
	 * Send to LiteSpeed API to generate placeholder
	 *
	 * @since  2.5.1
	 * @access private
	 */
	private function _generate_placeholder( $raw_size_and_src )
	{
		// Parse containing size and src info
		$size_and_src = explode( ' ', $raw_size_and_src, 2 ) ;
		$size = $size_and_src[ 0 ] ;
		$src = false ;
		if ( ! empty( $size_and_src[ 1 ] ) ) {
			$src = $size_and_src[ 1 ] ;
		}

		$file = $this->_placeholder_realpath( $src, $size ) ;

		// Local generate SVG to serve ( Repeatly doing this here to remove stored cron queue in case the setting _conf_placeholder_resp_generator is changed )
		if ( ! $this->_conf_placeholder_resp_generator ) {
			$data = $this->_generate_placeholder_locally( $size ) ;
		}
		else {
			// Update request status
			$this->_summary[ 'curr_request' ] = time() ;
			self::save_summary();

			// Generate LQIP
			if ( $this->_conf_placeholder_lqip ) {
				list( $width, $height ) = explode( 'x', $size ) ;
				$req_data = array(
					'width'		=> $width,
					'height'	=> $height,
					'url'		=> substr( $src, -5 ) === '.webp' ? substr( $src, 0, -5 ) : $src,
					'quality'	=> $this->_conf_placeholder_lqip_qual,
				) ;
				$json = Cloud::post( Cloud::SVC_LQIP, $req_data ) ;

				if ( empty( $json[ 'lqip' ] ) ) {
					Log::debug( '[Placeholder] wrong response format', $json ) ;
					return false ;
				}

				$data = $json[ 'lqip' ] ;

				Log::debug( '[Placeholder] _generate_placeholder LQIP' ) ;

				if ( strpos( $data, 'data:image/svg+xml' ) !== 0 ) {
					Log::debug( '[Placeholder] failed to decode response: ' . $data ) ;
					return false ;
				}
			}
			else {

				$req_data = array(
					'size'	=> $size,
					'color'	=> base64_encode( $this->_conf_placeholder_resp_color ), // Encode the color
				) ;
				$json = Cloud::get( Cloud::SVC_PLACEHOLDER, $req_data ) ;

				if ( empty( $json[ 'data' ] ) ) {
					Log::debug( '[Placeholder] wrong response format', $json ) ;
					return false ;
				}

				$data = $json[ 'data' ] ;

				Log::debug( '[Placeholder] _generate_placeholder ' ) ;

				if ( strpos( $data, 'data:image/png;base64,' ) !== 0 ) {
					Log::debug( '[Placeholder] failed to decode response: ' . $data ) ;
					return false ;
				}
			}
		}

		// Write to file
		File::save( $file, $data, true ) ;

		// Save summary data
		$this->_summary[ 'last_spent' ] = time() - $this->_summary[ 'curr_request' ] ;
		$this->_summary[ 'last_request' ] = $this->_summary[ 'curr_request' ] ;
		$this->_summary[ 'curr_request' ] = 0 ;
		if ( ! empty( $this->_summary[ 'queue' ] ) && in_array( $raw_size_and_src, $this->_summary[ 'queue' ] ) ) {
			unset( $this->_summary[ 'queue' ][ array_search( $raw_size_and_src, $this->_summary[ 'queue' ] ) ] ) ;
		}

		self::save_summary();

		Log::debug( '[Placeholder] saved placeholder ' . $file ) ;

		Log::debug2( '[Placeholder] placeholder con: ' . $data ) ;

		return $data ;
	}

	/**
	 * Handle all request actions from main cls
	 *
	 * @since  2.5.1
	 * @access public
	 */
	public static function handler()
	{
		$instance = self::get_instance() ;

		$type = Router::verify_type() ;

		switch ( $type ) {
			case self::TYPE_GENERATE :
				self::cron( true ) ;
				break ;

			default:
				break ;
		}

		Admin::redirect() ;
	}

}