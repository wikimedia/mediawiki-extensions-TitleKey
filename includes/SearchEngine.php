<?php

namespace MediaWiki\Extension\TitleKey;

use SearchEngine as MWSearchEngine;
use SearchSuggestionSet;

class SearchEngine extends MWSearchEngine {

	/**
	 * @param string $search
	 * @return SearchSuggestionSet
	 */
	protected function completionSearchBackend( $search ) {
		return SearchSuggestionSet::fromTitles(
			TitleKey::prefixSearch( $this->namespaces, $search, $this->limit, $this->offset )
		);
	}
}
