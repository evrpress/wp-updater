<?php

namespace EverPress;

if ( class_exists( 'EverPress\ReadmeParser' ) ) {
	return;
}


class ReadmeParser {

	private static $instance = null;
	private $data;

	private function __construct( $data ) {
		$this->data = $this->normalalize_data( $data );
	}

	public static function parse( $data ) {

		if ( self::$instance === null ) {
			self::$instance = new self( $data );
		}

		return self::$instance;
	}

	private function normalalize_data( $data ) {
		$data = str_replace( "\r\n", "\n", $data );
		$data = str_replace( "\r", "\n", $data );

		// convert markdown to wp
		$data = preg_replace( '/^# (.*)?/im', '= $1 =', $data );
		$data = preg_replace( '/^## (.*)?/im', '== $1 ==', $data );
		$data = preg_replace( '/^### (.*)?/im', '=== $1 ===', $data );
		$data = preg_replace( '/^#### (.*)?/im', '==== $1 ====', $data );
		$data = preg_replace( '/^##### (.*)?/im', '===== $1 =====', $data );
		return $data;
	}

	public function parse_data() {
		// parse data
	}

	public function get_data() {

		$this->parse_data();

		$readme = array(
			'version'      => $this->get_version(),
			'requires'     => $this->get_requires(),
			'requires_php' => $this->get_requires_php(),
			'tested'       => $this->get_tested(),
			'sections'     => $this->get_sections(),
		);

		return $readme;
	}
	/**
	 *
	 *
	 * @param unknown $parsed (optional)
	 * @return unknown
	 */
	public function get_sections( $parsed = true ) {

		$sections = array();

		if ( ! preg_match_all( '#^(==)[^=](.+)[^=](==)$#m', $this->data, $matches ) ) {
			preg_match_all( "|^(##)([^#]+)(#*?)\s*?\n|im", $this->data, $matches );
		}

		$tmp = $this->data;

		$tmp_name    = '';
		$tmp_content = '';

		if ( $matches[0] ) {

			foreach ( $matches[0] as $i => $e ) {

				$name = trim( $matches[2][ $i ] );
				$x    = trim( $matches[1][ $i ] );
				$y    = trim( $matches[3][ $i ] );
				if ( $tmp_name ) {
					$tmp_content = $x . ' ' . $name . ' ' . $y . "\n\n";
					$name        = $tmp_name;
					$tmp_name    = '';
				}
				$search = trim( $matches[0][ $i ] ) . "\n";
				$start  = strpos( $tmp, $search ) + strlen( $search );
				$tmp    = substr( $tmp, $start );
				if ( isset( $matches[0][ $i + 1 ] ) ) {
					$next   = trim( $matches[0][ $i + 1 ] ) . "\n";
					$length = strpos( $tmp, $next );
				} else {
					$length = 0;
				}

				$content = trim( ( $length ) ? substr( $tmp, 0, $length ) : $tmp );
				if ( $tmp_content ) {
					$content     = $tmp_content . $content;
					$tmp_content = '';
				}

				// move content if next line is also a headline
				if ( empty( $content ) ) {
					$tmp_name = $name;
					continue;
				}

				if ( $parsed ) {
					$content = $this->parser_tag( $content );
				}
				$key = strtolower( $name );

				if ( ! isset( $sections[ $key ] ) ) {
					$sections[ $key ] = '';
				}
				
				$sections[ $key ] .= "\n\n" . trim( $content );
			}
		}
		return $sections;
	}


	/**
	 *
	 *
	 * @param unknown $data
	 * @return unknown
	 */
	private function parser_tag( $data ) {

		include_once __DIR__ . '/vendor/autoload.php';
		$parser = new \Parsedown();

		// headlines
		$data = preg_replace( '#=== (.*?) ===#', '<h2>\\1</h2>', $data );
		$data = preg_replace( '#== (.*?) ==#', '<h3>\\1</h3>', $data );
		$data = preg_replace( '#= (.*?) =#', '<h4>\\1</h4>', $data );

		$rp = $parser->text( $data );

		return $rp;
	}


	/**
	 * inserts version in after plugin name
	 *
	 * @param string $file file
	 * @return string file
	 */
	private function getHeader( $what ) {
		if ( preg_match( '/^(.*)?' . preg_quote( $what ) . ':(.*)?/im', $this->data, $matches ) ) {
			return trim( $matches[2] );
		}
		return false;
	}


	/**
	 * inserts version in after plugin name
	 *
	 * @param string $file file
	 * @return string file
	 */
	private function get_version() {

		return $this->getHeader( 'Stable tag' );
	}


	/**
	 *
	 *
	 * @return unknown
	 */
	private function get_requires() {

		return $this->getHeader( 'Requires at least' );
	}


	/**
	 *
	 *
	 * @return unknown
	 */
	private function get_requires_php() {

		return $this->getHeader( 'Requires PHP' );
	}


	/**
	 *
	 *
	 * @return unknown
	 */
	private function get_tested() {

		return $this->getHeader( 'Tested up to' );
	}


	/**
	 * replaces screenshots with links to images if exists
	 *
	 * @param unknown $data
	 * @return string file
	 */
	private function get_screenshots( $data ) {
		$url = '';
		$pos = strpos( $data, '== Screenshots ==' );

		if ( $pos === false ) {
			return $data;
		}

		$start = $pos + 17;
		$end   = strpos( $data, '== ', $start );

		$subfile = substr( $data, $start, $end - $start );
		$rows    = explode( "\n", $subfile );

		$newsubfile = "\n";

		foreach ( $rows as $row ) {
			for ( $number = 1; $number <= 9; $number++ ) {
				if ( substr( $row, 0, 1 ) == $number ) {
					foreach ( array( 'jpg', 'png', 'gif' ) as $ext ) {
						$img  = dirname( $url ) . '/screenshot-' . $number . '.' . $ext;
						$mime = @getimagesize( $img );
						if ( strpos( $mime['mime'], 'image' ) !== false ) {
							$newsubfile .= $number . '. <a href="' . $img . '"><img src="' . $img . '" title="' . $row . '" /></a><br />' . $row . "\n";
							break;
						}
					}
					break;
				}
			}
		}

		if ( $newsubfile != "\n" ) {
			$data = str_replace( $subfile, $newsubfile, $data );
		}

		return $data;
	}
}
