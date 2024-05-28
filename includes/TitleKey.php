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

namespace MediaWiki\Extension\TitleKey;

use Content;
use DatabaseUpdater;
use ManualLogEntry;
use MediaWiki\Hook\PageMoveCompletingHook;
use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\Hook\ArticleDeleteCompleteHook;
use MediaWiki\Page\Hook\ArticleDeleteHook;
use MediaWiki\Page\Hook\ArticleUndeleteHook;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Status\Status;
use MediaWiki\Storage\EditResult;
use MediaWiki\Storage\Hook\PageSaveCompleteHook;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MediaWiki\User\UserIdentity;
use RebuildTitleKeys;
use WikiPage;

class TitleKey implements
	ArticleDeleteHook,
	ArticleDeleteCompleteHook,
	PageSaveCompleteHook,
	ArticleUndeleteHook,
	PageMoveCompletingHook,
	LoadExtensionSchemaUpdatesHook
{

	/** @var array */
	private static $deleteIds = [];

	/**
	 * Active functions...
	 *
	 * @param int $id
	 */
	private static function deleteKey( $id ) {
		$dbw = MediaWikiServices::getInstance()->getDBLoadBalancerFactory()->getPrimaryDatabase();
		$dbw->delete(
			'titlekey',
			[ 'tk_page' => $id ],
			__METHOD__
		);
	}

	/**
	 * @param int $id
	 * @param LinkTarget $title
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
		$dbw = MediaWikiServices::getInstance()->getDBLoadBalancerFactory()->getPrimaryDatabase();
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
		$hookContainer = MediaWikiServices::getInstance()->getHookContainer();
		$hookContainer->register( 'SearchGetNearMatch', [ self::class, 'searchGetNearMatch' ] );
	}

	/**
	 * @param WikiPage $wikiPage
	 * @param User $user
	 * @param string &$reason
	 * @param string &$error
	 * @param Status &$status
	 * @param bool $suppress
	 * @return bool
	 */
	public function onArticleDelete(
		WikiPage $wikiPage,
		User $user,
		&$reason,
		&$error,
		Status &$status,
		$suppress
	) {
		$title = $wikiPage->getTitle()->getPrefixedText();
		self::$deleteIds[$title] = $wikiPage->getID();
		return true;
	}

	/**
	 * @param WikiPage $wikiPage
	 * @param User $user
	 * @param string $reason
	 * @param int $id
	 * @param Content|null $content
	 * @param ManualLogEntry $logEntry
	 * @param int $archivedRevisionCount
	 * @return bool
	 */
	public function onArticleDeleteComplete(
		$wikiPage,
		$user,
		$reason,
		$id,
		$content,
		$logEntry,
		$archivedRevisionCount
	) {
		$title = $wikiPage->getTitle()->getPrefixedText();
		if ( isset( self::$deleteIds[$title] ) ) {
			self::deleteKey( self::$deleteIds[$title] );
		}
		return true;
	}

	/**
	 * @param WikiPage $wikiPage
	 * @param UserIdentity $user
	 * @param string $summary
	 * @param int $flags
	 * @param RevisionRecord $revisionRecord
	 * @param EditResult $editResult
	 * @return bool|void
	 */
	public function onPageSaveComplete( $wikiPage, $user, $summary, $flags, $revisionRecord, $editResult ) {
		self::setKey( $wikiPage->getId(), $wikiPage->getTitle() );
		return true;
	}

	/**
	 * @param LinkTarget $old
	 * @param LinkTarget $new
	 * @param UserIdentity $user
	 * @param int $pageid
	 * @param int $redirid
	 * @param string $reason
	 * @param RevisionRecord $revision
	 * @return bool
	 */
	public function onPageMoveCompleting( $old, $new, $user, $pageid, $redirid, $reason, $revision ) {
		self::setKey( $pageid, $old );
		self::setKey( $redirid, $new );
		return true;
	}

	/**
	 * @param Title $title
	 * @param bool $create
	 * @param string $comment
	 * @param int $oldPageId
	 * @param array $restoredPages
	 * @return bool
	 */
	public function onArticleUndelete( $title, $create, $comment, $oldPageId, $restoredPages ) {
		$id = MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle( $title )->getID();
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
	public function onLoadExtensionSchemaUpdates( $updater ) {
		$dbType = $updater->getDB()->getType();
		$dir = __DIR__ . '/../db_patches';
		if ( $dbType !== 'mysql' ) {
			$dir .= "/$dbType";
		}
		$updater->addExtensionTable(
			'titlekey',
			"$dir/tables-generated.sql"
		);
		if ( $dbType === 'mysql' ) {
			$updater->modifyExtensionField(
				'titlekey',
				'af_actor',
				"$dir/abstractSchemaChanges/patch-modify-tk_key.sql"
			);
		}

		$updater->addPostDatabaseUpdateMaintenance( RebuildTitleKeys::class );
	}

	/**
	 * @param array $namespaces
	 * @param string $search
	 * @param int $limit
	 * @param int $offset
	 * @return Title[]
	 */
	public static function prefixSearch( $namespaces, $search, $limit, $offset ) {
		// support only one namespace
		$ns = array_shift( $namespaces );
		if ( in_array( NS_MAIN, $namespaces ) ) {
			// if searching on many always default to main
			$ns = NS_MAIN;
		}

		$key = self::normalize( $search );

		$dbr = MediaWikiServices::getInstance()->getDBLoadBalancerFactory()->getReplicaDatabase();
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
		$results = [];
		foreach ( $result as $row ) {
			$results[] = Title::makeTitle( $row->page_namespace, $row->page_title );
		}

		return $results;
	}

	/**
	 * Find matching titles after the default 'go' search exact match fails.
	 * This will let 'mcgee' match 'McGee' etc.
	 *
	 * @param string $term
	 * @param Title &$title outparam
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
	 * @param int $ns
	 * @param string $text
	 * @return Title|null
	 */
	private static function exactMatch( $ns, $text ) {
		$key = self::normalize( $text );

		$dbr = MediaWikiServices::getInstance()->getDBLoadBalancerFactory()->getReplicaDatabase();
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
		}

		return null;
	}
}
