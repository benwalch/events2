<?php
namespace JWeiland\Events2\Service;

/*
 * This file is part of the events2 project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use Doctrine\DBAL\Platforms\SQLServerPlatform;
use JWeiland\Events2\Configuration\ExtConf;
use JWeiland\Events2\Utility\DateTimeUtility;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\HiddenRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Extbase\Object\ObjectManager;
use TYPO3\CMS\Extbase\Persistence\Generic\Exception\InconsistentQuerySettingsException;
use TYPO3\CMS\Extbase\Persistence\Generic\QuerySettingsInterface;
use TYPO3\CMS\Extbase\Persistence\QueryInterface;
use TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController;

/**
 * A little helper to organize our DB queries
 */
class DatabaseService
{
    /**
     * @var ExtConf
     */
    protected $extConf;

    /**
     * @param ExtConf $extConf
     */
    public function injectExtConf(ExtConf $extConf)
    {
        $this->extConf = $extConf;
    }

    /**
     * Get column definitions from table
     *
     * @param string $tableName
     * @return array
     */
    public function getColumnsFromTable($tableName): array
    {
        $output = [];
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($tableName);
        $statement = $connection->query('SHOW FULL COLUMNS FROM `' . $tableName . '`');
        while ($fieldRow = $statement->fetch()) {
            $output[$fieldRow['Field']] = $fieldRow;
        }
        return $output;
    }

    /**
     * Truncate table by TableName
     *
     * It's not a really TRUNCATE, it a DELETE FROM.
     * Set $really to true, to do a really TRUNCATE, which also sets starting increment back to 1.
     *
     * @link: https://stackoverflow.com/questions/9686888/how-to-truncate-a-table-using-doctrine-2
     * @param string $tableName
     * @param bool $really
     */
    public function truncateTable($tableName, $really = false)
    {
        if ($really) {
            $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($tableName);
            $connection->truncate($tableName);
        } else {
            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($tableName);
            $queryBuilder->getRestrictions()->removeAll();
            $queryBuilder
                ->delete($tableName)
                ->from($tableName)
                ->execute();
        }
    }

    /**
     * With this method you get all current and future events of all event types.
     * It does not select hidden records as eventRepository->findByIdentifier will not find them.
     *
     * @return array
     */
    public function getCurrentAndFutureEvents()
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tx_events2_domain_model_event');

        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(HiddenRestriction::class))
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $orConstraints = [];

        $orConstraints[] = $this->getConstraintForSingleEvents($queryBuilder);
        $orConstraints[] = $this->getConstraintForDurationEvents($queryBuilder);
        $orConstraints[] = $this->getConstraintForRecurringEvents($queryBuilder);

        $events = $queryBuilder
            ->select('uid', 'pid')
            ->from('tx_events2_domain_model_event')
            ->where(
                $queryBuilder->expr()->orX(...$orConstraints)
            )
            ->execute()
            ->fetchAll();

        if (empty($events)) {
            $events = [];
        }

        return $events;
    }

    /**
     * Get days in range.
     * This method was used by Ajax call: findDaysByMonth
     *
     * @param \DateTime $startDate
     * @param \DateTime $endDate
     * @param array $storagePids
     * @param array $categories
     * @return array Days with event UID, event title and day timestamp
     */
    public function getDaysInRange(\DateTime $startDate, \DateTime $endDate, array $storagePids = [], array $categories = [])
    {
        $constraint = [];

        // Create basic query with QueryBuilder. Where-clause will be added dynamically
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tx_events2_domain_model_day');
        $queryBuilder = $queryBuilder
            ->select('event.uid', 'event.title', 'day.day')
            ->from('tx_events2_domain_model_day', 'day')
            ->leftJoin(
                'day',
                'tx_events2_domain_model_event',
                'event',
                $queryBuilder->expr()->eq(
                    'day.event',
                    $queryBuilder->quoteIdentifier('event.uid')
                )
            );

        // Add relation to sys_category_record_mm only if categories were set
        if (!empty($categories)) {
            $this->addConstraintForCategories(
                $queryBuilder,
                $categories
            );
        }

        // Reduce ResultSet to configured StoragePids
        if (!empty($storagePids)) {
            $this->addConstraintForPid($queryBuilder, $storagePids);
        }

        // Get days greater than first date of month
        $constraint[] = $queryBuilder->expr()->gte('day.day', (int)$startDate->format('U'));
        // Get days lower than last date of month
        $constraint[] = $queryBuilder->expr()->lt('day.day', (int)$endDate->format('U'));

        $daysInMonth = $queryBuilder
            ->where(...$constraint)
            ->execute()
            ->fetchAll();

        return $daysInMonth;
    }

    /**
     * Get Constraint for single events
     *
     * @param QueryBuilder $queryBuilder
     * @return string
     */
    public function getConstraintForSingleEvents(QueryBuilder $queryBuilder): string
    {
        return (string)$queryBuilder->expr()->andX(
            $queryBuilder->expr()->eq(
                'event_type',
                $queryBuilder->quote('single', Connection::PARAM_STR)
            ),
            $queryBuilder->expr()->gt('event_begin', time())
        );
    }

    /**
     * Get Constraint for duration events
     *
     * @param QueryBuilder $queryBuilder
     * @return string
     */
    public function getConstraintForDurationEvents(QueryBuilder $queryBuilder): string
    {
        return (string)$queryBuilder->expr()->andX(
            $queryBuilder->expr()->eq(
                'event_type',
                $queryBuilder->quote('duration', Connection::PARAM_STR)
            ),
            $queryBuilder->expr()->orX(
                $queryBuilder->expr()->eq('event_end', 0),
                $queryBuilder->expr()->gt('event_end', time())
            )
        );
    }

    /**
     * Get Constraint for recurring events
     *
     * @param QueryBuilder $queryBuilder
     * @return string
     */
    public function getConstraintForRecurringEvents(QueryBuilder $queryBuilder): string
    {
        return (string)$queryBuilder->expr()->andX(
            $queryBuilder->expr()->eq(
                'event_type',
                $queryBuilder->quote('recurring', Connection::PARAM_STR)
            ),
            $queryBuilder->expr()->orX(
                $queryBuilder->expr()->eq('recurring_end', 0),
                $queryBuilder->expr()->gt('recurring_end', time())
            )
        );
    }

    /**
     * Add Constraints for Date
     *
     * @param QueryBuilder $queryBuilder
     * @param string $type
     */
    public function addConstraintForDate(QueryBuilder $queryBuilder, string $type)
    {
        $dateTimeUtility = GeneralUtility::makeInstance(DateTimeUtility::class);
        $startDateTime = null;
        $endDateTime = null;

        switch ($type) {
            case 'today':
                $startDateTime = $dateTimeUtility->convert('today');
                $endDateTime = clone $startDateTime;
                $endDateTime->modify('23:59:59');
                break;
            case 'range':
                $startDateTime = $dateTimeUtility->convert('today');
                $endDateTime = $dateTimeUtility->convert('today');
                $endDateTime->modify('+4 weeks');
                break;
            case 'thisWeek':
                $startDateTime = $dateTimeUtility->convert('today');
                $startDateTime->modify('this week'); // 'first day of' does not work for 'weeks'
                $endDateTime = $dateTimeUtility->convert('today');
                $endDateTime->modify('this week +6 days'); // 'last day of' does not work for 'weeks'
                break;
            case 'latest':
            case 'list':
            default:
                if ($this->extConf->getRecurringPast() === 0) {
                    // including current time as events in past are not allowed to be displayed
                    $startDateTime = new \DateTime('now');
                } else {
                    // exclude current time. Start with 00:00:00
                    $startDateTime = $dateTimeUtility->convert('today');
                }
        }

        $this->addConstraintForDateRange($queryBuilder, $startDateTime, $endDateTime);
    }

    /**
     * Add constraint for Date within a given range
     *
     * @param QueryBuilder $queryBuilder
     * @param \DateTime $startDateTime
     * @param \DateTime|null $endDateTime
     */
    public function addConstraintForDateRange(QueryBuilder $queryBuilder, \DateTime $startDateTime, \DateTime $endDateTime = null)
    {
        $constraintsForDateTime = [];

        $constraintsForDateTime[] = $queryBuilder->expr()->gte(
            'day.day_time',
            $startDateTime->format('U')
        );

        if ($endDateTime instanceof \DateTime) {
            $endDateTime->modify('23:59:59');
            $constraintsForDateTime[] = $queryBuilder->expr()->lt(
                'day.day_time',
                $endDateTime->format('U')
            );
        }

        $queryBuilder->andWhere(...$constraintsForDateTime);
    }

    /**
     * Add Constraint for storage page UIDs
     *
     * @param QueryBuilder $queryBuilder
     * @param array $storagePageIds
     */
    public function addConstraintForPid(QueryBuilder $queryBuilder, array $storagePageIds)
    {
        $storagePageIds = array_map('intval', $storagePageIds);
        if (empty($storagePageIds)) {
            return;
        }

        $pageIdExpressions = [];
        if (count($storagePageIds) === 1) {
            $pageIdExpressions[] = $queryBuilder->expr()->eq('day.pid', reset($storagePageIds));
            $pageIdExpressions[] = $queryBuilder->expr()->eq('event.pid', reset($storagePageIds));
        } else {
            $pageIdExpressions[] = $queryBuilder->expr()->in('day.pid', $storagePageIds);
            $pageIdExpressions[] = $queryBuilder->expr()->in('event.pid', $storagePageIds);
        }
        $queryBuilder->andWhere(...$pageIdExpressions);
    }

    /**
     * Add Constraint for Categories
     *
     * @param QueryBuilder $queryBuilder
     * @param array $categories
     */
    public function addConstraintForCategories(QueryBuilder $queryBuilder, array $categories)
    {
        $categories = array_map('intval', $categories);
        if (empty($categories)) {
            return;
        }

        $queryBuilder->leftJoin(
            'event',
            'sys_category_record_mm',
            'category_mm',
            (string)$queryBuilder->expr()->andX(
                $queryBuilder->expr()->eq(
                    'event.uid',
                    $queryBuilder->quoteIdentifier('category_mm.uid_foreign')
                ),
                $queryBuilder->expr()->eq(
                    'category_mm.tablenames',
                    $queryBuilder->quote('tx_events2_domain_model_event', Connection::PARAM_STR)
                ),
                $queryBuilder->expr()->eq(
                    'category_mm.fieldname',
                    $queryBuilder->quote('categories', Connection::PARAM_STR)
                )
            )
        );

        if (count($categories) === 1) {
            $categoryExpression = $queryBuilder->expr()->eq('category_mm.uid_local', reset($categories));
        } else {
            $categoryExpression = $queryBuilder->expr()->in('category_mm.uid_local', $categories);
        }
        $queryBuilder->andWhere($categoryExpression);
    }

    /**
     * Add Constraint for Organizer
     *
     * @param QueryBuilder $queryBuilder
     * @param int $organizer
     */
    public function addConstraintForOrganizer(QueryBuilder $queryBuilder, int $organizer)
    {
        $queryBuilder->andWhere(
            $queryBuilder->expr()->eq('event.organizer', $organizer)
        );
    }

    /**
     * Add Constraint for Location
     *
     * @param QueryBuilder $queryBuilder
     * @param int $location
     */
    public function addConstraintForLocation(QueryBuilder $queryBuilder, int $location)
    {
        $queryBuilder->andWhere(
            $queryBuilder->expr()->eq('event.location', $location)
        );
    }

    /**
     * Add Constraint for various columns of event table
     *
     * @param QueryBuilder $queryBuilder
     * @param string $column
     * @param string $value
     * @param int $dataType
     */
    public function addConstraintForEventColumn(QueryBuilder $queryBuilder, string $column, $value, int $dataType = Connection::PARAM_STR)
    {
        $value = $queryBuilder->quote($value, $dataType);
        $queryBuilder->andWhere(
            $queryBuilder->expr()->eq('event.' . $column, $value)
        );
    }

    /**
     * Working with own QueryBuilder queries does not respect showHiddenContent settings of TYPO3, that's why
     * we have to manually remove Hidden constraint from restriction container.
     *
     * @param QueryBuilder $queryBuilder
     */
    public function addVisibilityConstraintToQuery(QueryBuilder $queryBuilder)
    {
        if (version_compare(TYPO3_branch, '9.4', '>=')) {
            $context = GeneralUtility::makeInstance(Context::class);
            $showHiddenRecords = (bool)$context->getPropertyFromAspect(
                'visibility',
                'includeHiddenContent',
                false
            );
        } else {
            $showHiddenRecords = (bool)$this->getTypoScriptFrontendController()->showHiddenRecords;
        }

        if ($showHiddenRecords) {
            $queryBuilder->getRestrictions()->removeByType(HiddenRestriction::class);
        }
    }

    /**
     * Unquote a single identifier (no dot expansion). Used to unquote the table names
     * from the expressionBuilder so that the table can be found in the TCA definition.
     *
     * This is a slightly copy of TYPO3 Core's QueryBuilder
     *
     * @param QueryBuilder $queryBuilder
     * @param string $identifier The identifier / table name
     * @return string The unquoted table name / identifier
     */
    protected function unquoteSingleIdentifier(QueryBuilder $queryBuilder, string $identifier): string
    {
        $identifier = trim($identifier);
        $platform = $queryBuilder->getConnection()->getDatabasePlatform();
        if ($platform instanceof SQLServerPlatform) {
            // mssql quotes identifiers with [ and ], not a single character
            $identifier = ltrim($identifier, '[');
            $identifier = rtrim($identifier, ']');
        } else {
            $quoteChar = $platform->getIdentifierQuoteCharacter();
            $identifier = trim($identifier, $quoteChar);
            $identifier = str_replace($quoteChar . $quoteChar, $quoteChar, $identifier);
        }
        return $identifier;
    }

    /**
     * Return all tables/aliases used in FROM or JOIN query parts from the query builder.
     *
     * This is a slightly copy of TYPO3 Core's QueryBuilder
     *
     * @param QueryBuilder $queryBuilder
     * @return string[]
     */
    protected function getQueriedTables(QueryBuilder $queryBuilder): array
    {
        $queriedTables = [];

        // Loop through all FROM tables
        foreach ($queryBuilder->getQueryPart('from') as $from) {
            $tableName = $this->unquoteSingleIdentifier($queryBuilder, $from['table']);
            $tableAlias = isset($from['alias']) ? $this->unquoteSingleIdentifier($queryBuilder, $from['alias']) : $tableName;
            $queriedTables[$tableAlias] = $tableName;
        }

        // Loop through all JOIN tables
        foreach ($queryBuilder->getQueryPart('join') as $fromTable => $joins) {
            foreach ($joins as $join) {
                $tableName = $this->unquoteSingleIdentifier($queryBuilder, $join['joinTable']);
                $tableAlias = isset($join['joinAlias']) ? $this->unquoteSingleIdentifier($queryBuilder, $join['joinAlias']) : $tableName;
                $queriedTables[$tableAlias] = $tableName;
            }
        }

        return $queriedTables;
    }

    /**
     * add TYPO3 Constraints for all tables to the queryBuilder
     *
     * @param QueryBuilder $queryBuilder
     * @param QueryInterface $query
     */
    public function addTypo3Constraints(QueryBuilder $queryBuilder, QueryInterface $query)
    {
        $index = 0;
        $objectManager = GeneralUtility::makeInstance(ObjectManager::class);
        $configurationManager = $objectManager->get(ConfigurationManagerInterface::class);
        foreach ($this->getQueriedTables($queryBuilder) as $tableAlias => $tableName) {
            if ($index === 0 || !$configurationManager->isFeatureEnabled('consistentTranslationOverlayHandling')) {
                // With the new behaviour enabled, we only add the pid and language check for the first table (aggregate root).
                // We know the first table is always the main table for the current query run.
                $additionalWhereClauses = $this->getAdditionalWhereClause(
                    $queryBuilder,
                    $query->getQuerySettings(),
                    $tableName,
                    $tableAlias
                );
            } else {
                $additionalWhereClauses = [];
            }
            $index++;
            if (!empty($additionalWhereClauses)) {
                $queryBuilder->andWhere(...$additionalWhereClauses);
            }
        }
    }

    /**
     * Adds additional WHERE statements according to the query settings.
     *
     * @param QueryBuilder $queryBuilder
     * @param QuerySettingsInterface $querySettings The TYPO3 CMS specific query settings
     * @param string $tableName The table name to add the additional where clause for
     * @param string $tableAlias The table alias used in the query.
     * @return array
     */
    protected function getAdditionalWhereClause(QueryBuilder $queryBuilder, QuerySettingsInterface $querySettings, $tableName, $tableAlias = null)
    {
        $whereClause = [];
        if ($querySettings->getRespectSysLanguage()) {
            $objectManager = GeneralUtility::makeInstance(ObjectManager::class);
            $configurationManager = $objectManager->get(ConfigurationManagerInterface::class);
            if ($configurationManager->isFeatureEnabled('consistentTranslationOverlayHandling')) {
                $systemLanguageStatement = $this->getLanguageStatement($queryBuilder, $tableName, $tableAlias, $querySettings);
            } else {
                $systemLanguageStatement = $this->getSysLanguageStatement($queryBuilder, $tableName, $tableAlias, $querySettings);
            }

            if (!empty($systemLanguageStatement)) {
                $whereClause[] = $systemLanguageStatement;
            }
        }

        return $whereClause;
    }

    /**
     * Builds the language field statement
     *
     * @param QueryBuilder $queryBuilder
     * @param string $tableName The database table name
     * @param string $tableAlias The table alias used in the query.
     * @param QuerySettingsInterface $querySettings The TYPO3 CMS specific query settings
     * @return string
     */
    protected function getLanguageStatement(QueryBuilder $queryBuilder, $tableName, $tableAlias, QuerySettingsInterface $querySettings)
    {
        if (empty($GLOBALS['TCA'][$tableName]['ctrl']['languageField'])) {
            return '';
        }

        // Select all entries for the current language
        // If any language is set -> get those entries which are not translated yet
        // They will be removed by \TYPO3\CMS\Frontend\Page\PageRepository::getRecordOverlay if not matching overlay mode
        $languageField = $GLOBALS['TCA'][$tableName]['ctrl']['languageField'];

        $transOrigPointerField = $GLOBALS['TCA'][$tableName]['ctrl']['transOrigPointerField'] ?? '';
        if (!$transOrigPointerField || !$querySettings->getLanguageUid()) {
            return $queryBuilder->expr()->in(
                $tableAlias . '.' . $languageField,
                [(int)$querySettings->getLanguageUid(), -1]
            );
        }

        $mode = $querySettings->getLanguageOverlayMode();
        if (!$mode) {
            return $queryBuilder->expr()->in(
                $tableAlias . '.' . $languageField,
                [(int)$querySettings->getLanguageUid(), -1]
            );
        }

        $defLangTableAlias = $tableAlias . '_dl';
        $defaultLanguageRecordsSubSelect = $queryBuilder->getConnection()->createQueryBuilder();
        $defaultLanguageRecordsSubSelect
            ->select($defLangTableAlias . '.uid')
            ->from($tableName, $defLangTableAlias)
            ->where(
                $defaultLanguageRecordsSubSelect->expr()->andX(
                    $defaultLanguageRecordsSubSelect->expr()->eq($defLangTableAlias . '.' . $transOrigPointerField, 0),
                    $defaultLanguageRecordsSubSelect->expr()->eq($defLangTableAlias . '.' . $languageField, 0)
                )
            );

        $andConditions = [];
        // records in language 'all'
        $andConditions[] = $queryBuilder->expr()->eq($tableAlias . '.' . $languageField, -1);
        // translated records where a default translation exists
        $andConditions[] = $queryBuilder->expr()->andX(
            $queryBuilder->expr()->eq($tableAlias . '.' . $languageField, (int)$querySettings->getLanguageUid()),
            $queryBuilder->expr()->in(
                $tableAlias . '.' . $transOrigPointerField,
                $defaultLanguageRecordsSubSelect->getSQL()
            )
        );
        if ($mode !== 'hideNonTranslated') {
            // $mode = TRUE
            // returns records from current language which have default translation
            // together with not translated default language records
            $translatedOnlyTableAlias = $tableAlias . '_to';
            $queryBuilderForSubselect = $queryBuilder->getConnection()->createQueryBuilder();
            $queryBuilderForSubselect
                ->select($translatedOnlyTableAlias . '.' . $transOrigPointerField)
                ->from($tableName, $translatedOnlyTableAlias)
                ->where(
                    $queryBuilderForSubselect->expr()->andX(
                        $queryBuilderForSubselect->expr()->gt($translatedOnlyTableAlias . '.' . $transOrigPointerField, 0),
                        $queryBuilderForSubselect->expr()->eq($translatedOnlyTableAlias . '.' . $languageField, (int)$querySettings->getLanguageUid())
                    )
                );
            // records in default language, which do not have a translation
            $andConditions[] = $queryBuilder->expr()->andX(
                $queryBuilder->expr()->eq($tableAlias . '.' . $languageField, 0),
                $queryBuilder->expr()->notIn(
                    $tableAlias . '.uid',
                    $queryBuilderForSubselect->getSQL()
                )
            );
        }

        return $queryBuilder->expr()->orX(...$andConditions);
    }

    /**
     * Builds the language field statement in a legacy way (when consistentTranslationOverlayHandling flag is disabled)
     *
     * @param QueryBuilder $queryBuilder
     * @param string $tableName The database table name
     * @param string $tableAlias The table alias used in the query.
     * @param QuerySettingsInterface $querySettings The TYPO3 CMS specific query settings
     * @return string
     */
    protected function getSysLanguageStatement(QueryBuilder $queryBuilder, $tableName, $tableAlias, $querySettings)
    {
        if (is_array($GLOBALS['TCA'][$tableName]['ctrl'])) {
            if (!empty($GLOBALS['TCA'][$tableName]['ctrl']['languageField'])) {
                // Select all entries for the current language
                // If any language is set -> get those entries which are not translated yet
                // They will be removed by \TYPO3\CMS\Frontend\Page\PageRepository::getRecordOverlay if not matching overlay mode
                $languageField = $GLOBALS['TCA'][$tableName]['ctrl']['languageField'];

                if (isset($GLOBALS['TCA'][$tableName]['ctrl']['transOrigPointerField'])
                    && $querySettings->getLanguageUid() > 0
                ) {
                    $mode = $querySettings->getLanguageMode();

                    if ($mode === 'strict') {
                        $queryBuilderForSubselect = $queryBuilder->getConnection()->createQueryBuilder();
                        $queryBuilderForSubselect->getRestrictions()->removeAll()->add(new DeletedRestriction());
                        $queryBuilderForSubselect
                            ->select($tableName . '.' . $GLOBALS['TCA'][$tableName]['ctrl']['transOrigPointerField'])
                            ->from($tableName)
                            ->where(
                                $queryBuilderForSubselect->expr()->andX(
                                    $queryBuilderForSubselect->expr()->gt($tableName . '.' . $GLOBALS['TCA'][$tableName]['ctrl']['transOrigPointerField'], 0),
                                    $queryBuilderForSubselect->expr()->eq($tableName . '.' . $languageField, (int)$querySettings->getLanguageUid())
                                )
                            );
                        return $queryBuilder->expr()->orX(
                            $queryBuilder->expr()->eq($tableAlias . '.' . $languageField, -1),
                            $queryBuilder->expr()->andX(
                                $queryBuilder->expr()->eq($tableAlias . '.' . $languageField, (int)$querySettings->getLanguageUid()),
                                $queryBuilder->expr()->eq($tableAlias . '.' . $GLOBALS['TCA'][$tableName]['ctrl']['transOrigPointerField'], 0)
                            ),
                            $queryBuilder->expr()->andX(
                                $queryBuilder->expr()->eq($tableAlias . '.' . $languageField, 0),
                                $queryBuilder->expr()->in(
                                    $tableAlias . '.uid',
                                    $queryBuilderForSubselect->getSQL()

                                )
                            )
                        );
                    }
                    $queryBuilderForSubselect = $queryBuilder->getConnection()->createQueryBuilder();
                    $queryBuilderForSubselect->getRestrictions()->removeAll()->add(new DeletedRestriction());
                    $queryBuilderForSubselect
                        ->select($tableAlias . '.' . $GLOBALS['TCA'][$tableName]['ctrl']['transOrigPointerField'])
                        ->from($tableName)
                        ->where(
                            $queryBuilderForSubselect->expr()->andX(
                                $queryBuilderForSubselect->expr()->gt($tableName . '.' . $GLOBALS['TCA'][$tableName]['ctrl']['transOrigPointerField'], 0),
                                $queryBuilderForSubselect->expr()->eq($tableName . '.' . $languageField, (int)$querySettings->getLanguageUid())
                            )
                        );
                    return $queryBuilder->expr()->orX(
                        $queryBuilder->expr()->in($tableAlias . '.' . $languageField, [(int)$querySettings->getLanguageUid(), -1]),
                        $queryBuilder->expr()->andX(
                            $queryBuilder->expr()->eq($tableAlias . '.' . $languageField, 0),
                            $queryBuilder->expr()->notIn(
                                $tableAlias . '.uid',
                                $queryBuilderForSubselect->getSQL()

                            )
                        )
                    );
                }
                return $queryBuilder->expr()->in(
                    $tableAlias . '.' . $languageField,
                    [(int)$querySettings->getLanguageUid(), -1]
                );
            }
        }
        return '';
    }

    /**
     * @return TypoScriptFrontendController
     */
    protected function getTypoScriptFrontendController(): TypoScriptFrontendController
    {
        if ($GLOBALS['TSFE'] === null) {
            $GLOBALS['TSFE'] = GeneralUtility::makeInstance(
                TypoScriptFrontendController::class,
                [],
                1,
                0
            );
        }
        return $GLOBALS['TSFE'];
    }
}
