<?php
namespace Localizationteam\L10nmgr\Model;

/***************************************************************
 *  Copyright notice
 *  (c) 2006 Kasper Skårhøj <kasperYYYY@typo3.com>
 *  All rights reserved
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/
use Localizationteam\L10nmgr\Model\Tools\InlineRelationTool;
use Localizationteam\L10nmgr\Model\Tools\ParentRecordCreator;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Configuration\FlexForm\FlexFormTools;
use TYPO3\CMS\Core\Database\DatabaseConnection;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * baseService class for offering common services like saving translation etc...
 *
 * @author     Kasper Skaarhoj <kasperYYYY@typo3.com>
 * @author     Daniel Pötzinger <development@aoemedia.de>
 * @package    TYPO3
 * @subpackage tx_l10nmgr
 */
class L10nBaseService
{

    protected static $targetLanguageID = null;
    /**
     * @var bool Translate even if empty.
     */
    protected $createTranslationAlsoIfEmpty = false;

    /**
     * @var array Extension's configuration as from the EM
     */
    protected $extensionConfiguration = array();
    /**
     * @var array
     */
    protected $TCEmain_cmd = array();
    /**
     * @var array
     */
    protected $checkedParentRecords = array();
    /**
     * @var int
     */
    protected $depthCounter = 0;

    /**
     * @var int
     */
    public $lastTCEMAINCommandsCount = 0;

    /**
     * @var L10nConfiguration
     */
    protected $l10ncfgObj;

    /**
     * @var InlineRelationTool $inlineRelationTool
     */
    protected $inlineRelationTool = NULL;

    /**
     * @var DatabaseConnection
     */
    protected $database;


    public function __construct()
    {
        // Load the extension's configuration
        $this->extensionConfiguration = unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['l10nmgr']);
        $this->database = $GLOBALS['TYPO3_DB'];
    }

    /**
     * @return integer|NULL
     */
    public static function getTargetLanguageID()
    {
        return self::$targetLanguageID;
    }

    /**
     * Save the translation
     *
     * @param L10nConfiguration $l10ncfgObj
     * @param TranslationData $translationObj
     */
    function saveTranslation(L10nConfiguration $l10ncfgObj, TranslationData $translationObj)
    {
        $this->l10ncfgObj = $l10ncfgObj;

        // Provide a hook for specific manipulations before saving
        if (is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['l10nmgr']['savePreProcess'])) {
            foreach ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['l10nmgr']['savePreProcess'] as $classReference) {
                $processingObject = GeneralUtility::getUserObj($classReference);
                $processingObject->processBeforeSaving($l10ncfgObj, $translationObj, $this);
            }
        }

        $sysLang = $translationObj->getLanguage();

        $flexFormDiffArray = $this->_submitContentAndGetFlexFormDiff($translationObj->getTranslationData(), $sysLang);

        if ($flexFormDiffArray !== false) {
            $l10ncfgObj->updateFlexFormDiff($sysLang, $flexFormDiffArray);
        }

        // Provide a hook for specific manipulations after saving
        if (is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['l10nmgr']['savePostProcess'])) {
            foreach ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['l10nmgr']['savePostProcess'] as $classReference) {
                $processingObject = GeneralUtility::getUserObj($classReference);
                $processingObject->processAfterSaving($l10ncfgObj, $translationObj, $flexFormDiffArray, $this);
            }
        }
    }

    /**
     * Submit incoming content to database. Must match what is available in $accum.
     *
     * @param array $inputArray Array with incoming translation.
     * @param int $targetLanguageUid
     *
     * @return mixed False if error - else flexFormDiffArray (if $inputArray was an array and processing was performed.)
     */
    function _submitContentAndGetFlexFormDiff($inputArray, $targetLanguageUid)
    {
        return $this->_submitContentAsTranslatedLanguageAndGetFlexFormDiff($inputArray, $targetLanguageUid);
    }

    /**
     * Submit incoming content as translated language to database. Must match what is available in $accum.
     *
     * @param array $inputArray Array with incoming translation. Must match what is found in $accum
     *
     * @return mixed False if error - else flexFormDiffArray (if $inputArray was an array and processing was performed.)
     */
    function _submitContentAsTranslatedLanguageAndGetFlexFormDiff($inputArray, $targetLanguageUid)
    {
        global $TCA;

        $parentRecordCreatorsByTargetLanguage = [];

        if (is_array($inputArray)) {
            // Initialize:
            /** @var $flexToolObj FlexFormTools */
            $flexToolObj = GeneralUtility::makeInstance(FlexFormTools::class);
            $gridElementsInstalled = ExtensionManagementUtility::isLoaded('gridelements');
            $fluxInstalled = ExtensionManagementUtility::isLoaded('flux');
            $TCEmain_data = array();
            $this->TCEmain_cmd = array();

            $_flexFormDiffArray = array();
            // Traverse:

            foreach($inputArray as $table => $elements) {
                foreach($elements as $elementUid => $fields) {
                    foreach($fields as $key => $content) {

                        list($Ttable, $TuidString, $Tfield, $Tpath) = explode(':', $key);
                        list($Tuid, $Tlang, $TdefRecord) = explode('/', $TuidString);

                        if (!$this->createTranslationAlsoIfEmpty && $inputArray[$table][$elementUid][$key] == '' && $Tuid == 'NEW') {
                            //if data is empty do not save it
                            unset($inputArray[$table][$elementUid][$key]);
                            continue;
                        }

                        if ($Tuid === 'NEW') {
                            // check if we already have a translation for this element
                            $defaultElementSignature = new RecordSignature($table, $elementUid);
                            $translationRecordSignature = $this->checkIfTranslationExists($defaultElementSignature, $targetLanguageUid);

                            if(is_object($translationRecordSignature)) {
                                // yes, update the existing record, do NOT create a new one
                                $Ttable = $translationRecordSignature->table;
                                $TuidString = $translationRecordSignature->uid;
                            } else {
                                // create a new translation
                                if(! isset($parentRecordCreatorsByTargetLanguage[$Tlang])) {
                                    $newParentRecordCreator = GeneralUtility::makeInstance(ParentRecordCreator::class,
                                        $this->l10ncfgObj,
                                        $this->TCEmain_cmd,
                                        $Tlang
                                    );
                                    $parentRecordCreatorsByTargetLanguage[$Tlang] = $newParentRecordCreator;
                                }

                                /** @var ParentRecordCreator $parentRecordCreator */
                                $parentRecordCreator = $parentRecordCreatorsByTargetLanguage[$Tlang];
                                $recordsToCreate = $parentRecordCreator->createParentRecordIfNecessary($defaultElementSignature);

                                foreach($recordsToCreate as $parentRecordSignature) {
                                    $this->TCEmain_cmd[$parentRecordSignature->table][$parentRecordSignature->uid]['localize'] = $Tlang;
                                }

                                if ($table === 'tt_content' && ($gridElementsInstalled === true || $fluxInstalled === true)) {
                                    $element = BackendUtility::getRecordRaw($table,
                                        'uid = ' . (int)$elementUid . ' AND deleted = 0');
                                    if (isset($this->TCEmain_cmd['tt_content'][$elementUid])) {
                                        unset($this->TCEmain_cmd['tt_content'][$elementUid]);
                                    }
                                    if ((int)$element['colPos'] > -1 && (int)$element['colPos'] !== 18181) {
                                        $this->TCEmain_cmd['tt_content'][$elementUid]['localize'] = $Tlang;
                                    } else {
                                        if ($element['tx_gridelements_container'] > 0) {
                                            $this->depthCounter = 0;
                                            $this->recursivelyCheckForRelationParents($element, $Tlang,
                                                'tx_gridelements_container', 'tx_gridelements_children');
                                        }
                                        if ($element['tx_flux_parent'] > 0) {
                                            $this->depthCounter = 0;
                                            $this->recursivelyCheckForRelationParents($element, $Tlang,
                                                'tx_flux_parent', 'tx_flux_children');
                                        }
                                    }
                                } elseif ($table === 'sys_file_reference') {

                                    $element = BackendUtility::getRecordRaw($table,
                                        'uid = ' . (int)$elementUid . ' AND deleted = 0');
                                    if ($element['uid_foreign'] && $element['tablenames'] && $element['fieldname']) {
                                        if ($element['tablenames'] === 'pages') {
                                            if (isset($this->TCEmain_cmd[$table][$elementUid])) {
                                                unset($this->TCEmain_cmd[$table][$elementUid]);
                                            }
                                            $this->TCEmain_cmd[$table][$elementUid]['localize'] = $Tlang;
                                        } else {
                                            $parent = BackendUtility::getRecordRaw($element['tablenames'],
                                                $TCA[$element['tablenames']]['ctrl']['transOrigPointerField'] . ' = ' . (int)$element['uid_foreign']
                                                . BackendUtility::deleteClause($element['tablenames'])
                                                . ' AND sys_language_uid = ' . (int)$Tlang);
                                            if ($parent['uid'] > 0) {
                                                if (isset($this->TCEmain_cmd[$element['tablenames']][$element['uid_foreign']])) {
                                                    unset($this->TCEmain_cmd[$element['tablenames']][$element['uid_foreign']]);
                                                }
                                                $this->TCEmain_cmd[$element['tablenames']][$element['uid_foreign']]['inlineLocalizeSynchronize'] = $element['fieldname'] . ',localize';
                                            }
                                        }
                                    }
                                } else {
                                    if (isset($this->TCEmain_cmd[$table][$elementUid])) {
                                        unset($this->TCEmain_cmd[$table][$elementUid]);
                                    }
                                    $this->TCEmain_cmd[$table][$elementUid]['localize'] = $Tlang;
                                }
                            }
                        }

                         // If FlexForm, we set value in special way:
                        if ($Tpath) {
                            if (!is_array($TCEmain_data[$Ttable][$TuidString][$Tfield])) {
                                $TCEmain_data[$Ttable][$TuidString][$Tfield] = array();
                            }
                            //TCEMAINDATA is passed as reference here:
                            $flexToolObj->setArrayValueByPath($Tpath,
                                $TCEmain_data[$Ttable][$TuidString][$Tfield],
                                $inputArray[$table][$elementUid][$key]);
                            $_flexFormDiffArray[$key] = array(
                                'translated' => $inputArray[$table][$elementUid][$key],
                                'default' => ''
                            );
                        } else {
                            $TCEmain_data[$Ttable][$TuidString][$Tfield] = $content;
                        }
                    }
                }
            }

            self::$targetLanguageID = $Tlang;

            // Execute CMD array: Localizing records:
            /** @var $tce DataHandler */
            $tce = GeneralUtility::makeInstance(DataHandler::class);
            if ($this->extensionConfiguration['enable_neverHideAtCopy'] == 1) {
                $tce->neverHideAtCopy = true;
            }
            $tce->stripslashes_values = false;
            $tce->isImporting = true;
            if (count($this->TCEmain_cmd)) {
                $tce->start(array(), $this->TCEmain_cmd);
                $tce->process_cmdmap();
                if (count($tce->errorLog)) {
                    debug($tce->errorLog, 'TCEmain localization errors:');
                }
            }

            // Before remapping
            if (TYPO3_DLOG) {
                GeneralUtility::sysLog(__FILE__ . ': ' . __LINE__ . ': TCEmain_data before remapping: ' . GeneralUtility::arrayToLogString($TCEmain_data),
                    'l10nmgr');
            }
            // Remapping those elements which are new:
            $this->lastTCEMAINCommandsCount = 0;
            foreach ($TCEmain_data as $table => $items) {
                foreach ($TCEmain_data[$table] as $TuidString => $fields) {
                    if ($table === 'sys_file_reference' && $fields['tablenames'] === 'pages') {
                        $parent = BackendUtility::getRecordRaw('pages_language_overlay',
                            'pid = ' . (int)$fields['uid_foreign'] . '
			                AND deleted = 0 AND sys_language_uid = ' . (int)$Tlang);
                        if ($parent['uid']) {
                            $fields['tablenames'] = 'pages_language_overlay';
                            $fields['uid_foreign'] = $parent['uid'];
                        }
                    }
                    list($Tuid, $Tlang, $TdefRecord) = explode('/', $TuidString);
                    $this->lastTCEMAINCommandsCount++;
                    if ($Tuid === 'NEW') {
                        if ($tce->copyMappingArray_merged[$table][$TdefRecord]) {
                            $TCEmain_data[$table][BackendUtility::wsMapId($table,
                                $tce->copyMappingArray_merged[$table][$TdefRecord])] = $fields;
                        } else {
                            GeneralUtility::sysLog(__FILE__ . ': ' . __LINE__ . ': Record "' . $table . ':' . $TdefRecord . '" was NOT localized as it should have been!',
                                'l10nmgr');
                        }
                        unset($TCEmain_data[$table][$TuidString]);
                    }
                }
            }
            // After remapping
            if (TYPO3_DLOG) {
                GeneralUtility::sysLog(__FILE__ . ': ' . __LINE__ . ': TCEmain_data after remapping: ' . GeneralUtility::arrayToLogString($TCEmain_data),
                    'l10nmgr');
            }

            // Now, submitting translation data:
            /** @var $tce DataHandler */
            $tce = GeneralUtility::makeInstance(DataHandler::class);
            if ($this->extensionConfiguration['enable_neverHideAtCopy'] == 1) {
                $tce->neverHideAtCopy = true;
            }
            $tce->stripslashes_values = false;
            $tce->dontProcessTransformations = true;
            $tce->isImporting = true;
            //print_r($TCEmain_data);
            $tce->start($TCEmain_data, array()); // check has been done previously that there is a backend user which is Admin and also in live workspace
            $tce->process_datamap();

            self::$targetLanguageID = null;

            if (count($tce->errorLog)) {
                GeneralUtility::sysLog(__FILE__ . ': ' . __LINE__ . ': TCEmain update errors: ' . GeneralUtility::arrayToLogString($tce->errorLog),
                    'l10nmgr');
            }

            if (count($tce->autoVersionIdMap) && count($_flexFormDiffArray)) {
                if (TYPO3_DLOG) {
                    GeneralUtility::sysLog(__FILE__ . ': ' . __LINE__ . ': flexFormDiffArry: ' . GeneralUtility::arrayToLogString($this->flexFormDiffArray),
                        'l10nmgr');
                }
                foreach ($_flexFormDiffArray as $key => $value) {
                    list($Ttable, $Tuid, $Trest) = explode(':', $key, 3);
                    if ($tce->autoVersionIdMap[$Ttable][$Tuid]) {
                        $_flexFormDiffArray[$Ttable . ':' . $tce->autoVersionIdMap[$Ttable][$Tuid] . ':' . $Trest] = $_flexFormDiffArray[$key];
                        unset($_flexFormDiffArray[$key]);
                    }
                }
                if (TYPO3_DLOG) {
                    GeneralUtility::sysLog(__FILE__ . ': ' . __LINE__ . ': autoVersionIdMap: ' . $tce->autoVersionIdMap,
                        'l10nmgr');
                    GeneralUtility::sysLog(__FILE__ . ': ' . __LINE__ . ': _flexFormDiffArray: ' . GeneralUtility::arrayToLogString($_flexFormDiffArray),
                        'l10nmgr');
                }
            }

            return $_flexFormDiffArray;
        } else {
            return false;
        }
    }

    /**
     * @param $element
     * @param $Tlang
     * @param $parentField
     * @param $childrenField
     */
    function recursivelyCheckForRelationParents($element, $Tlang, $parentField, $childrenField)
    {
        global $TCA;
        $this->depthCounter++;
        if ($this->depthCounter < 100 && !isset($this->checkedParentRecords[$parentField][$element['uid']])) {
            $this->checkedParentRecords[$parentField][$element['uid']] = true;
            $translatedParent = BackendUtility::getRecordRaw('tt_content',
                $TCA['tt_content']['ctrl']['transOrigPointerField'] . ' = ' . (int)$element[$parentField] . '
	            AND deleted = 0 AND sys_language_uid = ' . (int)$Tlang);
            if ($translatedParent['uid'] > 0) {
                $this->TCEmain_cmd['tt_content'][$translatedParent['uid']]['inlineLocalizeSynchronize'] = $childrenField . ',localize';
            } else {
                if ($element[$parentField] > 0) {
                    $parent = BackendUtility::getRecordRaw('tt_content',
                        'uid = ' . (int)$element[$parentField] . ' AND deleted = 0');
                    $this->recursivelyCheckForRelationParents($parent, $Tlang, $parentField, $childrenField);
                } else {
                    $this->TCEmain_cmd['tt_content'][$element['uid']]['localize'] = $Tlang;
                }
            }
        }
    }

    private function checkIfTranslationExists(RecordSignature $recordSignature, $targetLanguageUid)
    {
        $table = isset($GLOBALS['TCA'][$recordSignature->table]['ctrl']['transForeignTable']) ? $GLOBALS['TCA'][$recordSignature->table]['ctrl']['transForeignTable'] : $recordSignature->table;

        if (BackendUtility::isTableLocalizable($table)) {
            $tcaCtrl = $GLOBALS['TCA'][$table]['ctrl'];

            $translationRow = $this->database->exec_SELECTgetSingleRow(
                'uid',
                $table,
                (
                    $tcaCtrl['transOrigPointerField'] . ' = ' . $recordSignature->uid
                    . ' AND ' . $tcaCtrl['languageField'] . '=' . $targetLanguageUid
                    . BackendUtility::deleteClause($table)
                )
            );

            if(is_array($translationRow)) {
                return new RecordSignature($table, $translationRow['uid']);
            }
        }

        return FALSE;
    }
}