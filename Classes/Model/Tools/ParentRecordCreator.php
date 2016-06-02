<?php


namespace Localizationteam\L10nmgr\Model\Tools;


use Localizationteam\L10nmgr\Model\L10nConfiguration;
use Localizationteam\L10nmgr\Model\RecordSignature;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\DatabaseConnection;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class ParentRecordCreator
{

    /**
     * @var L10nConfiguration
     */
    protected $l10nConfiguration;

    /**
     * @var InlineRelationTool
     */
    protected $inlineRelationTool;

    /**
     * @var array
     */
    protected $elementsWithTranslatedParent = [];

    /**
     * @var array
     */
    protected $elementsWithTranslations = [];

    /**
     * @var DatabaseConnection
     */
    protected $db;


    public function __construct(L10nConfiguration $l10ncfgObj, &$TCEmain_cmd, $targetLanguageUid)
    {
        $this->l10nConfiguration = $l10ncfgObj;
        $this->TCEmain_cmd = &$TCEmain_cmd;
        $this->inlineRelationTool = GeneralUtility::makeInstance(InlineRelationTool::class, $this->l10nConfiguration->l10ncfg);
        $this->targetLanguageUid = $targetLanguageUid;

        $this->db = $GLOBALS['TYPO3_DB'];
    }

    public function createParentRecordIfNecessary(RecordSignature $defaultRecordSignature)
    {
        if(! isset($this->elementsWithTranslatedParent[$defaultRecordSignature->toString()])) {
            // check if this element is a child within an inline relation
            $parentRecordSignature = $this->inlineRelationTool->checkIfRecordIsChildInInlineRelation($defaultRecordSignature);

            if (
                is_object($parentRecordSignature)
                && ! isset($this->elementsWithTranslations[$parentRecordSignature->toString()])
                && ! isset($this->TCEmain_cmd[$parentRecordSignature->table][$parentRecordSignature->uid])
                && ! $this->checkIfRecordIsTranlsatedInDatabase($parentRecordSignature)
            ) {
                // create a new default translation of the parent record
                $this->elementsWithTranslations[$parentRecordSignature->toString()] = TRUE;

                $grandParentRecords = $this->createParentRecordIfNecessary($parentRecordSignature);

                array_push(
                    $grandParentRecords,
                    $parentRecordSignature
                );

                return $grandParentRecords;
            }

            $this->elementsWithTranslatedParent[$defaultRecordSignature->toString()] = TRUE;
        }

        return [];
    }

    private function checkIfRecordIsTranlsatedInDatabase($recordSignature)
    {
        // Check if translations are stored in other table
        if (isset($GLOBALS['TCA'][$recordSignature->table]['ctrl']['transForeignTable'])) {
            $table = $GLOBALS['TCA'][$recordSignature->table]['ctrl']['transForeignTable'];
        } else {
            $table = $recordSignature->table;
        }
        if (BackendUtility::isTableLocalizable($table)) {
            $tcaCtrl = $GLOBALS['TCA'][$table]['ctrl'];

            $count = $this->db->exec_SELECTcountRows(
                'uid',
                $table,
                (
                    $tcaCtrl['transOrigPointerField'] . ' = ' . $recordSignature->uid
                    . ' AND ' . $tcaCtrl['languageField'] . '=' . $this->targetLanguageUid
                    . BackendUtility::deleteClause($table)
                )
            );

            return $count > 0;
        }

        return FALSE;
    }
}