<?php
/**
 * Copyright (C) 2008 Brion Vibber <brion@pobox.com>
 * https://www.mediawiki.org/
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 */

use MediaWiki\Linker\LinkTarget;
use MediaWiki\MediaWikiServices;
use MediaWiki\User\UserIdentity;

class TitleKey {

	/** @var array */
	private static $deleteIds = [];

	/**
	 * Active functions...
	 *
	 * @param int $id
	 */
	private static function deleteKey( $id ) {
		$dbw = wfGetDB( DB_MASTER );
		$dbw->delete(
			'titlekey',
			[ 'tk_page' => $id ],
			__METHOD__
		);
	}

	/**
	 * @param int $id
	 * @param LinkTarget[] $title
	 */
	private static function setKey( $id, LinkTarget $title ) {
		self::setBatchKeys( [ $id => $title ] );
	}

	/**
	 * @param LinkTarget[] $titles
	 */
	public static function setBatchKeys( $titles ) {
		$rows = [];
		foreach ( $titles as $id => $title ) {
			$rows[] = [
				'tk_page' => $id,
				'tk_namespace' => $title->getNamespace(),
				'tk_key' => self::normalize( $title->getText() ),
			];
		}
		$dbw = wfGetDB( DB_MASTER );
		$dbw->replace(
			'titlekey',
			[ 'tk_page' ],
			$rows,
			__METHOD__
		);
	}

	/**
	 * Normalization...
	 *
	 * @param string $text
	 * @return string
	 */
	private static function normalize( $text ) {
		return MediaWikiServices::getInstance()->getContentLanguage()->caseFold( $text );
	}

	// Hook functions....

	/**
	 * Delay setup to avoid compatibility problems with hook ordering
	 * when coexisting with MWSearch... we want MWSearch to be able to
	 * take over the PrefixSearchBackend hook without disabling the
	 * SearchGetNearMatch hook point.
	 */
	public static function setup() {
		global $wgHooks;
		$wgHooks['PrefixSearchBackend'][] = 'TitleKey::prefixSearchBackend';
		$wgHooks['SearchGetNearMatch'][] = 'TitleKey::searchGetNearMatch';
	}

	/**
	 * @param Article $article
	 * @param User $user
	 * @param string $reason
	 * @return bool
	 */
	public static function updateDeleteSetup( $article, $user, $reason ) {
		$title = $article->mTitle->getPrefixedText();
		self::$deleteIds[$title] = $article->getID();
		return true;
	}

	/**
	 * @param Article $article
	 * @param User $user
	 * @param string $reason
	 * @return bool
	 */
	public static function updateDelete( $article, $user, $reason ) {
		$title = $article->mTitle->getPrefixedText();
		if ( isset( self::$deleteIds[$title] ) ) {
			self::deleteKey( self::$deleteIds[$title] );
		}
		return true;
	}

	/**
	 * @param WikiPage $wikiPage
	 * @return bool
	 */
	public static function updateInsert( $wikiPage ) {
		self::setKey( $wikiPage->getId(), $wikiPage->getTitle() );
		return true;
	}

	/**
	 * @param LinkTarget $from
	 * @param LinkTarget $to
	 * @param UserIdentity $user
	 * @param int $fromid
	 * @param int $toid
	 * @return bool
	 */
	public static function updateMove( LinkTarget $from, LinkTarget $to, $user, $fromid, $toid ) {
		// FIXME
		self::setKey( $toid, $from );
		self::setKey( $fromid, $to );
		return true;
	}

	/**
	 * @param array &$tables
	 * @return bool
	 */
	public static function testTables( &$tables ) {
		$tables[] = 'titlekey';
		return true;
	}

	/**
	 * @param Title $title
	 * @param int $isnewid
	 * @return bool
	 */
	public static function updateUndelete( $title, $isnewid ) {
		$id = WikiPage::factory( $title )->getID();
		self::setKey( $id, $title );
		return true;
	}

	/**
	 * Apply schema updates as necessary.
	 * If creating the titlekey table for the first time,
	 * will populate the table with all titles in the page table.
	 *
	 * Status info is sent to stdout.
	 *
	 * @param DatabaseUpdater $updater
	 */
	public static function schemaUpdates( $updater ) {
		$updater->addExtensionUpdate( [ [ __CLASS__, 'runUpdates' ] ] );
		$updater->addPostDatabaseUpdateMaintenance( 'RebuildTitleKeys' );
	}

	/**
	 * @param DatabaseUpdater $updater
	 */
	public static function runUpdates( $updater ) {
		$db = $updater->getDB();
		if ( $db->tableExists( 'titlekey' ) ) {
			$updater->output( "...titlekey table already exists.\n" );
		} else {
			$updater->output( 'Creating titlekey table...' );
			$sourceFile = $db->getType() == 'postgres' ? '/../sql/titlekey.pg.sql' : '/../sql/titlekey.sql';
			$err = $db->sourceFile( __DIR__ . $sourceFile );
			if ( $err !== true ) {
				throw new Exception( $err );
			}

			$updater->output( "ok.\n" );
		}
	}

	/**
	 * Override the default OpenSearch backend...
	 *
	 * @param int[] $ns
	 * @param string $search term
	 * @param int $limit max number of items to return
	 * @param array &$results out param -- list of title strings
	 * @param int $offset number of items to offset
	 * @return false
	 */
	public static function prefixSearchBackend( $ns, $search, $limit, &$results, $offset = 0 ) {
		$results = self::prefixSearch( $ns, $search, $limit, $offset );
		return false;
	}

	/**
	 * @param array $namespaces
	 * @param string $search
	 * @param int $limit
	 * @param int $offset
	 * @return string
	 */
	private static function prefixSearch( $namespaces, $search, $limit, $offset ) {
		// support only one namespace
		$ns = array_shift( $namespaces );
		if ( in_array( NS_MAIN, $namespaces ) ) {
			// if searching on many always default to main
			$ns = NS_MAIN;
		}

		$key = self::normalize( $search );

		$dbr = wfGetDB( DB_REPLICA );
		$result = $dbr->select(
			[ 'titlekey', 'page' ],
			[ 'page_namespace', 'page_title' ],
			[
				'tk_page = page_id',
				'tk_namespace' => $ns,
				'tk_key ' . $dbr->buildLike( $key, $dbr->anyString() ),
			],
			__METHOD__,
			[
				'ORDER BY' => 'tk_key',
				'LIMIT' => $limit,
				'OFFSET' => $offset,
			]
		);

		// Reformat useful data for future printing by JSON engine
		$srchres = [];
		foreach ( $result as $row ) {
			$title = Title::makeTitle( $row->page_namespace, $row->page_title );
			$srchres[] = $title->getPrefixedText();
		}
		$result->free();

		return $srchres;
	}

	/**
	 * Find matching titles after the default 'go' search exact match fails.
	 * This'll let 'mcgee' match 'McGee' etc.
	 *
	 * @param string $term
	 * @param Title outparam &$title
	 * @return bool
	 */
	public static function searchGetNearMatch( $term, &$title ) {
		$temp = Title::newFromText( $term );
		if ( $temp ) {
			$match = self::exactMatchTitle( $temp );
			if ( $match ) {
				// Yay!
				$title = $match;
				return false;
			}
		}
		// No matches. :(
		return true;
	}

	/**
	 * @param Title $title
	 * @return Title|null
	 */
	private static function exactMatchTitle( $title ) {
		$ns = $title->getNamespace();
		return self::exactMatch( $ns, $title->getText() );
	}

	/**
	 * @param array $ns
	 * @param string $text
	 * @return Title|null
	 */
	private static function exactMatch( $ns, $text ) {
		$key = self::normalize( $text );

		$dbr = wfGetDB( DB_REPLICA );
		$row = $dbr->selectRow(
			[ 'titlekey', 'page' ],
			[ 'page_namespace', 'page_title' ],
			[
				'tk_page = page_id',
				'tk_namespace' => $ns,
				'tk_key' => $key,
			],
			__METHOD__
		);

		if ( $row ) {
			return Title::makeTitle( $row->page_namespace, $row->page_title );
		} else {
			return null;
		}
	}
}
