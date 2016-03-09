<?php
/**
 * Copyright (C) 2008 Brion Vibber <brion@pobox.com>
 * http://www.mediawiki.org/
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

// Ensure that the script cannot be executed outside of MediaWiki
if ( !defined( 'MEDIAWIKI' ) ) {
	die( 'This is an extension to MediaWiki and cannot be run standalone.' );
}

// Register extension with MediaWiki
$wgExtensionCredits['other'][] = array(
	'path' => __FILE__,
	'name' => 'TitleKey',
	'author' => array(
		'Brion Vibber',
		'...'
	),
	'descriptionmsg' => 'titlekey-desc',
	'url' => 'https://www.mediawiki.org/wiki/Extension:TitleKey',
	'license-name' => 'GPL-2.0+'
);

// The 'SearchUpdate' hook would be right, but it's called in the
// wrong place and I don't want to rewrite it all just this second.

// Update hooks...
$wgHooks['ArticleDelete'][] = 'TitleKey::updateDeleteSetup';
$wgHooks['ArticleDeleteComplete'][] = 'TitleKey::updateDelete';
$wgHooks['ArticleInsertComplete'][] = 'TitleKey::updateInsert';
$wgHooks['ArticleUndelete'][] = 'TitleKey::updateUndelete';
$wgHooks['TitleMoveComplete'][] = 'TitleKey::updateMove';

// Maintenance hooks...
$wgHooks['ParserTestTables'][] = 'TitleKey::testTables';
$wgHooks['LoadExtensionSchemaUpdates'][] = 'TitleKey::schemaUpdates';

// Search hooks...
// Delay setup to avoid compatibility problems with hook ordering
// when coexisting with MWSearch... we want MWSearch to be able to
// take over the PrefixSearchBackend hook without disabling the
// SearchGetNearMatch hook point.
$wgExtensionFunctions[] = 'efTitleKeySetup';

function efTitleKeySetup() {
	global $wgHooks;
	$wgHooks['PrefixSearchBackend'][] = 'TitleKey::prefixSearchBackend';
	$wgHooks['SearchGetNearMatch' ][] = 'TitleKey::searchGetNearMatch';
}

$wgMessagesDirs['TitleKey'] = __DIR__ . '/i18n';
$wgExtensionMessagesFiles['TitleKey'] = __DIR__ . '/TitleKey.i18n.php';
$wgAutoloadClasses['TitleKey'] = __DIR__ . '/TitleKey_body.php';
$wgAutoloadClasses['RebuildTitleKeys'] = __DIR__ . '/rebuildTitleKeys.php';
