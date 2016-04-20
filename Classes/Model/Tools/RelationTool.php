<?php


namespace Localizationteam\L10nmgr\Model\Tools;


use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\ReferenceIndex;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class RelationTool
{
    /**
     * @var ReferenceIndex
     */
    protected $referenceIndex = NULL;

    /**
     * @var array
     */
    protected $l10ncfg;

    /**
     * @var array
     */
    protected $tablesToInclude;

    /**
     * @var Tools
     */
    protected $t8Tools;

    /**
     * RelationTool constructor.
     */
    public function __construct()
    {
        $this->referenceIndex = GeneralUtility::makeInstance(ReferenceIndex::class);
        $this->t8Tools = GeneralUtility::makeInstance(Tools::class);
    }

    public function getRelatedRecords($flattened, $l10ncfg)
    {
        $this->l10ncfg = $l10ncfg;
        $this->tablesToInclude = explode(',', $this->l10ncfg['tablelist']);

        $relatedRecordsSignatures = $this->getRelatedRecordSignatures($flattened);

        return $relatedRecordsSignatures;
    }

    private function getRecordsSignatures($flattened)
    {
        $recordsInAccumulatedInformation = [];
        foreach($flattened as $table => $records) {
            foreach($records as $uid => $accumulatedInfo) {
                $recordsInAccumulatedInformation[$table . ':' . $uid] = [
                    'table' => $table,
                    'uid' => $uid,
                ];
            }
        }

        return $recordsInAccumulatedInformation;
    }

    /**
     * @param $flattened
     * @return array
     */
    private function getRelatedRecordSignatures($flattened)
    {
        $recordsInAccumulatedInformation = $this->getRecordsSignatures($flattened);

        $knownRecords = $recordsInAccumulatedInformation;
        $recordsToGetRelations = $recordsInAccumulatedInformation;
        $recordsToGetAcculumatedInformation = [];

        while ($record = array_shift($recordsToGetRelations)) {
            $table = $record['table'];
            $uid = $record['uid'];

            $row = BackendUtility::getRecord($table, $uid);
            $relations = $this->referenceIndex->getRelations($table, $row);

            foreach ($relations as $relation) {
                if ($relation['type'] == 'db') {
                    foreach ($relation['itemArray'] as $relatedRecord) {
                        $uid = $relatedRecord['id'];
                        $table = $relatedRecord['table'];

                        if (
                            in_array($table, $this->tablesToInclude)
                            && !array_key_exists($table . ':' . $uid, $knownRecords)
                        ) {
                            $relatedRecord = [
                                'table' => $table,
                                'uid' => $uid
                            ];

                            $knownRecords[$table . ':' . $uid] = $relatedRecord;
                            array_push($recordsToGetRelations, $relatedRecord);
                            array_push($recordsToGetAcculumatedInformation, $relatedRecord);
                        }
                    }
                }
            }
        }
        return $recordsToGetAcculumatedInformation;
    }
}