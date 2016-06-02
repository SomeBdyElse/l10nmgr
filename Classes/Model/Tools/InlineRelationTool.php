<?php


namespace Localizationteam\L10nmgr\Model\Tools;


use Localizationteam\L10nmgr\Model\InlineRelation;
use Localizationteam\L10nmgr\Model\L10nConfiguration;
use Localizationteam\L10nmgr\Model\RecordSignature;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\DatabaseConnection;
use TYPO3\CMS\Core\Database\RelationHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class InlineRelationTool
{
    /**
     * @var array
     */
    protected $l10ncfg = NULL;

    protected $inlineRelations = NULL;

    /**
     * @var DatabaseConnection
     */
    protected $db = NULL;

    public function __construct($l10ncfg)
    {
        $this->db = &$GLOBALS['TYPO3_DB'];
        $this->l10ncfg = $l10ncfg;

        $this->inlineRelations = $this->computeInlineRelations();
    }


    /**
     * @param array $flattened flattened accumulated records (will be modified)
     */
    public function addNestingInformation(&$flattened)
    {
        /** @var InlineRelation $inlineRelation */
        foreach($this->inlineRelations as $inlineRelation) {
            $parentTCAConf = $inlineRelation->parentTCAConf;
            $parentTable = $inlineRelation->parentTable;
            $parentField = $inlineRelation->parentField;

            if(array_key_exists($parentTable, $flattened)) {
                foreach($flattened[$parentTable] as $parentUid => &$parentRecord) {
                    /** @var RelationHandler $relationHandler */
                    $relationHandler = GeneralUtility::makeInstance(RelationHandler::class);
                    $relationHandler->currentTable = $parentTable;

                    $relationHandler->readForeignField($parentUid, $parentTCAConf);
                    $result = $relationHandler->itemArray;

                    $childRecords = [];
                    foreach($result as $childInfo) {
                        $childTable = $childInfo['table'];
                        $childUid = $childInfo['id'];

                        if(
                            array_key_exists($childTable, $flattened)
                            && array_key_exists($childUid, $flattened[$childTable])
                        ) {
                            $childRecords[] = &$flattened[$childTable][$childUid];
                        }
                    }

                    foreach($childRecords as &$childRecord) {
                        $childRecord['isInlineChild'] = TRUE;
                    }

                    if(count($childRecords) > 0) {
                        if(! array_key_exists('inlineChildren', $parentRecord)) {
                            $parentRecord['inlineChildren'] = [];
                        }

                        $parentRecord['inlineChildren'][$parentField] = $childRecords;
                    }
                }
            }
        }
    }


    /**
     * @return array
     */
    public function computeInlineRelations()
    {
        $tablesToConsider = GeneralUtility::trimExplode(',', $this->l10ncfg['tablelist'], true);

        $inlineRelations = [];

        foreach ($tablesToConsider as $table) {
            $columns = $GLOBALS['TCA'][$table]['columns'];
            foreach ($columns as $columnName => $columnConfiguration) {
                $config = $columnConfiguration['config'];

                if (
                    $config['type'] == 'inline'
                    && !isset($config['MM'])
                ) {
                    /** @var InlineRelation $inlineRelation */
                    $inlineRelation = new InlineRelation(
                        $table,
                        $columnName,
                        $config,
                        $config['foreign_table'],
                        $config['foreign_field']
                    );

                    $inlineRelation->foreign_table_field = $config['foreign_table_field'] ?: null;
                    $inlineRelation->foreign_match_fields = $config['foreign_match_fields'] ?: null;

                    $inlineRelations[] = $inlineRelation;
                }
            }
        }
        return $inlineRelations;
    }

    /**
     * Checks if the given record is a child of another record. If it is
     * this function returns the signature of the parent. False, if no
     * relation or no parent can be found.
     *
     * @param RecordSignature $potentialChildSignature
     * @return bool|RecordSignature record signature of the parents recor, or FALSE if there is no parent
     */
    public function checkIfRecordIsChildInInlineRelation(RecordSignature $potentialChildSignature)
    {
        /** @var InlineRelation $inlineRelation */
        foreach($this->inlineRelations as $inlineRelation) {
            if($inlineRelation->foreign_table == $potentialChildSignature->table) {
                $parentRecord = $this->db->exec_SELECTgetSingleRow(
                    'parentTable.uid',
                    (
                        $inlineRelation->parentTable . ' parentTable'
                        . ' JOIN ' . $inlineRelation->foreign_table . ' childTable ON (childTable.' . $inlineRelation->foreign_field . ' = parentTable.uid)'
                    ),
                    (
                        'childTable.uid = ' . $potentialChildSignature->uid
                        . BackendUtility::deleteClause($inlineRelation->parentTable, 'parentTable')
                    )
                );

                if(is_array($parentRecord)) {
                    return new RecordSignature($inlineRelation->parentTable, $parentRecord['uid']);
                }
            }
        }

        return FALSE;
    }
}