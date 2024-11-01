<?php

/**
 * Plugin Name: Thumby
 * Plugin URI:
 * Description: Generate thumbnails on demand and store them in WordPress' uploads directory for future requests.
 * Author: Erick Hitter, John James Jacoby
 * Version: 0.1
 * Author URI:
 * License: GPL2+
 * Text Domain: thumby
 */

/*
This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

class Thumby {

	/**
	 * Class variables
	 */
	private static $__instance = null;

	private static $__request = null; // use $this->request()

	private static $__sizes = null; // use $this->intermediate_image_sizes()

	private $qv = 'thumby';

	/**
	 * Oh look, a singleton
	 */
	public static function instance() {
		if ( is_null( self::$__instance ) ) {
			self::$__instance = new self;
			self::$__instance->setup();
		}

		return self::$__instance;
	}

	/**
	 * Silence is golden.
	 */
	private function __construct() {}


	/**
	 * Setup the main action responsible for generating any thumbnails
	 */
	private function setup() {
		add_action( 'template_redirect', array( $this, 'action_template_redirect' ) );
	}

	/**
	 *
	 */
	public function action_template_redirect() {
		if ( ! is_404() && ! $this->doing_thumby_request() )
			return;

		$image_request = $this->request();

		if ( false !== strpos( $image_request['image'], $image_request['relative_upload_path'] ) ) {
			if ( preg_match( '#((-e[\d]+)?-([\d]+)x([\d]+))\..+?$#i', $image_request['image'], $matches ) ) {
				if ( array_key_exists( 2, $matches ) ) {
					$size = str_replace( $matches[2], '', $matches[1] );
				} else {
					$size = $matches[1];
				}

				$width  = (int) $matches[3];
				$height = (int) $matches[4];

				$requested_img = str_replace( $size, '', $image_request['image'] );

				if ( $this->master_exists( $requested_img ) ) {
					global $wpdb;

					$file          = $this->relative_image_path( $requested_img );
					$attachment_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s and meta_value = %s", '_wp_attached_file', $file ) );

					if ( ! empty( $attachment_id ) && ! is_wp_error( $attachment_id ) ) {

						// check if source image is large enough to support the requested size

						$new_image = image_make_intermediate_size( $image_request['wp_upload_dir']['basedir'] . '/' . $file, $width, $height, true );

						if ( is_array( $new_image ) ) {
							$requested_img = $this->new_image_url( $requested_img, $new_image['file'] );

							$this->stream_image( $requested_img );
						}
					}
				}
			}
		}
	}

	/**
	 * @todo More data in the array to reduce checks on elements?
	 */
	private function request() {
		if ( is_null( self::$__request ) ) {
			$wp_upload_dir = wp_upload_dir();
			$image_request = array(
				'image'                => $_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI'],
				'protocol'             => is_ssl()? 'https://' : 'http://',
				'relative_upload_path' => str_replace( array( 'http://', 'https://' ), array( '', '' ), $wp_upload_dir['baseurl'] ),
				'wp_upload_dir'        => $wp_upload_dir
			);

			self::$__request = apply_filters( 'thumby_request', $image_request, $wp_upload_dir );
		}

		return self::$__request;
	}

	/**
	 * Does a master image exist to make a crop from?
	 */
	private function master_exists( $image ) {

		if ( 0 !== strpos( $image, 'http' ) ) {
			$image_request = $this->request();
			$image         = $image_request['protocol'] . $image;
		}

		$image       = $this->prepare_request_url( $image );
		$image       = wp_remote_head( $image );
		$status_code = wp_remote_retrieve_response_code( $image );
		$return      = (bool) ( 200 == $status_code );

		return apply_filters( 'thumby_master_exists', $return, $status_code, $image );
	}

	/**
	 * Get the relative path for an image
	 */
	private function relative_image_path( $path ) {
		$image_request = $this->request();
		$path          = str_replace( $image_request['relative_upload_path'], '', $path );

		if ( 0 === strpos( $path, '/' ) ) {
			$path = substr( $path, 1 );
		}

		return apply_filters( 'thumby_image_path', $path, $image_request );
	}

	/**
	 * Get the URL for a new image
	 */
	private function new_image_url( $url, $new_file ) {
		$image_request = $this->request();

		$url = str_replace( pathinfo( $url, PATHINFO_BASENAME ), $new_file, $url );
		$url = $image_request['protocol'] . $url;

		return apply_filters( 'thumby_image_url', $url, $image_request );
	}

	/**
	 * Return the new image on the first request
	 */
	private function stream_image( $url ) {
		$url     = $this->prepare_request_url( $url );
		$image   = wp_remote_get( $url );
		$headers = wp_remote_retrieve_headers( $image );

		status_header( wp_remote_retrieve_response_code( $image ) );

		if ( is_array( $headers ) && array_key_exists( 'content-type', $headers ) ) {
			header('Content-Type: ' . $headers['content-type'] );
		}

		echo wp_remote_retrieve_body( $image );

		exit();
	}

	/**
	 * Add recursion prevention to URLs requested from within the plugin
	 *
	 * @param string $url
	 * @uses add_query_arg
	 * @uses apply_filters
	 * @uses this::request
	 * @return string
	 */
	private function prepare_request_url( $url ) {
		$url = add_query_arg( $this->qv, $this->qv, $url );

		$url = apply_filters( 'thumby_prepare_request_url', $url, $this->request() );

		return $url;
	}

	/**
	 * Check if current request originates from the plugin
	 *
	 * @return bool
	 */
	private function doing_thumby_request() {
		return isset( $_REQUEST[ $this->qv ] );
	}

	/**
	 *
	 */
	private function requested_size_allowed( $w, $h ) {
		return true;

		// how to handle changes in registered image sizes
	}

	/**
	 * Build simple array of registered image sizes and their dimensions
	 *
	 * @uses get_intermediate_image_sizes
	 * @uses get_option
	 * @uses apply_filters
	 * @return array
	 */
	private function intermediate_image_sizes() {
		if ( is_null( self::$__sizes ) ) {
			global $_wp_additional_image_sizes;

			$sizes = array();

			$all_image_sizes = get_intermediate_image_sizes();

			foreach ( $all_image_sizes as $size ) {
				if ( array_key_exists( $size, $_wp_additional_image_sizes ) ) {
					$sizes[ $size ] = array(
						'width'  => $_wp_additional_image_sizes[ $size ]['width'],
						'height' => $_wp_additional_image_sizes[ $size ]['height']
					);
				} else {
					$sizes[ $size ] = array(
						'width'  => get_option( $size . '_size_w', 0 ),
						'height' => get_option( $size . '_size_h', 0 )
					);
				}
			}

			self::$__sizes = apply_filters( 'thumby_intermediate_image_sizes', $sizes, $this->request() );
		}

		return self::$__sizes;
	}
}

Thumby::instance();