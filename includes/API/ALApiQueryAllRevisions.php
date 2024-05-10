<?php

/**
 * Copyright © 2015 Wikimedia Foundation and contributors
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
 *
 * @file
 */

namespace MediaWiki\Extension\AspaklaryaLockDown\API;

use ApiPageSet;
use ApiQuery;
use ApiQueryAllRevisions;
use ApiQueryBase;
use ApiResult;
use ChangeTags;
use MediaWiki\CommentFormatter\CommentFormatter;
use MediaWiki\Content\IContentHandlerFactory;
use MediaWiki\Content\Renderer\ContentRenderer;
use MediaWiki\Content\Transform\ContentTransformer;
use MediaWiki\MainConfigNames;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\RevisionStore;
use MediaWiki\Revision\SlotRoleRegistry;
use MediaWiki\Title\Title;
use MediaWiki\User\ActorMigration;
use NamespaceInfo;
use ParserFactory;

/**
 * Query module to enumerate all revisions.
 *
 * @ingroup API
 * @since 1.27
 */
class ALApiQueryAllRevisions extends ApiQueryAllRevisions {

	/** @var RevisionStore */
	private $revisionStore;

	/** @var ActorMigration */
	private $actorMigration;

	/** @var NamespaceInfo */
	private $namespaceInfo;

	/**
	 * @param ApiQuery $query
	 * @param string $moduleName
	 * @param RevisionStore $revisionStore
	 * @param IContentHandlerFactory $contentHandlerFactory
	 * @param ParserFactory $parserFactory
	 * @param SlotRoleRegistry $slotRoleRegistry
	 * @param ActorMigration $actorMigration
	 * @param NamespaceInfo $namespaceInfo
	 * @param ContentRenderer $contentRenderer
	 * @param ContentTransformer $contentTransformer
	 * @param CommentFormatter $commentFormatter
	 */
	public function __construct(
		ApiQuery $query,
		$moduleName,
		RevisionStore $revisionStore,
		IContentHandlerFactory $contentHandlerFactory,
		ParserFactory $parserFactory,
		SlotRoleRegistry $slotRoleRegistry,
		ActorMigration $actorMigration,
		NamespaceInfo $namespaceInfo,
		ContentRenderer $contentRenderer,
		ContentTransformer $contentTransformer,
		CommentFormatter $commentFormatter
	) {
		parent::__construct(
			$query,
			$moduleName,
			$revisionStore,
			$contentHandlerFactory,
			$parserFactory,
			$slotRoleRegistry,
			$actorMigration,
			$namespaceInfo,
			$contentRenderer,
			$contentTransformer,
			$commentFormatter
		);
		$this->revisionStore = $revisionStore;
		$this->actorMigration = $actorMigration;
		$this->namespaceInfo = $namespaceInfo;
	}

	/**
	 * @param ApiPageSet|null $resultPageSet
	 * @return void
	 */
	protected function run( ApiPageSet $resultPageSet = null ) {
		$db = $this->getDB();
		$params = $this->extractRequestParams( false );

		$result = $this->getResult();

		$this->requireMaxOneParameter( $params, 'user', 'excludeuser' );

		$tsField = 'rev_timestamp';
		$idField = 'rev_id';
		$pageField = 'rev_page';

		// Namespace check is likely to be desired, but can't be done
		// efficiently in SQL.
		$miser_ns = null;
		$needPageTable = false;
		if ( $params['namespace'] !== null ) {
			$params['namespace'] = array_unique( $params['namespace'] );
			sort( $params['namespace'] );
			if ( $params['namespace'] != $this->namespaceInfo->getValidNamespaces() ) {
				$needPageTable = true;
				if ( $this->getConfig()->get( MainConfigNames::MiserMode ) ) {
					$miser_ns = $params['namespace'];
				} else {
					$this->addWhere( [ 'page_namespace' => $params['namespace'] ] );
				}
			}
		}

		if ( $resultPageSet === null ) {
			$this->parseParameters( $params );
			$revQuery = $this->revisionStore->getQueryInfo( [ 'page' ] );
		} else {
			$this->limit = $this->getParameter( 'limit' ) ?: 10;
			$revQuery = [
				'tables' => [ 'revision' ],
				'fields' => [ 'rev_timestamp', 'rev_id' ],
				'joins' => [],
			];

			if ( $params['generatetitles'] ) {
				$revQuery['fields'][] = 'rev_page';
			}

			if ( $params['user'] !== null || $params['excludeuser'] !== null ) {
				$actorQuery = $this->actorMigration->getJoin( 'rev_user' );
				$revQuery['tables'] += $actorQuery['tables'];
				$revQuery['joins'] += $actorQuery['joins'];
			}

			if ( $needPageTable ) {
				$revQuery['tables'][] = 'page';
				$revQuery['joins']['page'] = [ 'JOIN', [ "$pageField = page_id" ] ];
				if ( (bool)$miser_ns ) {
					$revQuery['fields'][] = 'page_namespace';
				}
			}
		}

		$this->addTables( $revQuery['tables'] );
		$this->addFields( $revQuery['fields'] );
		$this->addJoinConds( $revQuery['joins'] );

		// Seems to be needed to avoid a planner bug (T113901)
		$this->addOption( 'STRAIGHT_JOIN' );

		$dir = $params['dir'];
		$this->addTimestampWhereRange( $tsField, $dir, $params['start'], $params['end'] );

		if ( $this->fld_tags ) {
			$this->addFields( [ 'ts_tags' => ChangeTags::makeTagSummarySubquery( 'revision' ) ] );
		}

		if ( $params['user'] !== null ) {
			$actorQuery = $this->actorMigration->getWhere( $db, 'rev_user', $params['user'] );
			$this->addWhere( $actorQuery['conds'] );
		} elseif ( $params['excludeuser'] !== null ) {
			$actorQuery = $this->actorMigration->getWhere( $db, 'rev_user', $params['excludeuser'] );
			$this->addWhere( 'NOT(' . $actorQuery['conds'] . ')' );
		}

		if ( $params['user'] !== null || $params['excludeuser'] !== null ) {
			// Paranoia: avoid brute force searches (T19342)
			if ( !$this->getAuthority()->isAllowed( 'deletedhistory' ) ) {
				$bitmask = RevisionRecord::DELETED_USER;
			} elseif ( !$this->getAuthority()->isAllowedAny( 'suppressrevision', 'viewsuppressed' ) ) {
				$bitmask = RevisionRecord::DELETED_USER | RevisionRecord::DELETED_RESTRICTED;
			} else {
				$bitmask = 0;
			}
			if ( $bitmask ) {
				$this->addWhere( $db->bitAnd( 'rev_deleted', $bitmask ) . " != $bitmask" );
			}
		}

		if ( $params['continue'] !== null ) {
			$op = ( $dir == 'newer' ? '>' : '<' );
			$cont = explode( '|', $params['continue'] );
			$this->dieContinueUsageIf( count( $cont ) != 2 );
			$ts = $db->addQuotes( $db->timestamp( $cont[0] ) );
			$rev_id = (int)$cont[1];
			$this->dieContinueUsageIf( strval( $rev_id ) !== $cont[1] );
			$this->addWhere( "$tsField $op $ts OR " .
				"($tsField = $ts AND " .
				"$idField $op= $rev_id)" );
		}

		$this->addOption( 'LIMIT', $this->limit + 1 );

		$sort = ( $dir == 'newer' ? '' : ' DESC' );
		$orderby = [];
		// Targeting index rev_timestamp, user_timestamp, usertext_timestamp, or actor_timestamp.
		// But 'user' is always constant for the latter three, so it doesn't matter here.
		$orderby[] = "rev_timestamp $sort";
		$orderby[] = "rev_id $sort";
		$this->addOption( 'ORDER BY', $orderby );

		// aspaklarya-lockdown: If the user does not have the aspaklarya-read-locked right, exclude locked revisions
		if ( !$this->getAuthority()->isAllowed( 'aspaklarya-read-locked' ) ) {
			$lockedRevisionSubquery = $db->selectSQLText(
				'aspaklarya_lockdown_revisions',
				'alr_rev_id',
				[],
				__METHOD__
			);
			// Exclude locked revision IDs from the query
			$this->addWhere( "rev_id NOT IN ($lockedRevisionSubquery)" );
		}

		$hookData = [];
		$res = $this->select( __METHOD__, [], $hookData );

		if ( $resultPageSet === null ) {
			$this->executeGenderCacheFromResultWrapper( $res, __METHOD__ );
		}

		$pageMap = []; // Maps rev_page to array index
		$count = 0;
		$nextIndex = 0;
		$generated = [];
		foreach ( $res as $row ) {
			if ( $count === 0 && $resultPageSet !== null ) {
				// Set the non-continue since the list of all revisions is
				// prone to having entries added at the start frequently.
				$this->getContinuationManager()->addGeneratorNonContinueParam(
					$this,
					'continue',
					"$row->rev_timestamp|$row->rev_id"
				);
			}
			if ( ++$count > $this->limit ) {
				// We've had enough
				$this->setContinueEnumParameter( 'continue', "$row->rev_timestamp|$row->rev_id" );
				break;
			}

			// Miser mode namespace check
			if ( $miser_ns !== null && !in_array( $row->page_namespace, $miser_ns ) ) {
				continue;
			}

			if ( $resultPageSet !== null ) {
				if ( $params['generatetitles'] ) {
					$generated[$row->rev_page] = $row->rev_page;
				} else {
					$generated[] = $row->rev_id;
				}
			} else {
				$revision = $this->revisionStore->newRevisionFromRow( $row, 0, Title::newFromRow( $row ) );
				$rev = $this->extractRevisionInfo( $revision, $row );

				if ( !isset( $pageMap[$row->rev_page] ) ) {
					$index = $nextIndex++;
					$pageMap[$row->rev_page] = $index;
					$title = Title::newFromLinkTarget( $revision->getPageAsLinkTarget() );
					$a = [
						'pageid' => $title->getArticleID(),
						'revisions' => [ $rev ],
					];
					ApiResult::setIndexedTagName( $a['revisions'], 'rev' );
					ApiQueryBase::addTitleInfo( $a, $title );
					$fit = $this->processRow( $row, $a['revisions'][0], $hookData ) &&
						$result->addValue( [ 'query', $this->getModuleName() ], $index, $a );
				} else {
					$index = $pageMap[$row->rev_page];
					$fit = $this->processRow( $row, $rev, $hookData ) &&
						$result->addValue( [ 'query', $this->getModuleName(), $index, 'revisions' ], null, $rev );
				}
				if ( !$fit ) {
					$this->setContinueEnumParameter( 'continue', "$row->rev_timestamp|$row->rev_id" );
					break;
				}
			}
		}

		if ( $resultPageSet !== null ) {
			if ( $params['generatetitles'] ) {
				$resultPageSet->populateFromPageIDs( $generated );
			} else {
				$resultPageSet->populateFromRevisionIDs( $generated );
			}
		} else {
			$result->addIndexedTagName( [ 'query', $this->getModuleName() ], 'page' );
		}
	}
}
