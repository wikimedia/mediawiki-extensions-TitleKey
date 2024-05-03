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

use DatabaseUpdater;
use ManualLogEntry;
use MediaWiki\Hook\PageMoveCompletingHook;
use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\Hook\PageDeleteCompleteHook;
use MediaWiki\Page\Hook\PageUndeleteCompleteHook;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Permissions\Authority;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Storage\EditResult;
use MediaWiki\Storage\Hook\PageSaveCompleteHook;
use MediaWiki\Title\Title;
use MediaWiki\User\UserIdentity;
use RebuildTitleKeys;
use Wikimedia\Rdbms\IExpression;
use Wikimedia\Rdbms\LikeValue;
use WikiPage;

class TitleKey implements
	PageDeleteCompleteHook,
	PageSaveCompleteHook,
	PageUndeleteCompleteHook,
	PageMoveCompletingHook,
	LoadExtensionSchemaUpdatesHook
{

	/**
	 * Active functions...
	 *
	 * @param int $id
	 */
	private function deleteKey( $id ) {
		$dbw = MediaWikiServices::getInstance()->getConnectionProvider()->getPrimaryDatabase();
		$dbw->newDeleteQueryBuilder()
			->delete( 'titlekey' )
			->where( [ 'tk_page' => $id ] )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * @param int $id
	 * @param LinkTarget $title
	 */
	private function setKey( $id, LinkTarget $title ) {
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
		if ( !$rows ) {
			return;
		}
		$dbw = MediaWikiServices::getInstance()->getConnectionProvider()->getPrimaryDatabase()
			->newReplaceQueryBuilder()
			->replaceInto( 'titlekey' )
			->uniqueIndexFields( [ 'tk_page' ] )
			->rows( $rows )
			->caller( __METHOD__ )->execute();
	}

	/**
	 * Normalization...
	 *
	 * @param string $text
	 * @return string
	 */
	private static function normalize( $text ) {
		$contentLanguage = MediaWikiServices::getInstance()->getContentLanguage();
		return $contentLanguage->caseFold( $text );
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
	 * @inheritDoc
	 */
	public function onPageDeleteComplete(
		ProperPageIdentity $page,
		Authority $deleter,
		string $reason,
		int $pageID,
		RevisionRecord $deletedRev,
		ManualLogEntry $logEntry,
		int $archivedRevisionCount
	) {
		$this->deleteKey( $pageID );
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
		$this->setKey( $wikiPage->getId(), $wikiPage->getTitle() );
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
		$this->setKey( $pageid, $old );
		$this->setKey( $redirid, $new );
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function onPageUndeleteComplete(
		ProperPageIdentity $page,
		Authority $restorer,
		string $reason,
		RevisionRecord $restoredRev,
		ManualLogEntry $logEntry,
		int $restoredRevisionCount,
		bool $created,
		array $restoredPageIds
	): void {
		$id = $page->getId();
		$this->setKey( $id, Title::newFromPageIdentity( $page ) );
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
		$db = $updater->getDB();
		$path = dirname( __DIR__ ) . '/sql';
		$updater->addExtensionTable(
			'titlekey',
			$db->getType() === 'postgres' ? "$path/titlekey.pg.sql" : "$path/titlekey.sql"
		);
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

		$dbr = MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();
		$result = $dbr->newSelectQueryBuilder()
			->select( [ 'page_namespace', 'page_title' ] )
			->from( 'page' )
			->join( 'titlekey', null, 'tk_page=page_id' )
			->where( [
				'tk_namespace' => $ns,
				$dbr->expr( 'tk_key', IExpression::LIKE, new LikeValue( $key, $dbr->anyString() ) ),
			] )
			->orderBy( 'tk_key' )
			->limit( $limit )
			->offset( $offset )
			->caller( __METHOD__ )
			->fetchResultSet();

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

		$dbr = MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();
		$row = $dbr->newSelectQueryBuilder()
			->select( [ 'page_namespace', 'page_title' ] )
			->from( 'page' )
			->join( 'titlekey', null, 'tk_page=page_id' )
			->where( [
				'tk_namespace' => $ns,
				'tk_key' => $key,
			] )
			->caller( __METHOD__ )
			->fetchRow();

		if ( $row ) {
			return Title::makeTitle( $row->page_namespace, $row->page_title );
		}

		return null;
	}
}
