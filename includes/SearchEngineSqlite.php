<?php

namespace MediaWiki\Extension\TitleKey;

use SearchSqlite;
use SearchSuggestionSet;

class SearchEngineSqlite extends SearchSqlite {

	/**
	 * @param string $search
	 * @return SearchSuggestionSet
	 */
	protected function completionSearchBackend( $search ) {
		if ( $this->namespaces === [ -1 ] ) {
			return parent::completionSearchBackend( $search );
		}
		return SearchSuggestionSet::fromTitles(
			TitleKey::prefixSearch( $this->namespaces, $search, $this->limit, $this->offset )
		);
	}
}
