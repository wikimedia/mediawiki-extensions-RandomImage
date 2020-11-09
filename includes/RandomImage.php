<?php

use MediaWiki\MediaWikiServices;

/**
 * Class file for the RandomImage extension
 *
 * @file
 * @ingroup Extensions
 * @author Rob Church <robchur@gmail.com>
 * @copyright © 2006 Rob Church
 * @license GPL-2.0-only
 */
class RandomImage {

	private $parser = null;

	private $width = false;
	private $float = false;
	private $caption = '';

	private $choices = [];

	/**
	 * Constructor
	 *
	 * @param Parser $parser Parent parser
	 * @param array $options Initial options
	 * @param string $caption Caption text
	 */
	public function __construct( $parser, $options, $caption ) {
		$this->parser = $parser;
		$this->caption = $caption;
		$this->setOptions( $options );
	}

	/**
	 * Extract applicable options from tag attributes
	 *
	 * @param array $options Tag attributes
	 */
	protected function setOptions( $options ) {
		if ( isset( $options['size'] ) ) {
			$size = intval( $options['size'] );
			if ( $size > 0 ) {
				$this->width = $size;
			}
		}
		if ( isset( $options['float'] ) ) {
			$float = strtolower( $options['float'] );
			// TODO: Use magic words instead
			if ( in_array( $float, [ 'left', 'right', 'center' ] ) ) {
				$this->float = $float;
			}
		}
		if ( isset( $options['choices'] ) ) {
			$choices = explode( '|', $options['choices'] );
			if ( count( $choices ) > 0 ) {
				$this->choices = $choices;
			}
		}
	}

	/**
	 * Render a random image
	 *
	 * @return string
	 */
	public function render() {
		$title = $this->pickImage();
		if ( $title instanceof Title && $this->imageExists( $title ) ) {
			return $this->removeMagnifier(
				$this->parser->recursiveTagParse(
					$this->buildMarkup( $title )
				)
			);
		}
		return '';
	}

	/**
	 * Does the specified image exist?
	 *
	 * This is a wrapper around the new File/FileRepo mechanism from
	 * 1.10, to avoid breaking compatibility with older versions for
	 * no good reason
	 *
	 * @param Title $title Title of the image
	 * @return bool
	 */
	protected function imageExists( $title ) {
		$file = MediaWikiServices::getInstance()->getRepoGroup()->findFile( $title );
		return is_object( $file ) && $file->exists();
	}

	/**
	 * Prepare image markup for the given image
	 *
	 * @param Title $title Title of the image to render
	 * @return string
	 */
	protected function buildMarkup( $title ) {
		$parts[] = $title->getPrefixedText();
		$parts[] = 'thumb';
		if ( $this->width !== false ) {
			$parts[] = "{$this->width}px";
		}
		if ( $this->float ) {
			$parts[] = $this->float;
		}
		$parts[] = $this->getCaption( $title );
		return '[[' . implode( '|', $parts ) . ']]';
	}

	/**
	 * Locate and remove the "magnify" icon in the image HTML
	 *
	 * @param string $html Image HTML
	 * @return string
	 */
	protected function removeMagnifier( $html ) {
		$dom = new DOMDocument();
		$dom->loadHTML( $html );
		$xpath = new DOMXPath( $dom );
		foreach ( $xpath->query( '//div[@class="magnify"]' ) as $mag ) {
			$mag->parentNode->removeChild( $mag );
		}
		return preg_replace( '!<\?xml[^?]*\?>!', '', $dom->saveXml() );
	}

	/**
	 * Obtain caption text for a given image
	 *
	 * @param Title $title Image page to take caption from
	 * @return string
	 */
	protected function getCaption( $title ) {
		if ( !$this->caption ) {
			if ( $title->exists() ) {
				$content = Revision::newFromTitle( $title )->getContent();
				$text = ContentHandler::getContentText( $content );
				if ( preg_match( '!<randomcaption>(.*?)</randomcaption>!i', $text, $matches ) ) {
					$this->caption = $matches[1];
				} elseif ( preg_match( "!^(.*?)\n!i", $text, $matches ) ) {
					$this->caption = $matches[1];
				} else {
					if ( $text ) {
						$this->caption = $text;
					} else {
						$this->caption = '&#32;';
					}
				}
			} else {
				$this->caption = '&#32;';
			}
		}
		return $this->caption;
	}

	/**
	 * Select a random image
	 *
	 * @return Title
	 */
	protected function pickImage() {
		if ( count( $this->choices ) > 0 ) {
			return $this->pickFromChoices();
		} else {
			$pick = $this->pickFromDatabase();
			if ( !$pick instanceof Title ) {
				$pick = $this->pickFromDatabase();
			}
			return $pick;
		}
	}

	/**
	 * Select a random image from the choices given
	 *
	 * @return Title
	 */
	protected function pickFromChoices() {
		$name = count( $this->choices ) > 1
			? $this->choices[array_rand( $this->choices )]
			: $this->choices[0];
		return Title::makeTitleSafe( NS_FILE, $name );
	}

	/**
	 * Select a random image from the database
	 *
	 * @return Title
	 */
	protected function pickFromDatabase() {
		global $wgRandomImageStrict;

		$dbr = wfGetDB( DB_REPLICA );

		$tables = [ 'page' ];
		$conds = [
			'page_namespace' => NS_FILE,
			'page_is_redirect' => 0,
			'page_random > ' . $dbr->addQuotes( wfRandom() ),
		];
		$joins = [];

		if ( $wgRandomImageStrict ) {
			$tables[] = 'image';
			$conds[] = 'img_name = page_title';
			$conds['img_major_mime'] = 'image';
			$joins['image'] = [ 'LEFT JOIN', 'img_name = page_title' ];
		}

		$row = $dbr->selectRow(
			$tables,
			[ 'page_namespace', 'page_title' ],
			$conds,
			__METHOD__,
			[
				'USE INDEX' => [ 'page' => 'page_random' ],
				'ORDER BY' => 'page_random'
			],
			$joins
		);

		return $row ? Title::newFromRow( $row ) : null;
	}

	/**
	 * Extension registration callback which sets the default value for
	 * the global variable introduced by this extension.
	 */
	public static function onRegistration() {
		global $wgRandomImageStrict, $wgMiserMode;
		/**
		 * Set this to true to ensure that images selected from the database
		 * have an "IMAGE" MIME type
		 */
		$wgRandomImageStrict = !$wgMiserMode;
	}

	/**
	 * Hook setup
	 *
	 * @param Parser &$parser
	 * @return bool
	 */
	public static function onParserFirstCallInit( &$parser ) {
		$parser->setHook( 'randomimage', 'RandomImage::renderHook' );
		return true;
	}

	/**
	 * Parser hook callback
	 *
	 * @param string $input Tag input
	 * @param array $args Tag attributes
	 * @param Parser $parser Parent parser
	 * @return string
	 */
	public static function renderHook( $input, $args, $parser ) {
		global $wgRandomImageNoCache;
		if ( $wgRandomImageNoCache ) {
			$parser->getOutput()->updateCacheExpiry( 0 );
		}
		$random = new RandomImage( $parser, $args, $input );
		return $random->render();
	}

	/**
	 * Strip <randomcaption> tags out of page text
	 *
	 * @param Parser $parser Calling parser
	 * @param string &$text Page text
	 * @return bool
	 */
	public static function stripHook( $parser, &$text ) {
		$text = preg_replace( '!</?randomcaption>!i', '', $text );
		return true;
	}

}
