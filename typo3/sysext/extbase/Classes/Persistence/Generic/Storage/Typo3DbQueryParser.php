<?php
namespace TYPO3\CMS\Extbase\Persistence\Generic\Storage;

/*
 * This file is part of the TYPO3 CMS project.
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

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\CompositeExpression;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Persistence\Generic\Exception\InconsistentQuerySettingsException;
use TYPO3\CMS\Extbase\Persistence\Generic\Exception\InvalidRelationConfigurationException;
use TYPO3\CMS\Extbase\Persistence\Generic\Exception\MissingColumnMapException;
use TYPO3\CMS\Extbase\Persistence\Generic\Exception\RepositoryException;
use TYPO3\CMS\Extbase\Persistence\Generic\Exception\UnsupportedOrderException;
use TYPO3\CMS\Extbase\Persistence\Generic\Mapper\ColumnMap;
use TYPO3\CMS\Extbase\Persistence\Generic\Qom;
use TYPO3\CMS\Extbase\Persistence\Generic\QuerySettingsInterface;
use TYPO3\CMS\Extbase\Persistence\QueryInterface;
use TYPO3\CMS\Frontend\Page\PageRepository;

/**
 * QueryParser, converting the qom to string representation
 */
class Typo3DbQueryParser
{
    /**
     * @var \TYPO3\CMS\Extbase\Persistence\Generic\Mapper\DataMapper
     */
    protected $dataMapper;

    /**
     * The TYPO3 page repository. Used for language and workspace overlay
     *
     * @var PageRepository
     */
    protected $pageRepository;

    /**
     * @var \TYPO3\CMS\Extbase\Service\EnvironmentService
     */
    protected $environmentService;

    /**
     * Instance of the Doctrine query builder
     *
     * @var QueryBuilder
     */
    protected $queryBuilder;

    /**
     * Maps domain model properties to their corresponding table aliases that are used in the query, e.g.:
     *
     * 'property1' => 'tableName',
     * 'property1.property2' => 'tableName1',
     *
     * @var array
     */
    protected $tablePropertyMap = [];

    /**
     * Maps tablenames to their aliases to be used in where clauses etc.
     * Mainly used for joins on the same table etc.
     *
     * @var array
     */
    protected $tableAliasMap = [];

    /**
     * Stores all tables used in for SQL joins
     *
     * @var array
     */
    protected $unionTableAliasCache = [];

    /**
     * @var string
     */
    protected $tableName = '';

    /**
     * @param \TYPO3\CMS\Extbase\Persistence\Generic\Mapper\DataMapper $dataMapper
     */
    public function injectDataMapper(\TYPO3\CMS\Extbase\Persistence\Generic\Mapper\DataMapper $dataMapper)
    {
        $this->dataMapper = $dataMapper;
    }

    /**
     * @param \TYPO3\CMS\Extbase\Service\EnvironmentService $environmentService
     */
    public function injectEnvironmentService(\TYPO3\CMS\Extbase\Service\EnvironmentService $environmentService)
    {
        $this->environmentService = $environmentService;
    }

    /**
     * Returns a ready to be executed QueryBuilder object, based on the query
     *
     * @param QueryInterface $query
     * @return QueryBuilder
     */
    public function convertQueryToDoctrineQueryBuilder(QueryInterface $query)
    {
        // Reset all properties
        $this->tablePropertyMap = [];
        $this->tableAliasMap = [];
        $this->unionTableAliasCache = [];
        $this->tableName = '';
        // Find the right table name
        $source = $query->getSource();
        $this->initializeQueryBuilder($source);

        $constraint = $query->getConstraint();
        if ($constraint instanceof Qom\ConstraintInterface) {
            $wherePredicates = $this->parseConstraint($constraint, $source);
            if (!empty($wherePredicates)) {
                $this->queryBuilder->andWhere($wherePredicates);
            }
        }

        $this->parseOrderings($query->getOrderings(), $source);
        $this->addTypo3Constraints($query);

        return $this->queryBuilder;
    }

    /**
     * Creates the queryBuilder object whether it is a regular select or a JOIN
     *
     * @param Qom\SourceInterface $source The source
     */
    protected function initializeQueryBuilder(Qom\SourceInterface $source)
    {
        if ($source instanceof Qom\SelectorInterface) {
            $className = $source->getNodeTypeName();
            $tableName = $this->dataMapper->getDataMap($className)->getTableName();
            $this->tableName = $tableName;

            $this->queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
                ->getQueryBuilderForTable($tableName);

            $this->queryBuilder
                ->getRestrictions()
                ->removeAll();

            $tableAlias = $this->getUniqueAlias($tableName);

            $this->queryBuilder
                ->select($tableAlias . '.*')
                ->from($tableName, $tableAlias);

            $this->addRecordTypeConstraint($className);
        } elseif ($source instanceof Qom\JoinInterface) {
            $leftSource = $source->getLeft();
            $leftTableName = $leftSource->getSelectorName();

            $this->queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
                ->getQueryBuilderForTable($leftTableName);
            $leftTableAlias = $this->getUniqueAlias($leftTableName);
            $this->queryBuilder
                ->select($leftTableAlias . '.*')
                ->from($leftTableName, $leftTableAlias);
            $this->parseJoin($source, $leftTableAlias);
        }
    }

    /**
     * Transforms a constraint into SQL and parameter arrays
     *
     * @param Qom\ConstraintInterface $constraint The constraint
     * @param Qom\SourceInterface $source The source
     * @return CompositeExpression|string
     * @throws \RuntimeException
     */
    protected function parseConstraint(Qom\ConstraintInterface $constraint, Qom\SourceInterface $source)
    {
        if ($constraint instanceof Qom\AndInterface) {
            $constraint1 = $constraint->getConstraint1();
            $constraint2 = $constraint->getConstraint2();
            if (($constraint1 instanceof Qom\ConstraintInterface)
                && ($constraint2 instanceof Qom\ConstraintInterface)
            ) {
                return $this->queryBuilder->expr()->andX(
                    $this->parseConstraint($constraint1, $source),
                    $this->parseConstraint($constraint2, $source)
                );
            } else {
                return '';
            }
        } elseif ($constraint instanceof Qom\OrInterface) {
            $constraint1 = $constraint->getConstraint1();
            $constraint2 = $constraint->getConstraint2();
            if (($constraint1 instanceof Qom\ConstraintInterface)
                && ($constraint2 instanceof Qom\ConstraintInterface)
            ) {
                return $this->queryBuilder->expr()->orX(
                    $this->parseConstraint($constraint->getConstraint1(), $source),
                    $this->parseConstraint($constraint->getConstraint2(), $source)
                );
            } else {
                return '';
            }
        } elseif ($constraint instanceof Qom\NotInterface) {
            return ' NOT(' . $this->parseConstraint($constraint->getConstraint(), $source) . ')';
        } elseif ($constraint instanceof Qom\ComparisonInterface) {
            return $this->parseComparison($constraint, $source);
        } else {
            throw new \RuntimeException('not implemented', 1476199898);
        }
    }

    /**
     * Transforms orderings into SQL.
     *
     * @param array $orderings An array of orderings (Qom\Ordering)
     * @param Qom\SourceInterface $source The source
     * @throws UnsupportedOrderException
     */
    protected function parseOrderings(array $orderings, Qom\SourceInterface $source)
    {
        foreach ($orderings as $propertyName => $order) {
            if ($order !== QueryInterface::ORDER_ASCENDING && $order !== QueryInterface::ORDER_DESCENDING) {
                throw new UnsupportedOrderException('Unsupported order encountered.', 1242816074);
            }
            $className = null;
            $tableName = '';
            if ($source instanceof Qom\SelectorInterface) {
                $className = $source->getNodeTypeName();
                $tableName = $this->dataMapper->convertClassNameToTableName($className);
                $fullPropertyPath = '';
                while (strpos($propertyName, '.') !== false) {
                    $this->addUnionStatement($className, $tableName, $propertyName, $fullPropertyPath);
                }
            } elseif ($source instanceof Qom\JoinInterface) {
                $tableName = $source->getLeft()->getSelectorName();
            }
            $columnName = $this->dataMapper->convertPropertyNameToColumnName($propertyName, $className);
            if ($tableName !== '') {
                $this->queryBuilder->addOrderBy($tableName . '.' . $columnName, $order);
            } else {
                $this->queryBuilder->addOrderBy($columnName, $order);
            }
        }
    }

    /**
     * add TYPO3 Constraints for all tables to the queryBuilder
     *
     * @param QueryInterface $query
     */
    protected function addTypo3Constraints(QueryInterface $query)
    {
        foreach ($this->tableAliasMap as $tableAlias => $tableName) {
            $additionalWhereClauses = $this->getAdditionalWhereClause($query->getQuerySettings(), $tableName, $tableAlias);
            $statement = $this->getVisibilityConstraintStatement($query->getQuerySettings(), $tableName, $tableAlias);
            if ($statement !== '') {
                $additionalWhereClauses[] = $statement;
            }
            if (!empty($additionalWhereClauses)) {
                if (in_array($tableAlias, $this->unionTableAliasCache, true)) {
                    $this->queryBuilder->andWhere(
                        $this->queryBuilder->expr()->orX(
                            $this->queryBuilder->expr()->andX(...$additionalWhereClauses),
                            $this->queryBuilder->expr()->isNull($tableAlias . '.uid')
                        )
                    );
                } else {
                    $this->queryBuilder->andWhere(...$additionalWhereClauses);
                }
            }
        }
    }

    /**
     * Parse a Comparison into SQL and parameter arrays.
     *
     * @param Qom\ComparisonInterface $comparison The comparison to parse
     * @param Qom\SourceInterface $source The source
     * @return string
     * @throws \RuntimeException
     * @throws RepositoryException
     * @throws Exception\BadConstraintException
     */
    protected function parseComparison(Qom\ComparisonInterface $comparison, Qom\SourceInterface $source)
    {
        if ($comparison->getOperator() === QueryInterface::OPERATOR_CONTAINS) {
            if ($comparison->getOperand2() === null) {
                throw new Exception\BadConstraintException('The value for the CONTAINS operator must not be null.', 1484828468);
            } else {
                $value = $this->dataMapper->getPlainValue($comparison->getOperand2());
                if (!$source instanceof Qom\SelectorInterface) {
                    throw new \RuntimeException('Source is not of type "SelectorInterface"', 1395362539);
                }
                $className = $source->getNodeTypeName();
                $tableName = $this->dataMapper->convertClassNameToTableName($className);
                $operand1 = $comparison->getOperand1();
                $propertyName = $operand1->getPropertyName();
                $fullPropertyPath = '';
                while (strpos($propertyName, '.') !== false) {
                    $this->addUnionStatement($className, $tableName, $propertyName, $fullPropertyPath);
                }
                $columnName = $this->dataMapper->convertPropertyNameToColumnName($propertyName, $className);
                $dataMap = $this->dataMapper->getDataMap($className);
                $columnMap = $dataMap->getColumnMap($propertyName);
                $typeOfRelation = $columnMap instanceof ColumnMap ? $columnMap->getTypeOfRelation() : null;
                if ($typeOfRelation === ColumnMap::RELATION_HAS_AND_BELONGS_TO_MANY) {
                    $relationTableName = $columnMap->getRelationTableName();
                    $queryBuilderForSubselect = $this->queryBuilder->getConnection()->createQueryBuilder();
                    $queryBuilderForSubselect
                        ->select($columnMap->getParentKeyFieldName())
                        ->from($relationTableName)
                        ->where(
                            $queryBuilderForSubselect->expr()->eq(
                                $columnMap->getChildKeyFieldName(),
                                $this->queryBuilder->createNamedParameter($value)
                            )
                        );
                    $additionalWhereForMatchFields = $this->getAdditionalMatchFieldsStatement($queryBuilderForSubselect->expr(), $columnMap, $relationTableName, $relationTableName);
                    if ($additionalWhereForMatchFields) {
                        $queryBuilderForSubselect->andWhere($additionalWhereForMatchFields);
                    }

                    return $this->queryBuilder->expr()->comparison(
                        $this->queryBuilder->quoteIdentifier($tableName . '.uid'),
                        'IN',
                        '(' . $queryBuilderForSubselect->getSQL() . ')'
                    );
                } elseif ($typeOfRelation === ColumnMap::RELATION_HAS_MANY) {
                    $parentKeyFieldName = $columnMap->getParentKeyFieldName();
                    if (isset($parentKeyFieldName)) {
                        $childTableName = $columnMap->getChildTableName();

                        // Build the SQL statement of the subselect
                        $queryBuilderForSubselect = $this->queryBuilder->getConnection()->createQueryBuilder();
                        $queryBuilderForSubselect
                            ->select($parentKeyFieldName)
                            ->from($childTableName)
                            ->where(
                                $queryBuilderForSubselect->expr()->eq(
                                    'uid',
                                    (int)$value
                                )
                            );

                        // Add it to the main query
                        return $this->queryBuilder->expr()->eq(
                            $tableName . '.uid',
                            $queryBuilderForSubselect->getSQL()
                        );
                    } else {
                        return $this->queryBuilder->expr()->inSet(
                            $tableName . '.' . $columnName,
                            $this->queryBuilder->createNamedParameter($value)
                        );
                    }
                } else {
                    throw new RepositoryException('Unsupported or non-existing property name "' . $propertyName . '" used in relation matching.', 1327065745);
                }
            }
        } else {
            return $this->parseDynamicOperand($comparison, $source);
        }
    }

    /**
     * Parse a DynamicOperand into SQL and parameter arrays.
     *
     * @param Qom\ComparisonInterface $comparison
     * @param Qom\SourceInterface $source The source
     * @return string
     * @throws \TYPO3\CMS\Extbase\Persistence\Generic\Exception
     * @throws Exception\BadConstraintException
     */
    protected function parseDynamicOperand(Qom\ComparisonInterface $comparison, Qom\SourceInterface $source)
    {
        $value = $comparison->getOperand2();
        $fieldName = $this->parseOperand($comparison->getOperand1(), $source);
        $expr = null;
        $exprBuilder = $this->queryBuilder->expr();
        switch ($comparison->getOperator()) {
            case QueryInterface::OPERATOR_IN:
                $hasValue = false;
                $plainValues = [];
                foreach ($value as $singleValue) {
                    $plainValue = $this->dataMapper->getPlainValue($singleValue);
                    if ($plainValue !== null) {
                        $hasValue = true;
                        $parameterType = ctype_digit((string)$plainValue) ? \PDO::PARAM_INT : \PDO::PARAM_STR;
                        $plainValues[] = $this->queryBuilder->createNamedParameter($plainValue, $parameterType);
                    }
                }
                if (!$hasValue) {
                    throw new Exception\BadConstraintException('The IN operator needs a non-empty value list to compare against. The given value list is empty.', 1484828466);
                }
                $expr = $exprBuilder->comparison($fieldName, 'IN', '(' . implode(', ', $plainValues) . ')');
                break;
            case QueryInterface::OPERATOR_EQUAL_TO:
                if ($value === null) {
                    $expr = $fieldName . ' IS NULL';
                } else {
                    $value = $this->queryBuilder->createNamedParameter($this->dataMapper->getPlainValue($value));
                    $expr = $exprBuilder->comparison($fieldName, $exprBuilder::EQ, $value);
                }
                break;
            case QueryInterface::OPERATOR_EQUAL_TO_NULL:
                $expr = $fieldName . ' IS NULL';
                break;
            case QueryInterface::OPERATOR_NOT_EQUAL_TO:
                if ($value === null) {
                    $expr = $fieldName . ' IS NOT NULL';
                } else {
                    $value = $this->queryBuilder->createNamedParameter($this->dataMapper->getPlainValue($value));
                    $expr = $exprBuilder->comparison($fieldName, $exprBuilder::NEQ, $value);
                }
                break;
            case QueryInterface::OPERATOR_NOT_EQUAL_TO_NULL:
                $expr = $fieldName . ' IS NOT NULL';
                break;
            case QueryInterface::OPERATOR_LESS_THAN:
                $value = $this->queryBuilder->createNamedParameter($this->dataMapper->getPlainValue($value), \PDO::PARAM_INT);
                $expr = $exprBuilder->comparison($fieldName, $exprBuilder::LT, $value);
                break;
            case QueryInterface::OPERATOR_LESS_THAN_OR_EQUAL_TO:
                $value = $this->queryBuilder->createNamedParameter($this->dataMapper->getPlainValue($value), \PDO::PARAM_INT);
                $expr = $exprBuilder->comparison($fieldName, $exprBuilder::LTE, $value);
                break;
            case QueryInterface::OPERATOR_GREATER_THAN:
                $value = $this->queryBuilder->createNamedParameter($this->dataMapper->getPlainValue($value), \PDO::PARAM_INT);
                $expr = $exprBuilder->comparison($fieldName, $exprBuilder::GT, $value);
                break;
            case QueryInterface::OPERATOR_GREATER_THAN_OR_EQUAL_TO:
                $value = $this->queryBuilder->createNamedParameter($this->dataMapper->getPlainValue($value), \PDO::PARAM_INT);
                $expr = $exprBuilder->comparison($fieldName, $exprBuilder::GTE, $value);
                break;
            case QueryInterface::OPERATOR_LIKE:
                $value = $this->queryBuilder->createNamedParameter($this->dataMapper->getPlainValue($value));
                $expr = $exprBuilder->comparison($fieldName, 'LIKE', $value);
                break;
            default:
                throw new \TYPO3\CMS\Extbase\Persistence\Generic\Exception('Unsupported operator encountered.', 1242816073);
        }
        return $expr;
    }

    /**
     * @param Qom\DynamicOperandInterface $operand
     * @param Qom\SourceInterface $source The source
     * @return string
     * @throws \InvalidArgumentException
     */
    protected function parseOperand(Qom\DynamicOperandInterface $operand, Qom\SourceInterface $source)
    {
        if ($operand instanceof Qom\LowerCaseInterface) {
            $constraintSQL = 'LOWER(' . $this->parseOperand($operand->getOperand(), $source) . ')';
        } elseif ($operand instanceof Qom\UpperCaseInterface) {
            $constraintSQL = 'UPPER(' . $this->parseOperand($operand->getOperand(), $source) . ')';
        } elseif ($operand instanceof Qom\PropertyValueInterface) {
            $propertyName = $operand->getPropertyName();
            $className = '';
            if ($source instanceof Qom\SelectorInterface) {
                $className = $source->getNodeTypeName();
                $tableName = $this->dataMapper->convertClassNameToTableName($className);
                $fullPropertyPath = '';
                while (strpos($propertyName, '.') !== false) {
                    $this->addUnionStatement($className, $tableName, $propertyName, $fullPropertyPath);
                }
            } elseif ($source instanceof Qom\JoinInterface) {
                $tableName = $source->getJoinCondition()->getSelector1Name();
            }
            $columnName = $this->dataMapper->convertPropertyNameToColumnName($propertyName, $className);
            $constraintSQL = (!empty($tableName) ? $tableName . '.' : '') . $columnName;
            $constraintSQL = $this->queryBuilder->getConnection()->quoteIdentifier($constraintSQL);
        } else {
            throw new \InvalidArgumentException('Given operand has invalid type "' . get_class($operand) . '".', 1395710211);
        }
        return $constraintSQL;
    }

    /**
     * Add a constraint to ensure that the record type of the returned tuples is matching the data type of the repository.
     *
     * @param string $className The class name
     */
    protected function addRecordTypeConstraint($className)
    {
        if ($className !== null) {
            $dataMap = $this->dataMapper->getDataMap($className);
            if ($dataMap->getRecordTypeColumnName() !== null) {
                $recordTypes = [];
                if ($dataMap->getRecordType() !== null) {
                    $recordTypes[] = $dataMap->getRecordType();
                }
                foreach ($dataMap->getSubclasses() as $subclassName) {
                    $subclassDataMap = $this->dataMapper->getDataMap($subclassName);
                    if ($subclassDataMap->getRecordType() !== null) {
                        $recordTypes[] = $subclassDataMap->getRecordType();
                    }
                }
                if (!empty($recordTypes)) {
                    $recordTypeStatements = [];
                    foreach ($recordTypes as $recordType) {
                        $tableName = $dataMap->getTableName();
                        $recordTypeStatements[] = $this->queryBuilder->expr()->eq(
                            $tableName . '.' . $dataMap->getRecordTypeColumnName(),
                            $this->queryBuilder->createNamedParameter($recordType)
                        );
                    }
                    $this->queryBuilder->andWhere(
                        $this->queryBuilder->expr()->orX(...$recordTypeStatements)
                    );
                }
            }
        }
    }

    /**
     * Builds a condition for filtering records by the configured match field,
     * e.g. MM_match_fields, foreign_match_fields or foreign_table_field.
     *
     * @param ExpressionBuilder $exprBuilder
     * @param ColumnMap $columnMap The column man for which the condition should be build.
     * @param string $childTableAlias The alias of the child record table used in the query.
     * @param string $parentTable The real name of the parent table (used for building the foreign_table_field condition).
     * @return string The match field conditions or an empty string.
     */
    protected function getAdditionalMatchFieldsStatement($exprBuilder, $columnMap, $childTableAlias, $parentTable = null)
    {
        $additionalWhereForMatchFields = [];
        $relationTableMatchFields = $columnMap->getRelationTableMatchFields();
        if (is_array($relationTableMatchFields) && !empty($relationTableMatchFields)) {
            foreach ($relationTableMatchFields as $fieldName => $value) {
                $additionalWhereForMatchFields[] = $exprBuilder->eq($childTableAlias . '.' . $fieldName, $this->queryBuilder->createNamedParameter($value));
            }
        }

        if (isset($parentTable)) {
            $parentTableFieldName = $columnMap->getParentTableFieldName();
            if (!empty($parentTableFieldName)) {
                $additionalWhereForMatchFields[] = $exprBuilder->eq($childTableAlias . '.' . $parentTableFieldName, $this->queryBuilder->createNamedParameter($parentTable));
            }
        }

        if (!empty($additionalWhereForMatchFields)) {
            return $exprBuilder->andX(...$additionalWhereForMatchFields);
        } else {
            return '';
        }
    }

    /**
     * Adds additional WHERE statements according to the query settings.
     *
     * @param QuerySettingsInterface $querySettings The TYPO3 CMS specific query settings
     * @param string $tableName The table name to add the additional where clause for
     * @param string $tableAlias The table alias used in the query.
     * @return array
     */
    protected function getAdditionalWhereClause(QuerySettingsInterface $querySettings, $tableName, $tableAlias = null)
    {
        $whereClause = [];
        if ($querySettings->getRespectSysLanguage()) {
            $systemLanguageStatement = $this->getSysLanguageStatement($tableName, $tableAlias, $querySettings);
            if (!empty($systemLanguageStatement)) {
                $whereClause[] = $systemLanguageStatement;
            }
        }

        if ($querySettings->getRespectStoragePage()) {
            $pageIdStatement = $this->getPageIdStatement($tableName, $tableAlias, $querySettings->getStoragePageIds());
            if (!empty($pageIdStatement)) {
                $whereClause[] = $pageIdStatement;
            }
        }

        return $whereClause;
    }

    /**
     * Adds enableFields and deletedClause to the query if necessary
     *
     * @param QuerySettingsInterface $querySettings
     * @param string $tableName The database table name
     * @param string $tableAlias
     * @return string
     */
    protected function getVisibilityConstraintStatement(QuerySettingsInterface $querySettings, $tableName, $tableAlias)
    {
        $statement = '';
        if (is_array($GLOBALS['TCA'][$tableName]['ctrl'])) {
            $ignoreEnableFields = $querySettings->getIgnoreEnableFields();
            $enableFieldsToBeIgnored = $querySettings->getEnableFieldsToBeIgnored();
            $includeDeleted = $querySettings->getIncludeDeleted();
            if ($this->environmentService->isEnvironmentInFrontendMode()) {
                $statement .= $this->getFrontendConstraintStatement($tableName, $ignoreEnableFields, $enableFieldsToBeIgnored, $includeDeleted);
            } else {
                // TYPO3_MODE === 'BE'
                $statement .= $this->getBackendConstraintStatement($tableName, $ignoreEnableFields, $includeDeleted);
            }
            if (!empty($statement)) {
                $statement = $this->replaceTableNameWithAlias($statement, $tableName, $tableAlias);
                $statement = strtolower(substr($statement, 1, 3)) === 'and' ? substr($statement, 5) : $statement;
            }
        }
        return $statement;
    }

    /**
     * Returns constraint statement for frontend context
     *
     * @param string $tableName
     * @param bool $ignoreEnableFields A flag indicating whether the enable fields should be ignored
     * @param array $enableFieldsToBeIgnored If $ignoreEnableFields is true, this array specifies enable fields to be ignored. If it is NULL or an empty array (default) all enable fields are ignored.
     * @param bool $includeDeleted A flag indicating whether deleted records should be included
     * @return string
     * @throws InconsistentQuerySettingsException
     */
    protected function getFrontendConstraintStatement($tableName, $ignoreEnableFields, array $enableFieldsToBeIgnored = [], $includeDeleted)
    {
        $statement = '';
        if ($ignoreEnableFields && !$includeDeleted) {
            if (!empty($enableFieldsToBeIgnored)) {
                // array_combine() is necessary because of the way \TYPO3\CMS\Frontend\Page\PageRepository::enableFields() is implemented
                $statement .= $this->getPageRepository()->enableFields($tableName, -1, array_combine($enableFieldsToBeIgnored, $enableFieldsToBeIgnored));
            } else {
                $statement .= $this->getPageRepository()->deleteClause($tableName);
            }
        } elseif (!$ignoreEnableFields && !$includeDeleted) {
            $statement .= $this->getPageRepository()->enableFields($tableName);
        } elseif (!$ignoreEnableFields && $includeDeleted) {
            throw new InconsistentQuerySettingsException('Query setting "ignoreEnableFields=FALSE" can not be used together with "includeDeleted=TRUE" in frontend context.', 1460975922);
        }
        return $statement;
    }

    /**
     * Returns constraint statement for backend context
     *
     * @param string $tableName
     * @param bool $ignoreEnableFields A flag indicating whether the enable fields should be ignored
     * @param bool $includeDeleted A flag indicating whether deleted records should be included
     * @return string
     */
    protected function getBackendConstraintStatement($tableName, $ignoreEnableFields, $includeDeleted)
    {
        $statement = '';
        if (!$ignoreEnableFields) {
            $statement .= BackendUtility::BEenableFields($tableName);
        }
        if (!$includeDeleted) {
            $statement .= BackendUtility::deleteClause($tableName);
        }
        return $statement;
    }

    /**
     * Builds the language field statement
     *
     * @param string $tableName The database table name
     * @param string $tableAlias The table alias used in the query.
     * @param QuerySettingsInterface $querySettings The TYPO3 CMS specific query settings
     * @return string
     */
    protected function getSysLanguageStatement($tableName, $tableAlias, $querySettings)
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
                        $queryBuilderForSubselect = $this->queryBuilder->getConnection()->createQueryBuilder();
                        $queryBuilderForSubselect
                            ->select($tableName . '.' . $GLOBALS['TCA'][$tableName]['ctrl']['transOrigPointerField'])
                            ->from($tableName)
                            ->where(
                                $queryBuilderForSubselect->expr()->andX(
                                    $queryBuilderForSubselect->expr()->gt($tableName . '.' . $GLOBALS['TCA'][$tableName]['ctrl']['transOrigPointerField'], 0),
                                    $queryBuilderForSubselect->expr()->eq($tableName . '.' . $languageField, (int)$querySettings->getLanguageUid())
                                )
                            );
                        return $this->queryBuilder->expr()->orX(
                            $this->queryBuilder->expr()->eq($tableAlias . '.' . $languageField, -1),
                            $this->queryBuilder->expr()->andX(
                                $this->queryBuilder->expr()->eq($tableAlias . '.' . $languageField, (int)$querySettings->getLanguageUid()),
                                $this->queryBuilder->expr()->eq($tableAlias . '.' . $GLOBALS['TCA'][$tableName]['ctrl']['transOrigPointerField'], 0)
                            ),
                            $this->queryBuilder->expr()->andX(
                                $this->queryBuilder->expr()->eq($tableAlias . '.' . $languageField, 0),
                                $this->queryBuilder->expr()->in(
                                    $tableAlias . '.uid',
                                    $queryBuilderForSubselect->getSQL()

                                )
                            )
                        );
                    } else {
                        $queryBuilderForSubselect = $this->queryBuilder->getConnection()->createQueryBuilder();
                        $queryBuilderForSubselect
                            ->select($tableAlias . '.' . $GLOBALS['TCA'][$tableName]['ctrl']['transOrigPointerField'])
                            ->from($tableName)
                            ->where(
                                $queryBuilderForSubselect->expr()->andX(
                                    $queryBuilderForSubselect->expr()->gt($tableName . '.' . $GLOBALS['TCA'][$tableName]['ctrl']['transOrigPointerField'], 0),
                                    $queryBuilderForSubselect->expr()->eq($tableName . '.' . $languageField, (int)$querySettings->getLanguageUid())
                                )
                            );
                        return $this->queryBuilder->expr()->orX(
                            $this->queryBuilder->expr()->in($tableAlias . '.' . $languageField, [(int)$querySettings->getLanguageUid(), -1]),
                            $this->queryBuilder->expr()->andX(
                                $this->queryBuilder->expr()->eq($tableAlias . '.' . $languageField, 0),
                                $this->queryBuilder->expr()->notIn(
                                    $tableAlias . '.uid',
                                    $queryBuilderForSubselect->getSQL()

                                )
                            )
                        );
                    }
                } else {
                    return $this->queryBuilder->expr()->in(
                        $tableAlias . '.' . $languageField,
                        [(int)$querySettings->getLanguageUid(), -1]
                    );
                }
            }
        }
        return '';
    }

    /**
     * Builds the page ID checking statement
     *
     * @param string $tableName The database table name
     * @param string $tableAlias The table alias used in the query.
     * @param array $storagePageIds list of storage page ids
     * @throws InconsistentQuerySettingsException
     * @return string
     */
    protected function getPageIdStatement($tableName, $tableAlias, array $storagePageIds)
    {
        if (!is_array($GLOBALS['TCA'][$tableName]['ctrl'])) {
            return '';
        }

        $rootLevel = (int)$GLOBALS['TCA'][$tableName]['ctrl']['rootLevel'];
        switch ($rootLevel) {
            // Only in pid 0
            case 1:
                $storagePageIds = [0];
                break;
            // Pid 0 and pagetree
            case -1:
                if (empty($storagePageIds)) {
                    $storagePageIds = [0];
                } else {
                    $storagePageIds[] = 0;
                }
                break;
            // Only pagetree or not set
            case 0:
                if (empty($storagePageIds)) {
                    throw new InconsistentQuerySettingsException('Missing storage page ids.', 1365779762);
                }
                break;
            // Invalid configuration
            default:
                return '';
        }
        $storagePageIds = array_map('intval', $storagePageIds);
        if (count($storagePageIds) === 1) {
            return $this->queryBuilder->expr()->eq($tableAlias . '.pid', reset($storagePageIds));
        } else {
            return $this->queryBuilder->expr()->in($tableAlias . '.pid', $storagePageIds);
        }
    }

    /**
     * Transforms a Join into SQL and parameter arrays
     *
     * @param Qom\JoinInterface $join The join
     * @param string $leftTableAlias The alias from the table to main
     */
    protected function parseJoin(Qom\JoinInterface $join, $leftTableAlias)
    {
        $leftSource = $join->getLeft();
        $leftClassName = $leftSource->getNodeTypeName();
        $this->addRecordTypeConstraint($leftClassName);
        $rightSource = $join->getRight();
        if ($rightSource instanceof Qom\JoinInterface) {
            $left = $rightSource->getLeft();
            $rightClassName = $left->getNodeTypeName();
            $rightTableName = $left->getSelectorName();
        } else {
            $rightClassName = $rightSource->getNodeTypeName();
            $rightTableName = $rightSource->getSelectorName();
            $this->queryBuilder->addSelect($rightTableName . '.*');
        }
        $this->addRecordTypeConstraint($rightClassName);
        $rightTableAlias = $this->getUniqueAlias($rightTableName);
        $joinCondition = $join->getJoinCondition();
        $joinConditionExpression = null;
        $this->unionTableAliasCache[] = $rightTableAlias;
        if ($joinCondition instanceof Qom\EquiJoinCondition) {
            $column1Name = $this->dataMapper->convertPropertyNameToColumnName($joinCondition->getProperty1Name(), $leftClassName);
            $column2Name = $this->dataMapper->convertPropertyNameToColumnName($joinCondition->getProperty2Name(), $rightClassName);

            $joinConditionExpression =  $this->queryBuilder->expr()->eq(
                $leftTableAlias . '.' . $column1Name,
                $this->queryBuilder->quoteIdentifier($rightTableAlias . '.' . $column2Name)
            );
        }
        $this->queryBuilder->leftJoin($leftTableAlias, $rightTableName, $rightTableAlias, $joinConditionExpression);
        if ($rightSource instanceof Qom\JoinInterface) {
            $this->parseJoin($rightSource, $rightTableAlias);
        }
    }

    /**
     * Generates a unique alias for the given table and the given property path.
     * The property path will be mapped to the generated alias in the tablePropertyMap.
     *
     * @param string $tableName The name of the table for which the alias should be generated.
     * @param string $fullPropertyPath The full property path that is related to the given table.
     * @return string The generated table alias.
     */
    protected function getUniqueAlias($tableName, $fullPropertyPath = null)
    {
        if (isset($fullPropertyPath) && isset($this->tablePropertyMap[$fullPropertyPath])) {
            return $this->tablePropertyMap[$fullPropertyPath];
        }

        $alias = $tableName;
        $i = 0;
        while (isset($this->tableAliasMap[$alias])) {
            $alias = $tableName . $i;
            $i++;
        }

        $this->tableAliasMap[$alias] = $tableName;

        if (isset($fullPropertyPath)) {
            $this->tablePropertyMap[$fullPropertyPath] = $alias;
        }

        return $alias;
    }

    /**
     * adds a union statement to the query, mostly for tables referenced in the where condition.
     * The property for which the union statement is generated will be appended.
     *
     * @param string &$className The name of the parent class, will be set to the child class after processing.
     * @param string &$tableName The name of the parent table, will be set to the table alias that is used in the union statement.
     * @param string &$propertyPath The remaining property path, will be cut of by one part during the process.
     * @param string $fullPropertyPath The full path the the current property, will be used to make table names unique.
     * @throws \TYPO3\CMS\Extbase\Persistence\Generic\Exception
     * @throws InvalidRelationConfigurationException
     * @throws MissingColumnMapException
     */
    protected function addUnionStatement(&$className, &$tableName, &$propertyPath, &$fullPropertyPath)
    {
        $explodedPropertyPath = explode('.', $propertyPath, 2);
        $propertyName = $explodedPropertyPath[0];
        $columnName = $this->dataMapper->convertPropertyNameToColumnName($propertyName, $className);
        $realTableName = $this->dataMapper->convertClassNameToTableName($className);
        $tableName = isset($this->tablePropertyMap[$fullPropertyPath]) ? $this->tablePropertyMap[$fullPropertyPath] : $realTableName;
        $columnMap = $this->dataMapper->getDataMap($className)->getColumnMap($propertyName);

        if ($columnMap === null) {
            throw new MissingColumnMapException('The ColumnMap for property "' . $propertyName . '" of class "' . $className . '" is missing.', 1355142232);
        }

        $parentKeyFieldName = $columnMap->getParentKeyFieldName();
        $childTableName = $columnMap->getChildTableName();

        if ($childTableName === null) {
            throw new InvalidRelationConfigurationException('The relation information for property "' . $propertyName . '" of class "' . $className . '" is missing.', 1353170925);
        }

        $fullPropertyPath .= ($fullPropertyPath === '') ? $propertyName : '.' . $propertyName;
        $childTableAlias = $this->getUniqueAlias($childTableName, $fullPropertyPath);

        // If there is already a union with the current identifier we do not need to build it again and exit early.
        if (in_array($childTableAlias, $this->unionTableAliasCache, true)) {
            $propertyPath = $explodedPropertyPath[1];
            $tableName = $childTableAlias;
            $className = $this->dataMapper->getType($className, $propertyName);
            return;
        }

        if ($columnMap->getTypeOfRelation() === ColumnMap::RELATION_HAS_ONE) {
            if (isset($parentKeyFieldName)) {
                // @todo: no test for this part yet
                $joinConditionExpression = $this->queryBuilder->expr()->eq(
                    $tableName . '.uid',
                    $this->queryBuilder->quoteIdentifier($childTableAlias . '.' . $parentKeyFieldName)
                );
            } else {
                $joinConditionExpression = $this->queryBuilder->expr()->eq(
                    $tableName . '.' . $columnName,
                    $this->queryBuilder->quoteIdentifier($childTableAlias . '.uid')
                );
            }
            $this->queryBuilder->leftJoin($tableName, $childTableName, $childTableAlias, $joinConditionExpression);
            $this->unionTableAliasCache[] = $childTableAlias;
            $this->queryBuilder->andWhere(
                $this->getAdditionalMatchFieldsStatement($this->queryBuilder->expr(), $columnMap, $childTableAlias, $realTableName)
            );
        } elseif ($columnMap->getTypeOfRelation() === ColumnMap::RELATION_HAS_MANY) {
            // @todo: no tests for this part yet
            if (isset($parentKeyFieldName)) {
                $joinConditionExpression = $this->queryBuilder->expr()->eq(
                    $tableName . '.uid',
                    $this->queryBuilder->quoteIdentifier($childTableAlias . '.' . $parentKeyFieldName)
                );
            } else {
                $joinConditionExpression = $this->queryBuilder->expr()->inSet(
                    $tableName . '.' . $columnName,
                    $this->queryBuilder->quoteIdentifier($childTableAlias . '.uid'),
                    true
                );
            }
            $this->queryBuilder->leftJoin($tableName, $childTableName, $childTableAlias, $joinConditionExpression);
            $this->unionTableAliasCache[] = $childTableAlias;
            $this->queryBuilder->andWhere(
                $this->getAdditionalMatchFieldsStatement($this->queryBuilder->expr(), $columnMap, $childTableAlias, $realTableName)
            );
        } elseif ($columnMap->getTypeOfRelation() === ColumnMap::RELATION_HAS_AND_BELONGS_TO_MANY) {
            $relationTableName = $columnMap->getRelationTableName();
            $relationTableAlias = $relationTableAlias = $this->getUniqueAlias($relationTableName, $fullPropertyPath . '_mm');

            $joinConditionExpression = $this->queryBuilder->expr()->andX(
                $this->queryBuilder->expr()->eq(
                    $tableName . '.uid',
                    $this->queryBuilder->quoteIdentifier(
                        $relationTableAlias . '.' . $columnMap->getParentKeyFieldName()
                    )
                ),
                $this->getAdditionalMatchFieldsStatement($this->queryBuilder->expr(), $columnMap, $relationTableAlias, $realTableName)
            );
            $this->queryBuilder->leftJoin($tableName, $relationTableName, $relationTableAlias, $joinConditionExpression);
            $joinConditionExpression = $this->queryBuilder->expr()->eq(
                $relationTableAlias . '.' . $columnMap->getChildKeyFieldName(),
                $this->queryBuilder->quoteIdentifier($childTableAlias . '.uid')
            );
            $this->queryBuilder->leftJoin($relationTableAlias, $childTableName, $childTableAlias, $joinConditionExpression);
            $this->unionTableAliasCache[] = $childTableAlias;
            $this->queryBuilder->addGroupBy($this->tableName . '.uid');
        } else {
            throw new \TYPO3\CMS\Extbase\Persistence\Generic\Exception('Could not determine type of relation.', 1252502725);
        }
        $propertyPath = $explodedPropertyPath[1];
        $tableName = $childTableAlias;
        $className = $this->dataMapper->getType($className, $propertyName);
    }

    /**
     * If the table name does not match the table alias all occurrences of
     * "tableName." are replaced with "tableAlias." in the given SQL statement.
     *
     * @param string $statement The SQL statement in which the values are replaced.
     * @param string $tableName The table name that is replaced.
     * @param string $tableAlias The table alias that replaced the table name.
     * @return string The modified SQL statement.
     */
    protected function replaceTableNameWithAlias($statement, $tableName, $tableAlias)
    {
        if ($tableAlias !== $tableName) {
            /** @var Connection $connection */
            $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($tableName);
            $quotedTableName = $connection->quoteIdentifier($tableName);
            $quotedTableAlias = $connection->quoteIdentifier($tableAlias);
            $statement = str_replace(
                [$tableName . '.', $quotedTableName . '.'],
                [$tableAlias . '.', $quotedTableAlias . '.'],
                $statement
            );
        }

        return $statement;
    }

    /**
     * @return PageRepository
     */
    protected function getPageRepository()
    {
        if (!$this->pageRepository instanceof PageRepository) {
            if ($this->environmentService->isEnvironmentInFrontendMode() && is_object($GLOBALS['TSFE'])) {
                $this->pageRepository = $GLOBALS['TSFE']->sys_page;
            } else {
                $this->pageRepository = GeneralUtility::makeInstance(PageRepository::class);
            }
        }

        return $this->pageRepository;
    }
}
