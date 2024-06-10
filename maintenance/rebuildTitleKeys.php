<?php

use MediaWiki\Extension\TitleKey\TitleKey;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";

class RebuildTitleKeys extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription( "Rebuilds titlekey table entries for all pages in DB." );
		$this->setBatchSize( 1000 );
		$this->addOption( 'start', 'Page ID to start from', false, true );

		$this->requireExtension( 'TitleKey' );
	}

	public function execute() {
		$start = $this->getOption( 'start', 0 );
		$this->output( "Rebuilding titlekey table...\n" );
		$dbr = $this->getServiceContainer()->getConnectionProvider()->getReplicaDatabase();

		$maxId = $dbr->newSelectQueryBuilder()
			->select( 'MAX(page_id)' )
			->from( 'page' )
			->caller( __METHOD__ )
			->fetchField();

		$lastId = 0;
		for ( ; $start <= $maxId; $start += $this->mBatchSize ) {
			if ( $start != 0 ) {
				$this->output( "... $start...\n" );
			}
			$result = $dbr->newSelectQueryBuilder()
				->select( [ 'page_id', 'page_namespace', 'page_title' ] )
				->from( 'page' )
				->where( $dbr->expr( 'page_id', '>', intval( $start ) ) )
				->orderBy( 'page_id' )
				->limit( $this->mBatchSize )
				->caller( __METHOD__ )
				->fetchResultSet();

			$titles = [];
			foreach ( $result as $row ) {
				$titles[$row->page_id] =
					Title::makeTitle( $row->page_namespace, $row->page_title );
				$lastId = $row->page_id;
			}
			$result->free();

			TitleKey::setBatchKeys( $titles );
			$this->waitForReplication();
		}

		if ( $lastId ) {
			$this->output( "... $lastId ok.\n" );
		} else {
			$this->output( "... no pages.\n" );
		}
	}
}

$maintClass = RebuildTitleKeys::class;
require_once RUN_MAINTENANCE_IF_MAIN;
