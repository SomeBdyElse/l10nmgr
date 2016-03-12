<?php


namespace Localizationteam\L10nmgr\Model\Tools;


use Localizationteam\L10nmgr\Model\InlineRelation;
use TYPO3\CMS\Core\Database\RelationHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class InlineRelationTool
{
    protected $l10ncfg = NULL;

    protected $inlineRelations = NULL;

    public function __construct($l10ncfg)
    {
        $this->l10ncfg = $l10ncfg;

        $this->inlineRelations = $this->computeInlineRelations();
    }


    public function addNestingInformation(&$accum)
    {
        $flattened = $this->flattenAccumulatedRecords($accum);

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
    protected function computeInlineRelations()
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
     * @param $accum
     * @return array flattened records table -> uid -> record
     */
    protected function flattenAccumulatedRecords(& $accum)
    {
        $flattened = [];
        foreach ($accum as &$pages) {
            foreach ($pages['items'] as $table => &$records) {
                foreach ($records as $uid => &$record) {
                    if (!array_key_exists($table, $flattened)) {
                        $flattened[$table] = [];
                    }
                    $flattened[$table][$uid] = &$record;
                }
            }
        }

        return $flattened;
    }
}