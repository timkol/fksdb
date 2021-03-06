<?php

namespace Persons\Deduplication;

use FKS\Logging\ILogger;
use Nette\Database\Connection;
use Nette\Database\Reflection\AmbiguousReferenceKeyException;
use Nette\Database\Reflection\MissingReferenceException;
use Nette\Database\Table\ActiveRow;
use Nette\InvalidStateException;
use Persons\Deduplication\MergeStrategy\CannotMergeException;
use Persons\Deduplication\MergeStrategy\IMergeStrategy;

/**
 * Due to author's laziness there's no class doc (or it's self explaining).
 * 
 * @note Works with single column primary keys only.
 * @note Assumes name of the FK column is the same like the referenced PK column.
 * 
 * @author Michal Koutný <michal@fykos.cz>
 */
class TableMerger {

    /**
     * @var string
     */
    private $table;

    /**
     * @var Merger
     */
    private $merger;

    /**
     * @var Connection
     */
    private $connection;

    /**
     * @var ActiveRow
     */
    private $trunkRow;

    /**
     * @var ActiveRow
     */
    private $mergedRow;

    /**
     * @var IMergeStrategy[]
     */
    private $columnMergeStrategies = array();

    /**
     * @var IMergeStrategy
     */
    private $globalMergeStrategy;

    /**
     * @var ILogger
     */
    private $logger;

    function __construct($table, Merger $merger, Connection $connection, IMergeStrategy $globalMergeStrategy, ILogger $logger) {
        $this->table = $table;
        $this->merger = $merger;
        $this->connection = $connection;
        $this->globalMergeStrategy = $globalMergeStrategy;
        $this->logger = $logger;
    }

    /*     * ******************************
     * Merging
     * ****************************** */

    public function setMergedPair(ActiveRow $trunkRow, ActiveRow $mergedRow) {
        $this->trunkRow = $trunkRow;
        $this->mergedRow = $mergedRow;
    }

    public function setColumnMergeStrategy($column, IMergeStrategy $mergeStrategy = null) {
        if (!$mergeStrategy) {
            unset($this->columnMergeStrategies[$column]);
        } else {
            $this->columnMergeStrategies[$column] = $mergeStrategy;
        }
    }

    /**
     * 
     * @param mixed $column
     * @return boolean
     */
    private function tryColumnMerge($column) {
        if ($this->getMerger()->hasResolution($this->trunkRow, $this->mergedRow, $column)) {
            $values = array(
                $column => $this->getMerger()->getResolution($this->trunkRow, $this->mergedRow, $column),
            );
            $this->logUpdate($this->trunkRow, $values);
            $this->trunkRow->update($values);
            return true;
        } else {
            if (isset($this->columnMergeStrategies[$column])) {
                $strategy = $this->columnMergeStrategies[$column];
            } else {
                $strategy = $this->globalMergeStrategy;
            }
            try {
                $values = array(
                    $column => $strategy->mergeValues($this->trunkRow[$column], $this->mergedRow[$column]),
                );
                $this->logUpdate($this->trunkRow, $values);
                $this->trunkRow->update($values);
                return true;
            } catch (CannotMergeException $e) {
                return false;
            }
        }
    }

    /**
     * @return Merger
     */
    private function getMerger() {
        return $this->merger;
    }

    public function merge($mergedParent = null) {
        $this->trunkRow->getTable()->accessColumn(null); // stupid touch
        $this->mergedRow->getTable()->accessColumn(null); // stupid touch

        /*
         * We merge child-rows (referencing rows) of the merged rows.
         * We get the list of possible referncing tables from the database reflection.
         */
        foreach ($this->getReferencingTables() as $referencingTable => $FKcolumn) {
            $referencingMerger = $this->getMerger()->getMerger($referencingTable);

            $trunkDependants = $this->trunkRow->related($referencingTable);
            $mergedDependants = $this->mergedRow->related($referencingTable);

            $newParent = array(
                $FKcolumn => $this->trunkRow->getPrimary()
            );
            /*
             * If simply changing the parent would violate some constraints (i.e. parent
             * can have only one child with certain properties -- that's the secondary key),
             * we have to recursively merge the children with the same secondary key.
             */
            if ($referencingMerger->getSecondaryKey()) {
                /* Group by ignores the FKcolumn value, as it's being changed. */
                $groupedTrunks = $referencingMerger->groupBySecondaryKey($trunkDependants, $FKcolumn);
                $groupedMerged = $referencingMerger->groupBySecondaryKey($mergedDependants, $FKcolumn);
                $secondaryKeys = array_merge(array_keys($groupedTrunks), array_keys($groupedMerged));
                $secondaryKeys = array_unique($secondaryKeys);
                foreach ($secondaryKeys as $secondaryKey) {
                    $refTrunk = isset($groupedTrunks[$secondaryKey]) ? $groupedTrunks[$secondaryKey] : null;
                    $refMerged = isset($groupedMerged[$secondaryKey]) ? $groupedMerged[$secondaryKey] : null;
                    if ($refTrunk && $refMerged) {
                        $backTrunk = $referencingMerger->trunkRow;
                        $backMerged = $referencingMerger->mergedRow;
                        $referencingMerger->setMergedPair($refTrunk, $refMerged);
                        $referencingMerger->merge($newParent); // recursive merge
                        if ($backTrunk) {
                            $referencingMerger->setMergedPair($backTrunk, $backMerged);
                        }
                    } else if ($refMerged) {
                        $this->logUpdate($refMerged, $newParent);
                        $refMerged->update($newParent); //TODO allow delete refMerged
                    }
                }
            } else {
                /* Redirect dependant to the new parent. */
                foreach ($mergedDependants as $dependant) {
                    $this->logUpdate($dependant, $newParent);
                    $dependant->update($newParent);
                }
            }
        }
        /*
         * Delete merged row.
         * Must be done prior updating trunk as there may be unique constraint.
         */
        $this->mergedRow->delete();

        /*
         * Ordinary columns of merged rows are merged.
         */
        foreach ($this->getColumns() as $column) {
            /* Primary key is not merged. */
            if ($this->isPrimaryKey($column)) {
                continue;
            }
            /* When we are merging two rows under common parent, we ignore the foreign key. */
            if ($mergedParent && isset($mergedParent[$column])) {
                /* empty */ // row will be deleted eventually
                continue;
            }

            /* For all other columns, we try to apply merging strategy. */
            if (!$this->tryColumnMerge($column)) {
                $this->getMerger()->addConflict($this->trunkRow, $this->mergedRow, $column);
            }
        }


        /* Log the overeall changes. */
        $this->logDelete($this->mergedRow);
        $this->logTrunk($this->trunkRow);
    }

    private function groupBySecondaryKey($rows, $parentColumn) {
        $result = array();
        foreach ($rows as $row) {
            $key = $this->getSecondaryKeyValue($row, $parentColumn);
            if (isset($result[$key])) {
                throw new InvalidStateException('Secondary key is not a key.');
            }
            $result[$key] = $row;
        }
        return $result;
    }

    private function getSecondaryKeyValue(ActiveRow $row, $parentColumn) {
        $key = array();
        foreach ($this->getSecondaryKey() as $column) {
            if ($column == $parentColumn) {
                continue;
            }
            $key[] = $row[$column];
        }
        return implode('_', $key);
    }

    /*     * ******************************
     * Logging sugar
     * ****************************** */

    private function logUpdate(ActiveRow $row, $changes) {
        $msg = array();
        foreach ($changes as $column => $value) {
            if ($row[$column] != $value) {
                $msg[] = "$column -> $value";
            }
        }
        if ($msg) {
            $this->logger->log(sprintf(_('%s(%s) nové hodnoty: %s'), $row->getTable()->getName(), $row->getPrimary(), implode(', ', $msg)));
        }
    }

    private function logDelete(ActiveRow $row) {
        $this->logger->log(sprintf(_('%s(%s) sloučen a smazán.'), $row->getTable()->getName(), $row->getPrimary()));
    }

    private function logTrunk(ActiveRow $row) {
        $this->logger->log(sprintf(_('%s(%s) rozšířen sloučením.'), $row->getTable()->getName(), $row->getPrimary()));
    }

    /*     * ******************************
     * DB reflection
     * ****************************** */

    private $refTables = null;
    private static $refreshReferencing = true;

    private function getReferencingTables() {
        if ($this->refTables === null) {
            $this->refTables = array();
            foreach ($this->connection->getSupplementalDriver()->getTables() as $otherTable) {
                try {
                    list($table, $refColumn) = $this->connection->getDatabaseReflection()->getHasManyReference($this->table, $otherTable['name'], self::$refreshReferencing);
                    self::$refreshReferencing = false;
                    $this->refTables[$table] = $refColumn;
                } catch (MissingReferenceException $e) {
                    /* empty */
                } catch (AmbiguousReferenceKeyException $e) {
                    /* empty */
                }
            }
        }
        return $this->refTables;
    }

    private $columns = null;

    private function getColumns() {
        if ($this->columns === null) {
            $this->columns = array();
            foreach ($this->connection->getSupplementalDriver()->getColumns($this->table) as $column) {
                $this->columns[] = $column['name'];
            }
        }
        return $this->columns;
    }

    private $primaryKey;

    private function isPrimaryKey($column) {
        if ($this->primaryKey === null) {
            $this->primaryKey = $this->connection->getDatabaseReflection()->getPrimary($this->table);
        }
        return $column == $this->primaryKey;
    }

    private $referencedTables = array();
    private static $refreshReferenced = true;

    private function getReferencedTable($column) {
        if (!array_key_exists($column, $this->referencedTables)) {
            try {
                list($table, $refColumn) = $this->connection->getDatabaseReflection()->getBelongsToReference($this->table, $column, self::$refreshReferenced);
                self::$refreshReferenced = false;
                $this->referencedTables[$column] = $table;
            } catch (MissingReferenceException $e) {
                $this->referencedTables[$column] = null;
            }
        }
        return $this->referencedTables[$column];
    }

    private $secondaryKey;

    /**
     * @return array
     */
    private function getSecondaryKey() {
        if ($this->secondaryKey === null) {
            $this->secondaryKey = array();
            foreach ($this->connection->getSupplementalDriver()->getIndexes($this->table) as $index) {
                if ($index['unique']) {
                    $this->secondaryKey = array_merge($this->secondaryKey, $index['columns']);
                }
            }
            $this->secondaryKey = array_unique($this->secondaryKey);
        }

        return $this->secondaryKey;
    }

    public function setSecondaryKey($secondaryKey) {
        $this->secondaryKey = $secondaryKey;
    }

}
