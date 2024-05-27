<?php

namespace MediaWiki\Extension\TitleKey;

use SearchPostgres;
use SearchSuggestionSet;

class SearchEnginePostgres extends SearchPostgres {

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
